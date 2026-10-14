<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\SupportConception;
use App\Service\Conception\DemandesConception;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\EspaceClient\PreferencesNotifications;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Le suivi d'une demande de conception dans les notifications du client :
 * prise en charge, terminée, annulée, sans montant, vu aussi en lecture seule.
 */
final class FilConceptionTest extends TestCase
{
    public function testPriseEnChargePuisTermineeSansMontant(): void
    {
        $client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $demande = self::demande($client);
        $demande->prendreEnCharge('Seydou', new \DateTimeImmutable('2026-10-13 10:00'));
        $demande->terminer(DemandeConception::TERMINEE, new \DateTimeImmutable('2026-10-14 09:00'));

        $fil = DemandesConception::fil($this->em([$demande]), $client);

        self::assertSame(['conception', 'conception'], array_column($fil, 'type'));
        self::assertSame(['Demande de conception prise en charge', 'Conception terminée'], array_column($fil, 'titre'));
        self::assertSame([null, null], array_column($fil, 'montant'));
        self::assertSame('app_espace_conception_demande', $fil[0]['lien'][0]);
        self::assertSame((string) $demande->getPublicId(), $fil[0]['cible']['demande']);
        // Le message (écran verrouillé, SMS) ne porte que le numéro, jamais le forfait.
        $message = NotificationsClient::message($fil[0]);
        self::assertSame('Demande de conception prise en charge : W-DC-2026-014.', $message);
        self::assertStringNotContainsString('10', str_replace('2026-014', '', $message));
    }

    public function testAnnuleeEtVueParChaqueCompte(): void
    {
        $client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $demande = self::demande($client);
        $demande->terminer(DemandeConception::ANNULEE, new \DateTimeImmutable('2026-10-14 09:00'));
        $fil = DemandesConception::fil($this->em([$demande]), $client);
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');

        self::assertSame(['Demande de conception annulée'], array_column($fil, 'titre'));
        foreach ([[CompteClient::DROIT_PAYEUR], [], [CompteClient::DROIT_LECTURE]] as $droits) {
            $vu = FilNotifications::presenter($fil, (new CompteClient())->setClient($client)->setDroits($droits), [], $maintenant);
            self::assertCount(1, $vu, implode(',', $droits));
            self::assertSame(['production', 'PAO', null], [$vu[0]['categorie'], $vu[0]['sigle'], $vu[0]['montant']]);
        }
    }

    public function testChaqueTypeEnvoyeAUneCategorieEtUnePastille(): void
    {
        foreach (NotificationsClient::TYPES as $type) {
            self::assertArrayHasKey($type, PreferencesNotifications::CATEGORIE_DU_TYPE, $type);
            self::assertArrayHasKey($type, FilNotifications::BADGES, $type);
        }
    }

    private static function demande(Clients $client): DemandeConception
    {
        $support = (new SupportConception())->setCode('affiche')->setNom('Affiche A3')->setCourt('Affiche')->setFormat('29,7 × 42 cm')->setLargeurCm(30)->setHauteurCm(42)->setForfait(10000);
        $demande = DemandeConception::nouvelle($client, null, $support, new \DateTimeImmutable('2026-10-12 09:00'));
        $demande->setNumero('W-DC-2026-014');
        $demande->envoyer(DemandeConception::RECUE, new \DateTimeImmutable('2026-10-12 09:30'));

        return $demande;
    }

    /** @param list<DemandeConception> $demandes */
    private function em(array $demandes): EntityManagerInterface
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findBy')->willReturn($demandes);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);

        return $em;
    }
}
