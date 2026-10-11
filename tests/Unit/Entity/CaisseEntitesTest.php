<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ClotureCaisse;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class CaisseEntitesTest extends TestCase
{
    public function testTotalPayeNeCompteQueLesPaiementsValides(): void
    {
        $commande = new Commandes();
        $caisse = (new CompteTresorerie())->setType(CompteTresorerie::TYPE_CAISSE);
        $valide = (new Paiements())->setMontant(20000)->setMode(Paiements::MODE_ESPECES)->setCompteTresorerie($caisse);
        $valide->validerPar(new User());
        $enAttente = (new Paiements())->setMontant(15000)->setMode(Paiements::MODE_VIREMENT);
        $commande->addPaiement($valide);
        $commande->addPaiement($enAttente);

        self::assertSame(20000, $commande->getTotalPaye());
    }

    public function testEcartDeCloture(): void
    {
        $cloture = (new ClotureCaisse())->setMontantTheorique(20000)->setMontantCompte(19000);
        self::assertSame(-1000, $cloture->getEcart());

        self::assertSame(0, $cloture->setMontantCompte(20000)->getEcart());
    }
}
