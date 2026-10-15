<?php

namespace App\Tests\Unit\Service;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Service\PaiementService;
use PHPUnit\Framework\TestCase;

/**
 * Validation d'un paiement Orange Money de l'espace client (À valider) :
 * le compte reçoit aussi les frais payés par le client, dans un mouvement
 * « Autre produit » à part, comme à la caisse ; la commande n'en voit rien.
 */
final class PaiementFraisTest extends TestCase
{
    public function testLesFraisOntLeurMouvement(): void
    {
        $paiement = $this->paiement(250, 250);
        $compte = new CompteTresorerie();

        $mouvement = PaiementService::mouvementFrais($paiement, $compte, null);

        self::assertInstanceOf(MouvementTresorerie::class, $mouvement);
        self::assertSame(500, $mouvement->getMontant());
        self::assertSame(MouvementTresorerie::TYPE_ENCAISSEMENT, $mouvement->getType());
        self::assertSame(MouvementTresorerie::CATEGORIE_AUTRE_PRODUIT, $mouvement->getCategorie(), 'pas dans les ventes');
        self::assertSame($compte, $mouvement->getCompteDestination());
        self::assertSame('MVT-FRAIS-P42', $mouvement->getReference(), 'retrouvé à l\'annulation du paiement');
        self::assertSame('OM MP261015.1000.A1', $mouvement->getReferenceExterne());
        self::assertStringContainsString('fonds de soutien : 250 FCFA, frais de retrait : 250 FCFA', (string) $mouvement->getDescription());
        self::assertSame(25500, $paiement->getMontantTotalEncaisse());
        self::assertSame(25000, $paiement->getMontant(), 'la part de la commande ne bouge pas');
    }

    public function testSansFraisPasDeMouvement(): void
    {
        self::assertNull(PaiementService::mouvementFrais($this->paiement(0, 0), new CompteTresorerie(), null));
    }

    private function paiement(int $fonds, int $retrait): Paiements
    {
        $commande = (new Commandes())->setNumero('CMD-000042-10-2026')->setTotalTtc(25000)->setClients((new Clients())->setNom('Hôtel Niger'));
        $paiement = (new Paiements())
            ->setCommande($commande)
            ->setMontant(25000)
            ->setMode(Paiements::MODE_ORANGE_MONEY)
            ->setReference('OM MP261015.1000.A1')
            ->setFondsSoutienInclus($fonds > 0)->setMontantFondsSoutien($fonds)
            ->setFraisRetraitInclus($retrait > 0)->setMontantFraisRetrait($retrait);
        (new \ReflectionProperty(Paiements::class, 'id'))->setValue($paiement, 42);

        return $paiement;
    }
}
