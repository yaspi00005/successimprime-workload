<?php

namespace App\Tests\Unit\Service\Installation;

use App\Service\Installation\VerificationInstallation as V;
use PHPUnit\Framework\TestCase;

final class VerificationInstallationTest extends TestCase
{
    private const PHP = ['version' => '8.4.26', 'extensions' => [...V::EXTENSIONS_REQUISES, 'intl', 'zip', 'curl', 'Zend OPcache'], 'webp' => true, 'jpeg' => true];

    /** @return array<string, string> */
    private static function enLigne(): array
    {
        return [
            'APP_ENV' => 'prod', 'APP_SECRET' => str_repeat('s3', 20), 'DEFAULT_URI' => 'https://succesimprim.com', 'MAILER_DSN' => 'smtp://relais',
            'SYNC_CLE' => str_repeat('k8', 32), 'ORANGE_SMS_CLIENT_ID' => 'id-sms-7Qx', 'ORANGE_SMS_CLIENT_SECRET' => 'secret-sms-9Zk',
            'ORANGE_MONEY_CLIENT_ID' => 'a', 'ORANGE_MONEY_CLIENT_SECRET' => 'b', 'ORANGE_MONEY_MERCHANT_KEY' => 'c', 'VAPID_PUBLIC_KEY' => 'BPub-5Rt', 'VAPID_PRIVATE_KEY' => 'priv-vapid-3Wm',
            'ANTHROPIC_API_KEY' => 'sk-ant-essai-4Hv',
        ];
    }

    /** @param list<array{statut: string, point: string, detail: string}> $resultats */
    private static function statut(array $resultats, string $point): ?string
    {
        foreach ($resultats as $r) {
            if ($r['point'] === $point) {
                return $r['statut'];
            }
        }

        return null;
    }

    public function testSiteEnLigneComplet(): void
    {
        $resultats = V::verifier(self::enLigne(), self::PHP, true);

        self::assertFalse(V::aDesErreurs($resultats));
        self::assertSame([V::OK], array_values(array_unique(array_column($resultats, 'statut'))));
    }

    public function testAucunSecretNEstAffiche(): void
    {
        $env = self::enLigne();
        $texte = json_encode(V::verifier($env, self::PHP, true));

        foreach (['APP_SECRET', 'SYNC_CLE', 'ORANGE_SMS_CLIENT_SECRET', 'VAPID_PRIVATE_KEY', 'ANTHROPIC_API_KEY'] as $cle) {
            self::assertStringNotContainsString($env[$cle], (string) $texte, $cle);
        }
    }

    public function testLaCleHorsSiteNeDoitPasEtreEnLigne(): void
    {
        $resultats = V::verifier(['SAUVEGARDE_CLE_HORS_SITE' => 'cle'] + self::enLigne(), self::PHP, true);

        self::assertSame(V::ERREUR, self::statut($resultats, 'SAUVEGARDE_CLE_HORS_SITE'));
    }

    public function testClesIncompletesEtAbsentes(): void
    {
        $env = self::enLigne();
        unset($env['ORANGE_MONEY_MERCHANT_KEY'], $env['VAPID_PUBLIC_KEY'], $env['VAPID_PRIVATE_KEY']);
        $resultats = V::verifier($env, self::PHP, true);

        // Une clé oubliée en copiant est une erreur ; aucune clé désactive seulement la fonction.
        self::assertSame(V::ERREUR, self::statut($resultats, 'Orange Money'));
        self::assertSame(V::A_VOIR, self::statut($resultats, 'Notifications (VAPID)'));
    }

    public function testCleDeLAssistant(): void
    {
        $env = self::enLigne();
        unset($env['ANTHROPIC_API_KEY']);
        self::assertSame(V::A_VOIR, self::statut(V::verifier($env, self::PHP, true), 'Assistant du site (ANTHROPIC_API_KEY)'));
        self::assertSame(V::ERREUR, self::statut(V::verifier(['ANTHROPIC_API_KEY' => 'abc'] + $env, self::PHP, true), 'Assistant du site (ANTHROPIC_API_KEY)'));

        // Les réponses de démonstration ne vont jamais en ligne ; la clé ne reste pas sur l'ERP local.
        self::assertSame(V::ERREUR, self::statut(V::verifier(['ASSISTANT_DEMO' => '1'] + self::enLigne(), self::PHP, true), 'ASSISTANT_DEMO'));
        self::assertNull(self::statut(V::verifier(['ASSISTANT_DEMO' => '0'] + self::enLigne(), self::PHP, true), 'ASSISTANT_DEMO'));
        self::assertSame(V::A_VOIR, self::statut(V::verifier(['APP_ENV' => 'prod', 'APP_SECRET' => str_repeat('s', 40), 'ANTHROPIC_API_KEY' => 'sk-ant-x'], self::PHP, false), 'ANTHROPIC_API_KEY'));
    }

    public function testEnLigneHttpsEtProdObligatoires(): void
    {
        $resultats = V::verifier(['DEFAULT_URI' => 'http://succesimprim.com', 'APP_ENV' => 'dev'] + self::enLigne(), self::PHP, true);

        self::assertSame(V::ERREUR, self::statut($resultats, 'DEFAULT_URI'));
        self::assertSame(V::ERREUR, self::statut($resultats, 'APP_ENV'));
    }

    public function testErpLocal(): void
    {
        $php = ['extensions' => array_diff(self::PHP['extensions'], ['zip']), 'webp' => false] + self::PHP;
        $resultats = V::verifier(['APP_ENV' => 'prod', 'APP_SECRET' => str_repeat('s', 40), 'SYNC_URL' => 'http://site', 'SYNC_CLE' => str_repeat('k', 64)], $php, false);

        self::assertSame(V::ERREUR, self::statut($resultats, 'Synchronisation'));
        self::assertSame(V::A_VOIR, self::statut($resultats, 'Extension zip'));
        self::assertSame(V::A_VOIR, self::statut($resultats, 'Images (GD)'));
        self::assertSame(V::A_VOIR, self::statut($resultats, 'Sauvegarde hors site'));
        self::assertNull(self::statut($resultats, 'Orange Money'));
    }

    public function testPhpTropAncienEtExtensionManquante(): void
    {
        $resultats = V::verifier(self::enLigne(), ['version' => '8.2.0', 'extensions' => ['ctype']] + self::PHP, true);

        self::assertSame(V::ERREUR, self::statut($resultats, 'PHP'));
        self::assertSame(V::ERREUR, self::statut($resultats, 'Extensions PHP requises'));
    }
}
