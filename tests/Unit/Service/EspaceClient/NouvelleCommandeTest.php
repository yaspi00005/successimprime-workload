<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Service\EspaceClient\NouvelleCommande;
use PHPUnit\Framework\TestCase;

final class NouvelleCommandeTest extends TestCase
{
    public function testBacheAuMetreCarreAvecOeillets(): void
    {
        [$bache, $oeillets, $ourlet] = $this->bache();

        // 3 m × 1 m à 8 000 F/m², 2 exemplaires = 48 000 F ; œillets 3 000 F par exemplaire.
        $estimation = NouvelleCommande::estimer($bache, 2, 300, 100, [$oeillets->getId()], false);

        self::assertSame(54000, $estimation['total']);
        self::assertSame([['libelle' => 'Impression', 'montant' => 48000], ['libelle' => 'Œillets', 'montant' => 6000]], $estimation['detail']);
        self::assertNotContains('Ourlet', array_column($estimation['detail'], 'libelle'));
        unset($ourlet);
    }

    public function testFinitionAuMetreCarreCompteePourChaqueExemplaire(): void
    {
        [$bache] = $this->bache();
        $fourreau = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Fourreau'))
            ->setPayante(true)->setPrix(300)->setModeCalcul('metre_carre');
        (new \ReflectionProperty($fourreau, 'id'))->setValue($fourreau, 3);
        $bache->addConfigurationFinition($fourreau);

        // 2 bâches de 3 m² : 6 m² de finition à 300 F, comme au comptoir.
        $estimation = NouvelleCommande::estimer($bache, 2, 300, 100, [3], false);

        self::assertSame(['libelle' => 'Fourreau', 'montant' => 1800], $estimation['detail'][1]);
    }

    public function testFinitionObligatoireToujoursComptee(): void
    {
        [$bache, , $ourlet] = $this->bache();
        $ourlet->setObligatoire(true)->setSelectionneeParDefaut(true);

        $estimation = NouvelleCommande::estimer($bache, 1, 200, 100, [], false);

        self::assertSame(16000 + 1000, $estimation['total']);
    }

    public function testPrixB2B(): void
    {
        [$bache] = $this->bache();
        $bache->setPrixB2B(6000);

        self::assertSame(18000, NouvelleCommande::estimer($bache, 1, 300, 100, [], true)['total']);
        self::assertSame(24000, NouvelleCommande::estimer($bache, 1, 300, 100, [], false)['total']);
    }

    public function testTailleEtQuantiteControlees(): void
    {
        [$bache] = $this->bache();
        $bache->setQuantiteMinimale(1)->setQuantiteMaximale(50);

        foreach ([[1, null, 100, 'Indiquez la largeur'], [1, 6000, 100, 'plus de 50 m'], [51, 300, 100, 'demandez un devis']] as [$q, $l, $h, $message]) {
            try {
                NouvelleCommande::estimer($bache, $q, $l, $h, [], false);
                self::fail('Erreur attendue : '.$message);
            } catch (\DomainException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testCartesALUniteSansTaille(): void
    {
        $cartes = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Cartes de visite'))
            ->setModeDimension('aucune')->setModeCalcul('unite')->setPrixBase(50);

        self::assertFalse(NouvelleCommande::demandeTaille($cartes));
        self::assertSame(25000, NouvelleCommande::estimer($cartes, 500, null, null, [], false)['total']);
    }

    /** @return array{ProduitConfiguration, ProduitConfigurationFinition, ProduitConfigurationFinition} */
    private function bache(): array
    {
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche'))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(8000);
        $oeillets = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Œillets'))
            ->setPayante(true)->setPrix(3000)->setModeCalcul('unite');
        $ourlet = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Ourlet'))
            ->setPayante(true)->setPrix(1000)->setModeCalcul('forfait');
        foreach ([[$oeillets, 1], [$ourlet, 2]] as [$f, $id]) {
            (new \ReflectionProperty($f, 'id'))->setValue($f, $id);
            $bache->addConfigurationFinition($f);
        }

        return [$bache, $oeillets, $ourlet];
    }
}
