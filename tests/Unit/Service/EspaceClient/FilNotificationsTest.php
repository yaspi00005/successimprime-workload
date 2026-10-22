<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\NotificationsClient;
use PHPUnit\Framework\TestCase;

final class FilNotificationsTest extends TestCase
{
    private const MAINTENANT = '2026-10-14 10:00';

    public function testChacunVoitSesCategoriesEtSesMontants(): void
    {
        $client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $fil = self::fil();

        $payeur = FilNotifications::presenter($fil, self::compte($client, [CompteClient::DROIT_PAYEUR]), [], self::maintenant());
        $valideur = FilNotifications::presenter($fil, self::compte($client, [CompteClient::DROIT_VALIDEUR]), [], self::maintenant());
        $demandeur = FilNotifications::presenter($fil, self::compte($client, []), [], self::maintenant());
        $lecture = FilNotifications::presenter($fil, self::compte($client, [CompteClient::DROIT_LECTURE]), [], self::maintenant());

        self::assertSame(['bat', 'factures', 'devis', 'livraison'], array_column($payeur, 'categorie'));
        self::assertSame([null, 45000, 120000, null], array_column($payeur, 'montant'));
        // Pas de facture pour qui ne paie pas ; le devis avec son montant pour qui l'accepte.
        self::assertSame(['bat', 'devis', 'livraison'], array_column($valideur, 'categorie'));
        self::assertSame([null, 120000, null], array_column($valideur, 'montant'));
        self::assertSame(['bat', 'devis', 'livraison'], array_column($demandeur, 'categorie'));
        self::assertSame([null, null, null], array_column($demandeur, 'montant'));
        // Lecture · suivi : production et livraison seulement, sans montant.
        self::assertSame(['livraison'], array_column($lecture, 'categorie'));
    }

    public function testEtatLuPropreAChaquePersonne(): void
    {
        $client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $fil = self::fil();
        $cleBat = NotificationsClient::cle($fil[0]);

        $awa = FilNotifications::presenter($fil, self::compte($client, [CompteClient::DROIT_ADMINISTRATEUR]), [$cleBat => true], self::maintenant());
        $ibrahim = FilNotifications::presenter($fil, self::compte($client, [CompteClient::DROIT_ADMINISTRATEUR]), [], self::maintenant());

        self::assertFalse($awa[0]['nouveau'], 'lue par Awa');
        self::assertTrue($ibrahim[0]['nouveau'], 'toujours nouvelle pour Ibrahim');
        self::assertSame(3, FilNotifications::compter($awa)['toutes']);
        self::assertSame(4, FilNotifications::compter($ibrahim)['toutes']);
        self::assertSame(1, FilNotifications::compter($ibrahim)['bat']);
        self::assertSame(0, FilNotifications::compter($awa)['bat']);
    }

    public function testToutMarquerCommeLuEtVieuxEvenements(): void
    {
        $client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $compte = self::compte($client, [CompteClient::DROIT_ADMINISTRATEUR])->setNotificationsLuesJusqua(new \DateTimeImmutable('2026-10-14 09:00'));
        $fil = self::fil();
        $fil[] = self::evenement('livree', '2026-09-01 10:00', 'CMD-0');

        $vu = FilNotifications::presenter($fil, $compte, [], self::maintenant());

        // Ce qui est arrivé après « Tout marquer comme lu » reste nouveau ; plus de 14 jours : plus nouveau.
        self::assertSame([true, false, false, false, false], array_column($vu, 'nouveau'));
    }

    public function testRegroupementParJour(): void
    {
        $groupes = FilNotifications::parJour(FilNotifications::presenter(self::fil(), self::compte(new Clients(), []), [], self::maintenant()), self::maintenant());

        self::assertSame(['Aujourd\'hui · mercredi 14 octobre', 'Hier · mardi 13 octobre', 'samedi 10 octobre'], array_column($groupes, 'libelle'));
        self::assertSame('09:40', FilNotifications::quand(new \DateTimeImmutable('2026-10-14 09:40'), self::maintenant()));
        self::assertSame('hier', FilNotifications::quand(new \DateTimeImmutable('2026-10-13 16:10'), self::maintenant()));
        self::assertSame('10/10', FilNotifications::quand(new \DateTimeImmutable('2026-10-10 11:00'), self::maintenant()));
    }

    /** @return list<array<string, mixed>> le plus récent d'abord */
    private static function fil(): array
    {
        return [
            self::evenement('bat', '2026-10-14 09:40', 'CMD-4'),
            self::evenement('echeance', '2026-10-14 08:30', 'F-0009', 45000),
            self::evenement('devis', '2026-10-13 16:10', 'DV-0003', 120000),
            self::evenement('livree', '2026-10-10 11:00', 'CMD-1'),
        ];
    }

    /** @return array<string, mixed> */
    private static function evenement(string $type, string $date, string $numero, ?int $montant = null): array
    {
        return ['type' => $type, 'titre' => $type, 'texte' => $numero, 'date' => new \DateTimeImmutable($date), 'lien' => ['app_espace_commande', ['id' => $numero]], 'numero' => $numero, 'montant' => $montant];
    }

    private static function maintenant(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::MAINTENANT);
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits($droits);
    }
}
