<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\CompteClient;
use App\Service\EspaceClient\AppareilsClient;
use PHPUnit\Framework\TestCase;

final class AppareilsClientTest extends TestCase
{
    public function testLibelleDeLAppareil(): void
    {
        self::assertSame('iPhone', AppareilsClient::libelle('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'));
        self::assertSame('Android', AppareilsClient::libelle('Mozilla/5.0 (Linux; Android 14; SM-A145F) AppleWebKit/537.36 Chrome/128.0 Mobile Safari/537.36'));
        self::assertSame('Tablette Android', AppareilsClient::libelle('Mozilla/5.0 (Linux; Android 13; SM-X200) AppleWebKit/537.36 Chrome/128.0 Safari/537.36'));
        self::assertSame('PC Windows', AppareilsClient::libelle('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/128.0'));
        self::assertSame('Mac', AppareilsClient::libelle('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15'));
        self::assertSame('Navigateur', AppareilsClient::libelle(''));
    }

    public function testDeconnecterLesAutresAppareilsFermeLesSessionsPrecedentes(): void
    {
        $compte = new CompteClient();
        self::assertSame(1, $compte->getVersionSession());
        self::assertFalse(AppareilsClient::sessionPerimee($compte, 1));

        // « Déconnecter les autres appareils » depuis ce téléphone : il prend la nouvelle version.
        $nouvelle = $compte->nouvelleVersionSession();

        self::assertSame(2, $nouvelle);
        self::assertTrue(AppareilsClient::sessionPerimee($compte, 1), 'l\'autre appareil est déconnecté à sa prochaine page');
        self::assertFalse(AppareilsClient::sessionPerimee($compte, $nouvelle), 'celui-ci reste connecté');
    }

    public function testAppareilsRegroupesCeluiCiEnPremier(): void
    {
        $liste = [
            ['libelle' => 'PC Windows', 'courant' => false],
            ['libelle' => 'Android', 'courant' => false],
            ['libelle' => 'PC Windows', 'courant' => false],
            ['libelle' => 'iPhone', 'courant' => true],
        ];

        self::assertSame([
            ['libelle' => 'iPhone', 'courant' => true, 'nombre' => 1],
            ['libelle' => 'PC Windows', 'courant' => false, 'nombre' => 2],
            ['libelle' => 'Android', 'courant' => false, 'nombre' => 1],
        ], AppareilsClient::regrouper($liste));
    }

    public function testEmpreinteSeulementGardee(): void
    {
        $jeton = str_repeat('ab', 32);

        self::assertSame(hash('sha256', $jeton), AppareilsClient::empreinte($jeton));
        self::assertNotSame($jeton, AppareilsClient::empreinte($jeton));
    }
}
