<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Service\Messagerie\SoldeOrange;
use App\Service\OrangeSmsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Solde SMS lu chez Orange : jamais l'API réelle (client HTTP simulé),
 * lecture gardée une heure, « Actualiser » au plus une fois par minute.
 */
final class SoldeOrangeTest extends TestCase
{
    private const CONTRATS = '[{"id":"c1","country":"MLI","offerName":"SMS_OCB","availableUnits":1180,"status":"ACTIVE","expirationDate":"2026-11-30T10:00:00.000Z"},'
        .'{"id":"c2","country":"SEN","offerName":"SMS_OCB","availableUnits":40,"status":"ACTIVE","expirationDate":"2026-10-20T10:00:00.000Z"}]';

    private const ACHATS = '[{"id":"a1","country":"MLI","bundleDescription":"Pack 1000 SMS","price":15000,"currency":"XOF","purchaseDate":"2026-09-01T08:00:00.000Z","oldAvailableUnits":180,"newAvailableUnits":1180,"newExpirationDate":"2026-11-30T10:00:00.000Z"},'
        .'{"id":"a2","country":"MLI","bundleDescription":"Pack 500 SMS","price":8000,"currency":"XOF","purchaseDate":"2026-10-05T08:00:00.000Z","oldAvailableUnits":0,"newAvailableUnits":500,"newExpirationDate":"2026-12-05T10:00:00.000Z"},'
        .'{"id":"a3","country":"SEN","bundleDescription":"Autre pays","price":1,"currency":"XOF","purchaseDate":"2026-10-06T08:00:00.000Z","oldAvailableUnits":0,"newAvailableUnits":1}]';

    public function testContratsDuMaliSeulement(): void
    {
        $contrats = SoldeOrange::lireContrats(json_decode(self::CONTRATS, true));

        self::assertCount(1, $contrats);
        self::assertSame('MLI', $contrats[0]['pays']);
        self::assertSame(1180, $contrats[0]['unites']);
        self::assertSame('2026-11-30', $contrats[0]['expiration']?->format('Y-m-d'));
        self::assertTrue($contrats[0]['actif']);

        // Aucun contrat du Mali : tous sont gardés plutôt que rien.
        self::assertCount(1, SoldeOrange::lireContrats([['country' => 'SEN', 'availableUnits' => 3]]));
    }

    public function testAncienFormatDesContrats(): void
    {
        $json = ['partnerContracts' => ['contracts' => [['service' => 'SMS_OCB', 'serviceContracts' => [
            ['country' => 'MLI', 'service' => 'SMS_OCB', 'availableUnits' => 75, 'expires' => '2026-10-25T00:00:00Z'],
        ]]]]];
        $contrats = SoldeOrange::lireContrats($json);

        self::assertSame(75, $contrats[0]['unites']);
        self::assertSame('SMS_OCB', $contrats[0]['offre']);
        self::assertSame('2026-10-25', $contrats[0]['expiration']?->format('Y-m-d'));
    }

    public function testResumeDesContrats(): void
    {
        $fin1 = new \DateTimeImmutable('2026-11-30');
        $fin2 = new \DateTimeImmutable('2026-10-31');
        $resume = SoldeOrange::resumerContrats([
            ['unites' => 100, 'expiration' => $fin1, 'actif' => true],
            ['unites' => 20, 'expiration' => $fin2, 'actif' => true],
            ['unites' => 999, 'expiration' => new \DateTimeImmutable('2026-01-01'), 'actif' => false],
        ]);
        self::assertSame(120, $resume['unites']);
        self::assertEquals($fin2, $resume['expiration'], 'la fin la plus proche');

        $expire = SoldeOrange::resumerContrats([['unites' => 50, 'expiration' => $fin2, 'actif' => false]]);
        self::assertSame(0, $expire['unites'], 'pack expiré : plus rien à envoyer');
        self::assertSame(['unites' => null, 'expiration' => null], SoldeOrange::resumerContrats([]));
    }

    public function testAchatsDuPlusRecentAuPlusAncien(): void
    {
        $achats = SoldeOrange::lireAchats(json_decode(self::ACHATS, true));

        self::assertCount(2, $achats, 'les achats d\'un autre pays sont écartés');
        self::assertSame('Pack 500 SMS', $achats[0]['pack']);
        self::assertSame(500, $achats[0]['unites']);
        self::assertSame(1000, $achats[1]['unites']);
        self::assertSame(15000.0, $achats[1]['prix']);
        self::assertSame('XOF', $achats[1]['devise']);
        self::assertSame([], SoldeOrange::lireAchats(['purchaseOrders' => []]));
    }

