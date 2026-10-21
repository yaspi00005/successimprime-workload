<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ControlesTirage;
use App\Service\Production\ProfilsImpression;
use PHPUnit\Framework\TestCase;

final class ControlesTirageTest extends TestCase
{
    private const PROFIL = ['fichier' => 'Bache440_Solvant_8pass.icc', 'mode' => '8 passes · bidirectionnel', 'vitesse' => 25.0, 'chauffe' => 45, 'sechage' => 45, 'miroir' => false];

    public function testFicheDeLaMaquettePd03UnPointBloquant(): void
    {
        $coches = ['support' => true, 'profil' => true, 'mode' => true, 'sechage' => true, 'miroir' => true];
        $points = ControlesTirage::evaluer('Bâche 440 g frontlit · laize 3,20 m', $coches, self::PROFIL, ['le' => new \DateTimeImmutable('2026-10-20 08:12'), 'bouchees' => 0], ['C' => 62, 'M' => 55, 'J' => 18, 'N' => 70], 20, ['rouge Awa Mode']);

        self::assertSame(['support', 'profil', 'mode', 'buses', 'encres', 'sechage', 'miroir', 'echelle', 'couleurs'], array_column($points, 'cle'));
        $etats = array_column($points, 'etat', 'cle');
        self::assertSame(ControlesTirage::BLOQUANT, $etats['encres']);
        self::assertSame('jaune à 18 % : remplacer avant le travail', $points[4]['detail']);
        self::assertSame('fait à 08:12 · 0 buse bouchée', $points[3]['detail']);
        self::assertSame(ControlesTirage::SURVEILLER, $etats['echelle']);
        self::assertSame('rouge Awa Mode : comparer au BAT imprimé', $points[8]['detail']);
        self::assertSame(['bloquants' => 1, 'afaire' => 0, 'surveiller' => 2, 'pret' => false], ControlesTirage::resume($points));

        // Jaune remplacé : tout est vert ou orange, « Démarrer » se débloque.
        $points = ControlesTirage::evaluer('Bâche', $coches, self::PROFIL, ['le' => new \DateTimeImmutable(), 'bouchees' => 0], ['C' => 62, 'M' => 55, 'J' => 100, 'N' => 70], 20, []);
        self::assertTrue(ControlesTirage::resume($points)['pret']);
    }

    public function testRienDeCocheEtSansProfil(): void
    {
        $points = ControlesTirage::evaluer('Vinyle', [], null, null, [], 20, [], null, true);
        $etats = array_column($points, 'etat', 'cle');

        self::assertSame(ControlesTirage::BLOQUANT, $etats['profil']);
        self::assertSame(ControlesTirage::BLOQUANT, $etats['sechage']);
        self::assertSame(ControlesTirage::A_FAIRE, $etats['support']);
        self::assertSame(ControlesTirage::A_FAIRE, $etats['buses']);
        self::assertSame(ControlesTirage::A_FAIRE, $etats['encres']);
        self::assertSame('impression en miroir (transfert)', array_column($points, 'detail', 'cle')['miroir']);
        self::assertSame(ControlesTirage::OK, $etats['couleurs']);
        self::assertFalse(ControlesTirage::resume($points)['pret']);
    }

    public function testBusesBoucheesAsurveillerSansBloquer(): void
    {
        $points = ControlesTirage::evaluer('Bâche', [], self::PROFIL, ['le' => new \DateTimeImmutable('2026-10-20 07:30'), 'bouchees' => 3], ['C' => 90], 20, []);

        self::assertSame(ControlesTirage::SURVEILLER, array_column($points, 'etat', 'cle')['buses']);
    }

    public function testCouleursSensiblesDansLesRemarques(): void
    {
        self::assertSame('rouge Awa Mode', ControlesTirage::couleurSensible(['Le rouge du logo doit bien ressortir.', null], 'Awa Mode'));
        self::assertSame('couleurs Koita', ControlesTirage::couleurSensible(['Respecter la charte'], 'Koita'));
        self::assertNull(ControlesTirage::couleurSensible(['Livrer avant midi', 'Ourlet 3 cm'], 'Koita'));
    }

    public function testCodeDuRouleau(): void
    {
        self::assertTrue(ControlesTirage::codeRouleauConforme(' d-mat-020 ', 'D-MAT-020'));
        self::assertFalse(ControlesTirage::codeRouleauConforme('D-MAT-002', 'D-MAT-020'));
        self::assertFalse(ControlesTirage::codeRouleauConforme('', null));
    }

    public function testProfilDuSupport(): void
    {
        $profils = ProfilsImpression::normaliser([
            ['support' => 'vinyle', 'profilIcc' => 'Vinyle.icc', 'vitesseM2h' => '18'],
            ['support' => 'bâche 440', 'profilIcc' => 'Bache440_Solvant_8pass.icc', 'mode' => '8 passes', 'vitesseM2h' => '25', 'encreMlM2' => '12', 'chauffeC' => '45', 'sechageMin' => '45'],
            ['support' => '', 'profilIcc' => ''],
            ['support' => '900020', 'profilIcc' => 'Article.icc', 'vitesseM2h' => '9999'],
        ]);

        self::assertCount(3, $profils);
        self::assertNull($profils[2]['vitesseM2h']);
        self::assertSame('Bache440_Solvant_8pass.icc', ProfilsImpression::choisir($profils, null, 'Bâche 440 g frontlit', 'BACHE440')['profilIcc']);
        self::assertSame('Article.icc', ProfilsImpression::choisir($profils, 900020, 'Toile', 'TOILE')['profilIcc']);
        self::assertSame('Vinyle.icc', ProfilsImpression::choisir($profils, null, 'Akylux', 'AKYLUX3')['profilIcc']);
        self::assertNull(ProfilsImpression::choisir([], null, 'Bâche', 'BACHE'));
    }
}
