<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Entity\CommandesDetails;
use App\Service\Atelier\TarifAtelier;
use App\Service\Parametres\CatalogueParametres;
use PHPUnit\Framework\TestCase;

/** Prix de l'Atelier 3D : catalogue, seconde face, paliers de quantité (docs/atelier-3d.md). */
final class TarifAtelierTest extends TestCase
{
    private static function defaut(): array
    {
        return TarifAtelier::normaliser(CatalogueParametres::avecDefauts(CatalogueParametres::tuiles()['atelier_3d']['champs'], []));
    }

    public function testValeursDuPrototypeParDefaut(): void
    {
        $r = self::defaut();
        self::assertSame([['quantite' => 25, 'pourcentage' => 10.0], ['quantite' => 50, 'pourcentage' => 15.0]], $r['paliers']);
        self::assertSame(1500, $r['face_en_plus']);
        self::assertFalse($r['paliers_b2b']);
    }

    public function testPalierEtSecondeFace(): void
    {
        $r = self::defaut();

        $un = TarifAtelier::calculer(5000, 1, 10, $r, false);
        self::assertSame(['prixUnitaire' => 5000, 'remiseUnitaire' => 0, 'net' => 5000, 'pourcentage' => 0.0, 'palier' => null], array_diff_key($un, ['prochain' => 0]));
        self::assertSame(['quantite' => 25, 'pourcentage' => 10.0, 'manque' => 15], $un['prochain']);

        // Devant + dos : 5 000 + 1 500 ; 30 pièces : -10 %, arrondi à 50 F.
        $deux = TarifAtelier::calculer(5000, 2, 30, $r, false);
        self::assertSame(6500, $deux['prixUnitaire']);
        self::assertSame(5850, $deux['net']);
        self::assertSame(650, $deux['remiseUnitaire']);
        self::assertSame(25, $deux['palier']);
        self::assertSame(20, $deux['prochain']['manque']);

        $cinquante = TarifAtelier::calculer(5000, 1, 50, $r, false);
        self::assertSame(4250, $cinquante['net']);
        self::assertNull($cinquante['prochain']);
    }

    public function testLeRevendeurGardeSonPrixSansPalierSaufReglage(): void
    {
        $r = self::defaut();
        $b2b = TarifAtelier::calculer(4200, 1, 60, $r, true);
        self::assertSame(4200, $b2b['net']);
        self::assertNull($b2b['palier']);

        $r['paliers_b2b'] = true;
        self::assertSame(3550, TarifAtelier::calculer(4200, 1, 60, $r, true)['net']);
    }

    public function testPaliersMalSaisisRemisDansLOrdre(): void
    {
        $r = TarifAtelier::normaliser(['palier1_quantite' => 50, 'palier1_remise' => 15, 'palier2_quantite' => 25, 'palier2_remise' => 10, 'face_en_plus' => -3]);
        self::assertSame([25, 50], array_column($r['paliers'], 'quantite'));
        self::assertSame(0, $r['face_en_plus']);

        // Un palier plus haut qui donnerait moins est ignoré ; un palier à 0 % aussi.
        $r = TarifAtelier::normaliser(['palier1_quantite' => 25, 'palier1_remise' => 20, 'palier2_quantite' => 50, 'palier2_remise' => 5]);
        self::assertSame([['quantite' => 25, 'pourcentage' => 20.0]], $r['paliers']);
        self::assertSame([], TarifAtelier::normaliser(['palier1_remise' => 0, 'palier2_remise' => 0])['paliers']);
    }

    public function testLaLignePorteLePrixEtLaRemise(): void
    {
        $ligne = (new CommandesDetails())->setModeConfiguration('manuel')->setModeCalcul('unite')->setQuantite(30)->setPrixUnitaire(5000)->setTva(0);
        TarifAtelier::appliquer($ligne, 2, self::defaut(), false);

        self::assertSame(6500.0, $ligne->getPrixUnitaire());
        self::assertSame(650, $ligne->getRemise());
        self::assertSame(2, $ligne->getNombreFaces());
        self::assertSame(30 * 5850, $ligne->getTotalTtc());
    }
}
