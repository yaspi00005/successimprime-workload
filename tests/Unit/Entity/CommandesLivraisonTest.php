<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Commandes;
use PHPUnit\Framework\TestCase;

final class CommandesLivraisonTest extends TestCase
{
    public function testRetraitParDefaut(): void
    {
        $commande = new Commandes();

        self::assertSame(Commandes::LIVRAISON_RETRAIT, $commande->getModeLivraison());
        self::assertFalse($commande->estALivrer());
    }

    public function testLivraisonEtValeurInconnue(): void
    {
        $commande = (new Commandes())->setModeLivraison(Commandes::LIVRAISON_DOMICILE);
        self::assertTrue($commande->estALivrer());

        $commande->setModeLivraison('pose');
        self::assertSame(Commandes::LIVRAISON_RETRAIT, $commande->getModeLivraison());
    }

    public function testAdresseVideDevientNulle(): void
    {
        $commande = (new Commandes())->setAdresseLivraison('   ');
        self::assertNull($commande->getAdresseLivraison());

        $commande->setAdresseLivraison(' Hôtel Niger, Bamako ');
        self::assertSame('Hôtel Niger, Bamako', $commande->getAdresseLivraison());
    }
}
