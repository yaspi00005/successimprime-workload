<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Controller\EspaceNouvelleCommandeController;
use App\Entity\Commandes;
use App\Entity\Paiements;
use App\Service\Synchro\Synchro;
use PHPUnit\Framework\TestCase;

/**
 * Commande passée en ligne avec « Livraison à Bamako » : les frais arrivent
 * au local s'ils sont 0 ou un des paliers ; un autre montant est retiré
 * (la boutique choisit en confirmant).
 */
final class FraisLivraisonEnLigneTest extends TestCase
{
    public function testUnPalierEstGarde(): void
    {
        $commande = $this->commande(2000);

        self::assertFalse(Synchro::verifierFraisEnLigne($commande, [1000, 1500, 2000]));
        self::assertSame(2000, $commande->getFraisLivraison());
        self::assertSame(26000, $commande->getTotalTtc());
        self::assertFalse(Synchro::verifierFraisEnLigne($this->commande(0), []), 'livraison offerte');
    }

    public function testHorsPaliersLesFraisSontRetiresDesTotaux(): void
    {
        $commande = $this->commande(1800);
        // L'acompte payé en ligne, en attente de la caisse : il ne change pas le reste à payer.
        $commande->addPaiement((new Paiements())->setMontant(5000)->setMode(Paiements::MODE_ORANGE_MONEY)->setDate(new \DateTimeImmutable()));

        self::assertTrue(Synchro::verifierFraisEnLigne($commande, [1000, 2000]));
        self::assertSame(0, $commande->getFraisLivraison());
        self::assertSame(24000, $commande->getTotalHt());
        self::assertSame(24000, $commande->getTotalTtc());
        self::assertSame(24000, $commande->getMontantApayer());
        self::assertSame(24000, $commande->getResteAPayer());
    }

    public function testTelephoneDuContact(): void
    {
        self::assertSame('+223 60 00 62 12', EspaceNouvelleCommandeController::telephone('22360006212'));
        self::assertSame('+33612345678', EspaceNouvelleCommandeController::telephone('33612345678'));
    }

    private function commande(int $frais): Commandes
    {
        return (new Commandes())->setCanal(Commandes::CANAL_EN_LIGNE)->setStatut(false)
            ->setFraisLivraison($frais)->setTotalHt(24000 + $frais)->setTotalTtc(24000 + $frais)
            ->setMontantApayer(24000 + $frais)->setResteAPayer(24000 + $frais);
    }
}
