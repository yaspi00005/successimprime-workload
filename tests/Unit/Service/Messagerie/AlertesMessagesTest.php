<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Service\Messagerie\AlertesMessages;
use App\Service\Messagerie\CreditsMessages;
use PHPUnit\Framework\TestCase;

/** Alertes des crédits : seuil bas, fin proche ; une seule fois par situation, sans montant. */
final class AlertesMessagesTest extends TestCase
{
    public function testConditionsDAlerte(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-18 09:00:00');
        $sms = ['unites' => 80, 'expiration' => new \DateTimeImmutable('2026-10-30 23:59:59')];
        $email = ['expiration' => new \DateTimeImmutable('2026-12-31 23:59:59')];

        $alertes = CreditsMessages::alertes($sms, $email, 100, 15, $maintenant);

        self::assertSame(['sms_seuil', 'sms_expiration'], array_keys($alertes));
        self::assertSame('sous_100', $alertes['sms_seuil']['valeur']);
        self::assertSame('Crédits SMS : il reste 80 SMS, moins que le seuil de 100.', $alertes['sms_seuil']['message']);
        self::assertSame('2026-10-30', $alertes['sms_expiration']['valeur']);
        self::assertSame('Crédits SMS : le pack expire le 30/10/2026.', $alertes['sms_expiration']['message']);

        foreach ($alertes as $alerte) {
            self::assertDoesNotMatchRegularExpression('/\d\s?(F|FCFA|XOF)\b/u', $alerte['message'], 'jamais de montant');
        }
    }

    public function testRienQuandToutVaBien(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-18 09:00:00');

        self::assertSame([], CreditsMessages::alertes(['unites' => 100, 'expiration' => new \DateTimeImmutable('2026-11-03')], ['expiration' => null], 100, 15, $maintenant));
        self::assertSame([], CreditsMessages::alertes(['unites' => null, 'expiration' => null], ['expiration' => null], 100, 15, $maintenant), 'solde inconnu : pas d\'alerte');
    }

    public function testAbonnementEmailExpire(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-18 09:00:00');
        $alertes = CreditsMessages::alertes(['unites' => null, 'expiration' => null], ['expiration' => new \DateTimeImmutable('2026-10-10 23:59:59')], 100, 15, $maintenant);

        self::assertSame('Messagerie e-mail : l\'abonnement a expiré le 10/10/2026.', $alertes['email_expiration']['message']);
    }

    public function testUneSeuleFoisParSituation(): void
    {
        $alertes = [
            'sms_seuil' => ['valeur' => 'sous_100', 'message' => 'seuil'],
            'sms_expiration' => ['valeur' => '2026-10-30', 'message' => 'fin'],
        ];

        [$messages, $garde] = AlertesMessages::aSignaler($alertes, []);
        self::assertSame(['seuil', 'fin'], $messages);
        self::assertSame(['sms_seuil' => 'sous_100', 'sms_expiration' => '2026-10-30'], $garde);

        // L'heure suivante, même situation : rien.
        self::assertSame([], AlertesMessages::aSignaler($alertes, $garde)[0]);

        // Nouveau pack avec une autre date de fin : signalé de nouveau.
        $alertes['sms_expiration'] = ['valeur' => '2026-12-05', 'message' => 'nouvelle fin'];
        self::assertSame(['nouvelle fin'], AlertesMessages::aSignaler($alertes, $garde)[0]);

        // Le solde remonte puis redescend : la situation a cessé, elle sera de nouveau signalée.
        [, $garde] = AlertesMessages::aSignaler(['sms_expiration' => $alertes['sms_expiration']], $garde);
        self::assertArrayNotHasKey('sms_seuil', $garde);
        self::assertSame(['seuil'], AlertesMessages::aSignaler(['sms_seuil' => ['valeur' => 'sous_100', 'message' => 'seuil']], $garde)[0]);
    }

    public function testCeQuIlResteDesLimitesEmail(): void
    {
        self::assertSame(
            ['heure' => 40, 'jour' => 0, 'mois' => null],
            CreditsMessages::restants(['heure' => 50, 'jour' => 300, 'mois' => 0], ['heure' => 10, 'jour' => 320, 'mois' => 900]),
        );
    }
}
