<?php

namespace App\Tests\Unit\Service\Conception;

use App\Service\Conception\Generateur\GenerateurChoisi;
use App\Service\Conception\Generateur\GenerateurDemo;
use App\Service\Conception\Generateur\GenerateurOpenAi;
use App\Service\Conception\Generateur\ImageGeneree;
use App\Service\Conception\PromptConception;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Générateurs d'images : OpenAI (production) appelé sans réseau grâce à
 * un faux client HTTP, démonstration dessinée avec GD.
 */
final class GenerateursTest extends TestCase
{
    public function testOpenAiDemandeUneImageEnBase64(): void
    {
        $appels = [];
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use (&$appels): MockResponse {
            $appels[] = ['methode' => $methode, 'url' => $url, 'entetes' => $options['headers'], 'corps' => json_decode((string) $options['body'], true)];

            return new MockResponse((string) json_encode(['data' => [['b64_json' => base64_encode(self::png(96, 144))]]]));
        });
        $generateur = new GenerateurOpenAi($http, 'sk-test-secret');

        $image = $generateur->generer('A poster', PromptConception::PORTRAIT);

        self::assertSame('POST', $appels[0]['methode']);
        self::assertSame('https://api.openai.com/v1/images/generations', $appels[0]['url']);
        self::assertContains('Authorization: Bearer sk-test-secret', $appels[0]['entetes']);
        self::assertSame(['model' => 'gpt-image-1', 'prompt' => 'A poster', 'n' => 1, 'size' => '1024x1536'], $appels[0]['corps']);
        self::assertSame('image/png', $image->type);
        self::assertSame([96, 144], [$image->largeur, $image->hauteur]);
        self::assertSame('png', $image->extension());
    }

    public function testTailleSelonLeModele(): void
    {
        $http = new MockHttpClient();
        self::assertSame('1536x1024', (new GenerateurOpenAi($http, 'k'))->requete('x', PromptConception::PAYSAGE)['size']);
        $dalle3 = (new GenerateurOpenAi($http, 'k', 'dall-e-3'))->requete('x', PromptConception::PORTRAIT);
        self::assertSame(['1024x1792', 'b64_json'], [$dalle3['size'], $dalle3['response_format']]);
        $dalle2 = (new GenerateurOpenAi($http, 'k', 'dall-e-2'))->requete(str_repeat('a', 1500), PromptConception::PORTRAIT);
        self::assertSame('1024x1024', $dalle2['size']);
        self::assertSame(1000, mb_strlen($dalle2['prompt']));
    }

    public function testErreurDuFournisseurSansLaCle(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode(['error' => ['message' => 'Incorrect API key provided: sk-test-secret']]), ['http_code' => 401]));

        try {
            (new GenerateurOpenAi($http, 'sk-test-secret'))->generer('x', PromptConception::CARRE);
            self::fail('Erreur attendue');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('erreur 401', $e->getMessage());
            self::assertStringNotContainsString('sk-test-secret', $e->getMessage());
        }
    }

    public function testReponseQuiNEstPasUneImageRefusee(): void
    {
        $http = new MockHttpClient(new MockResponse((string) json_encode(['data' => [['b64_json' => base64_encode('<svg onload="alert(1)"></svg>')]]])));

        $this->expectException(\RuntimeException::class);
        (new GenerateurOpenAi($http, 'k'))->generer('x', PromptConception::CARRE);
    }

    public function testSansCleOuEnHttpIndisponible(): void
    {
        self::assertFalse((new GenerateurOpenAi(new MockHttpClient(), ''))->estDisponible());
        self::assertFalse((new GenerateurOpenAi(new MockHttpClient(), 'k', 'gpt-image-1', 'http://api.exemple.test/v1'))->estDisponible());
        self::assertTrue((new GenerateurOpenAi(new MockHttpClient(), 'k'))->estDisponible());
    }

    public function testDemonstrationDessineUneAfficheSansReseauEtRefuseeEnProduction(): void
    {
        $demo = new GenerateurDemo(sys_get_temp_dir().'/sans-polices');
        $image = $demo->generer('prompt', PromptConception::PORTRAIT, ['titre' => 'Soirée jazz', 'sousTitre' => 'au bord du fleuve', 'couleur1' => '#13285C', 'couleur2' => '#F2C230']);

        self::assertSame('image/png', $image->type);
        self::assertSame([768, 1152], [$image->largeur, $image->hauteur]);
        self::assertFalse((new GenerateurDemo('', true))->estDisponible());
    }

    public function testChoixDuFournisseurParLEnvironnement(): void
    {
        $http = new MockHttpClient();
        self::assertSame('openai', (new GenerateurChoisi($http, 'openai', 'k', '', 'https://api.openai.com/v1', '/tmp', 'dev'))->nom());
        self::assertSame('demo', (new GenerateurChoisi($http, 'demo', '', '', '', '/tmp', 'dev'))->nom());
        // En production, la démonstration n'est jamais proposée aux clients.
        self::assertFalse((new GenerateurChoisi($http, 'demo', '', '', '', '/tmp', 'prod'))->estDisponible());
        self::assertFalse((new GenerateurChoisi($http, 'openai', '', '', '', '/tmp', 'prod'))->estDisponible());
    }

    public function testImageTropPetiteRefusee(): void
    {
        $this->expectException(\RuntimeException::class);
        ImageGeneree::depuis(self::png(20, 20));
    }

    private static function png(int $l, int $h): string
    {
        $image = imagecreatetruecolor($l, $h);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