    public function testLectureGardeeUneHeureEtActualiserLimite(): void
    {
        $appels = [];
        $solde = $this->solde($appels, fn (string $url): MockResponse => match (true) {
            str_ends_with($url, '/contracts') => new MockResponse(self::CONTRATS),
            default => new MockResponse(self::ACHATS),
        });
        $t0 = new \DateTimeImmutable('2026-10-18 09:00:00');

        $lu = $solde->lire($t0);
        self::assertNull($lu['erreur']);
        self::assertSame(1180, $lu['contrats'][0]['unites']);
        self::assertCount(2, $lu['achats']);
        self::assertSame(['token', 'contracts', 'purchaseorders'], $appels);

        // Pages suivantes : rien n'est redemandé à Orange.
        $solde->lire($t0->modify('+10 minutes'));
        // « Actualiser » moins d'une minute après la dernière lecture : rien non plus.
        $solde->lire($t0->modify('+30 seconds'), true);
        self::assertCount(3, $appels);

        // « Actualiser » plus tard : nouvelle lecture (le jeton est réutilisé).
        $solde->lire($t0->modify('+5 minutes'), true);
        self::assertSame(['token', 'contracts', 'purchaseorders', 'contracts', 'purchaseorders'], $appels);
    }

    public function testUneLectureRateeGardeLesDerniersChiffres(): void
    {
        $panne = false;
        $appels = [];
        $solde = $this->solde($appels, function (string $url) use (&$panne): MockResponse {
            if ($panne) {
                return new MockResponse('{"code":42,"message":"Expired credentials","description":"Jeton refusé pour tel:+22376000099"}', ['http_code' => 403]);
            }

            return new MockResponse(str_ends_with($url, '/contracts') ? self::CONTRATS : '[]');
        });
        $t0 = new \DateTimeImmutable('2026-10-18 09:00:00');
        $solde->lire($t0);

        $panne = true;
        $lu = $solde->lire($t0->modify('+2 minutes'), true);
        self::assertNotNull($lu['erreur']);
        self::assertStringNotContainsString('76000099', (string) $lu['erreur']);
        self::assertSame(1180, $lu['contrats'][0]['unites'], 'derniers chiffres gardés');
        self::assertEquals($t0, $lu['luLe'], 'avec l\'heure de la dernière lecture réussie');
    }

    public function testJetonRefuseRedemandeUneFois(): void
    {
        $appels = [];
        $jetons = 0;
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$appels, &$jetons): MockResponse {
            if (str_ends_with($url, '/oauth/v3/token')) {
                ++$jetons;

                return new MockResponse('{"access_token":"jeton'.$jetons.'","expires_in":3600}');
            }
            $entete = implode(' ', $options['normalized_headers']['authorization'] ?? []);
            $appels[] = $entete;

            return str_contains($entete, 'jeton1') ? new MockResponse('{}', ['http_code' => 401]) : new MockResponse(self::CONTRATS);
        });
        $sms = new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), 'id', 'secret', 'tel:+2230000', '');

        self::assertSame(1180, $sms->lireContrats()[0]['availableUnits']);
        self::assertSame(2, $jetons);
        self::assertCount(2, $appels);
    }

    public function testSansClesRienNEstDemande(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('aucun appel à Orange sans les clés');
        });
        $sms = new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), '', '', '', '');

        $this->expectException(\RuntimeException::class);
        $sms->lireContrats();
    }

    /**
     * @param list<string>                  $appels
     * @param callable(string): MockResponse $reponse
     */
    private function solde(array &$appels, callable $reponse): SoldeOrange
    {
        $client = new MockHttpClient(static function (string $methode, string $url) use (&$appels, $reponse): MockResponse {
            if (str_ends_with($url, '/oauth/v3/token')) {
                $appels[] = 'token';

                return new MockResponse('{"access_token":"jeton","expires_in":3600}');
            }
            $appels[] = basename(parse_url($url, \PHP_URL_PATH) ?: '');

            return $reponse($url);
        });
        $cache = new ArrayAdapter();

        return new SoldeOrange(new OrangeSmsService($client, $cache, new NullLogger(), 'id', 'secret', 'tel:+2230000', ''), $cache);
    }
}
