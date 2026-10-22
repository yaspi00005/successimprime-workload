<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Paiements;
use App\Service\EspaceClient\ElementAPayer;
use App\Service\EspaceClient\MesCommandesClient;
use App\Service\EspaceClient\PaiementALaCommande;
use PHPUnit\Framework\TestCase;

/**
 * Acompte ou tout payé à la commande (maquette CO04) : seulement la
 * commande en ligne « à confirmer » de ce client, jamais plus que ce qui
 * reste à payer.
 */
final class PaiementALaCommandeTest extends TestCase
{
    public function testSeuleLaCommandeEnLigneDuClientSePaieAvantConfirmation(): void
    {
        $compte = $this->compte(7);

        self::assertTrue(PaiementALaCommande::payable($this->commande(7), $compte));

        $comptoir = $this->commande(7)->setCanal(Commandes::CANAL_COMPTOIR);
        self::assertFalse(PaiementALaCommande::payable($comptoir, $compte), 'une commande du comptoir attend sa confirmation');
        self::assertFalse(PaiementALaCommande::payable($this->commande(8), $compte), 'la commande d\'un autre client');
        self::assertFalse(PaiementALaCommande::payable($this->commande(7)->setStatut(true), $compte), 'confirmée : « Factures et paiements »');
        self::assertFalse(PaiementALaCommande::payable($this->commande(7)->setDeleted(true), $compte));
        self::assertFalse(PaiementALaCommande::payable($this->commande(7)->setValidationEntreprise(Commandes::VALIDATION_A_FAIRE), $compte), 'demande qui attend le valideur');
        self::assertFalse(PaiementALaCommande::payable($this->commande(7)->setValidationEntreprise(Commandes::VALIDATION_REFUSEE), $compte));
        self::assertTrue(PaiementALaCommande::payable($this->commande(7)->setValidationEntreprise(Commandes::VALIDATION_ACCEPTEE), $compte));
    }

    public function testAcompteOuTout(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-16 10:00');
        $commande = $this->commande(7, 23001);

        $acompte = PaiementALaCommande::element($commande, PaiementALaCommande::ACOMPTE, 50, $maintenant);
        self::assertNotNull($acompte);
        self::assertSame(ElementAPayer::ACOMPTE, $acompte->nature);
        self::assertSame(11501, $acompte->montant, 'arrondi au franc supérieur');
        self::assertSame(50, $acompte->pourcentage);

        $tout = PaiementALaCommande::element($commande, PaiementALaCommande::TOUT, 50, $maintenant);
        self::assertSame(ElementAPayer::COMMANDE, $tout?->nature);
        self::assertSame(23001, $tout->montant);

        // 0 % ou 100 % : pas d'acompte, tout se paie.
        self::assertSame(ElementAPayer::COMMANDE, PaiementALaCommande::element($commande, PaiementALaCommande::ACOMPTE, 100, $maintenant)?->nature);
        self::assertSame(ElementAPayer::COMMANDE, PaiementALaCommande::element($commande, PaiementALaCommande::ACOMPTE, 0, $maintenant)?->nature);
    }

    public function testCeQuiAttendLaCaisseNEstPlusDemande(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-16 10:00');
        $commande = $this->commande(7, 23000);
        // L'acompte payé en ligne, en attente de la caisse.
        $commande->addPaiement((new Paiements())->setMontant(11500)->setMode(Paiements::MODE_ORANGE_MONEY)->setDate($maintenant));

        // L'acompte est couvert : il ne reste que le tout, sans ce qui est déjà en validation.
        $element = PaiementALaCommande::element($commande, PaiementALaCommande::ACOMPTE, 50, $maintenant);
        self::assertSame(ElementAPayer::COMMANDE, $element?->nature);
        self::assertSame(11500, $element->montant);

        $commande->addPaiement((new Paiements())->setMontant(11500)->setMode(Paiements::MODE_ORANGE_MONEY)->setDate($maintenant));
        self::assertNull(PaiementALaCommande::element($commande, PaiementALaCommande::TOUT, 50, $maintenant), 'plus rien à payer');
    }

    /** Plafond dépassé (maquette CD04) : solde + ce qui reste à crédit, comparé au plafond. */
    public function testPlafondDepasseEtDifference(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 10:00');
        self::assertNull(MesCommandesClient::depassement(null, 800000, 500000), 'sans plafond');
        self::assertNull(MesCommandesClient::depassement(900000, 800000, 100000), 'juste au plafond');
        self::assertNull(MesCommandesClient::depassement(900000, 950000, 0), 'commande payée en entier : rien à crédit');
        self::assertSame(['plafond' => 900000, 'solde' => 836850, 'apres' => 2036850, 'difference' => 1136850], MesCommandesClient::depassement(900000, 836850, 1200000));

        $commande = $this->commande(7, 1200000);
        $difference = PaiementALaCommande::element($commande, PaiementALaCommande::DIFFERENCE, 50, $maintenant, 1136850);
        self::assertSame(ElementAPayer::ACOMPTE, $difference?->nature);
        self::assertSame(1136850, $difference->montant);
        self::assertSame(95, $difference->pourcentage);

        // Petit dépassement : au moins l'acompte, qui lance la production.
        self::assertSame(600000, PaiementALaCommande::element($commande, PaiementALaCommande::DIFFERENCE, 50, $maintenant, 5000)?->montant);
        // Plus que la commande : tout, jamais plus.
        self::assertSame(ElementAPayer::COMMANDE, PaiementALaCommande::element($commande, PaiementALaCommande::DIFFERENCE, 50, $maintenant, 2000000)?->nature);
    }

    private function commande(int $client, int $total = 10000): Commandes
    {
        return (new Commandes())->setCanal(Commandes::CANAL_EN_LIGNE)->setStatut(false)->setTotalTtc($total)->setClients($this->client($client));
    }

    private function compte(int $client): CompteClient
    {
        return (new CompteClient())->setClient($this->client($client));
    }

    private function client(int $id): Clients
    {
        $c = new Clients();
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($c, $id);

        return $c;
    }
}
