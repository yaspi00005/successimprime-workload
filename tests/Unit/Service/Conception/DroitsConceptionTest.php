<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\SupportConception;
use App\Service\Conception\DroitsConception;
use PHPUnit\Framework\TestCase;

/**
 * Qui peut quoi dans une demande de conception, selon le compte : tout le
 * monde ne voit pas tout, ne paie pas, ne génère pas.
 */
final class DroitsConceptionTest extends TestCase
{
    public function testParticulierFaitTout(): void
    {
        $client = $this->client(1, Clients::TYPE_COMPTE_PARTICULIER, 'B2C');
        $compte = (new CompteClient())->setClient($client)->setDroits([]);
        $demande = $this->demande($client);

        self::assertTrue(DroitsConception::peutPreparer($compte));
        self::assertTrue(DroitsConception::peutPayer($compte));
        self::assertTrue(DroitsConception::peutVoir($compte, $demande));
        self::assertTrue(DroitsConception::peutModifier($compte, $demande));
    }

    public function testDansUneEntrepriseSeulLePayeurPaieEtGenere(): void
    {
        $entreprise = $this->client(2, Clients::TYPE_COMPTE_ENTREPRISE, 'B2B');
        $demande = $this->demande($entreprise);
        $admin = (new CompteClient())->setClient($entreprise);
        $payeur = (new CompteClient())->setClient($entreprise)->setDroits([CompteClient::DROIT_PAYEUR]);
        $valideur = (new CompteClient())->setClient($entreprise)->setDroits([CompteClient::DROIT_VALIDEUR]);
        $demandeur = (new CompteClient())->setClient($entreprise)->setDroits([]);

        foreach ([$admin, $payeur] as $compte) {
            self::assertTrue(DroitsConception::peutPayer($compte));
        }
        foreach ([$valideur, $demandeur] as $compte) {
            // Il prépare la demande, mais l'acompte, le crédit et chaque image sont payés par un payeur.
            self::assertTrue(DroitsConception::peutPreparer($compte));
            self::assertTrue(DroitsConception::peutModifier($compte, $demande));
            self::assertFalse(DroitsConception::peutPayer($compte));
        }
    }

    public function testLectureSeuleRegardeSeulement(): void
    {
        $entreprise = $this->client(3, Clients::TYPE_COMPTE_ENTREPRISE, 'B2B');
        $demande = $this->demande($entreprise);
        $lecture = (new CompteClient())->setClient($entreprise);
        // Droit « lecture » des comptes en lecture seule (posé par Mon compte).
        (new \ReflectionProperty(CompteClient::class, 'droits'))->setValue($lecture, ['lecture']);

        self::assertTrue(DroitsConception::lectureSeule($lecture));
        self::assertTrue(DroitsConception::peutVoir($lecture, $demande));
        self::assertFalse(DroitsConception::peutPreparer($lecture));
        self::assertFalse(DroitsConception::peutModifier($lecture, $demande));
        self::assertFalse(DroitsConception::peutPayer($lecture));
    }

    public function testCompteDesactiveNeFaitRien(): void
    {
        $client = $this->client(4, Clients::TYPE_COMPTE_PARTICULIER, 'B2C');
        $compte = (new CompteClient())->setClient($client)->setActif(false);

        self::assertFalse(DroitsConception::peutPreparer($compte));
        self::assertFalse(DroitsConception::peutPayer($compte));
    }

    public function testDemandeDUnAutreClientInvisible(): void
    {
        $moi = $this->client(5, Clients::TYPE_COMPTE_PARTICULIER, 'B2C');
        $autre = $this->client(6, Clients::TYPE_COMPTE_PARTICULIER, 'B2C');
        $compte = (new CompteClient())->setClient($moi);
        $demande = $this->demande($autre);

        self::assertFalse(DroitsConception::peutVoir($compte, $demande));
        self::assertFalse(DroitsConception::peutModifier($compte, $demande));
    }

    public function testDemandePartieALAtelierNeSeModifiePlus(): void
    {
        $client = $this->client(7, Clients::TYPE_COMPTE_PARTICULIER, 'B2C');
        $compte = (new CompteClient())->setClient($client);
        $demande = $this->demande($client);
        $demande->envoyer(DemandeConception::RECUE, new \DateTimeImmutable());

        self::assertTrue(DroitsConception::peutVoir($compte, $demande));
        self::assertFalse(DroitsConception::peutModifier($compte, $demande));
    }

    private function client(int $id, string $typeCompte, string $type): Clients
    {
        $client = (new Clients())->setTypeClient($type)->setTypeCompte($typeCompte)->setNom('Client '.$id);
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($client, $id);

        return $client;
    }

    private function demande(Clients $client): DemandeConception
    {
        $support = (new SupportConception())->setCode('kakemono')->setNom('Kakémono / roll-up')->setCourt('Kakémono')->setFormat('85 × 200 cm')->setForfait(10000);

        return DemandeConception::nouvelle($client, null, $support, new \DateTimeImmutable('2026-10-14 10:00'));
    }
}
