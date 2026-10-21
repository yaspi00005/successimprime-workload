<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ImpositionRouleau;
use PHPUnit\Framework\TestCase;

final class ImpositionRouleauTest extends TestCase
{
    /** Le rouleau de la maquette PD02 : solvant 3,20 m, marge 10 cm, 4 cm entre les pièces. */
    public function testRouleauDeLaMaquettePd02(): void
    {
        $pieces = [
            ['cle' => 'koita-1', 'largeur' => 120.0, 'hauteur' => 200.0],
            ['cle' => 'pasteur-1', 'largeur' => 85.0, 'hauteur' => 200.0],
            ['cle' => 'pasteur-2', 'largeur' => 85.0, 'hauteur' => 200.0],
            ['cle' => 'awa-1', 'largeur' => 300.0, 'hauteur' => 100.0],
            ['cle' => 'awa-2', 'largeur' => 300.0, 'hauteur' => 100.0],
        ];

        $r = ImpositionRouleau::placer(320.0, 10.0, 4.0, $pieces);

        self::assertSame([], $r['refusees']);
        self::assertCount(3, $r['etageres']);
        // Koita en haut à gauche : le plus urgent part en premier.
        self::assertSame(['cle' => 'koita-1', 'x' => 10.0, 'y' => 0.0, 'largeur' => 120.0, 'hauteur' => 200.0, 'tourne' => false, 'etagere' => 0], $r['pieces'][0]);
        self::assertSame([134.0, 223.0], [$r['pieces'][1]['x'], $r['pieces'][2]['x']]);
        self::assertSame([204.0, 308.0], [$r['pieces'][3]['y'], $r['pieces'][4]['y']]);

        $b = $r['bilan'];
        self::assertSame(408.0, $b['longueur']);
        self::assertSame('4,1', number_format($b['longueur'] / 100, 1, ',', ''));
        self::assertSame('13,1', number_format($b['consomme'], 1, ',', ''));
        self::assertSame('11,8', number_format($b['imprime'], 1, ',', ''));
        self::assertSame('1,3', number_format($b['chute'], 1, ',', ''));
        self::assertSame(90, $b['pourcent']);
        self::assertSame(['koita-1', 'pasteur-1', 'pasteur-2', 'awa-1', 'awa-2'], ImpositionRouleau::ordreImpression($r['pieces']));

        // 25 m²/h, séchage 45 min, 12 ml/m² : 28 min, 45 min, ≈ 142 ml.
        self::assertSame(['impressionMin' => 28, 'sechageMin' => 45, 'encreMl' => 142], ImpositionRouleau::estimation($b['imprime'], 25.0, 45, 12.0));
        self::assertSame([], ImpositionRouleau::problemes(320.0, 10.0, $r['pieces']));
    }

    public function testPiecePlusLargeQueLaLaizeEstTournee(): void
    {
        // 400 × 150 sur une laize de 1,80 m : elle passe tournée (150 en travers).
        $r = ImpositionRouleau::placer(180.0, 10.0, 4.0, [['cle' => 'b', 'largeur' => 400.0, 'hauteur' => 150.0]]);

        self::assertTrue($r['pieces'][0]['tourne']);
        self::assertSame([150.0, 400.0], [$r['pieces'][0]['largeur'], $r['pieces'][0]['hauteur']]);
        self::assertSame(400.0, $r['bilan']['longueur']);
    }

    public function testPieceTropGrandeRefuseeAvecSaRaison(): void
    {
        $r = ImpositionRouleau::placer(180.0, 10.0, 4.0, [['cle' => 'geante', 'largeur' => 300.0, 'hauteur' => 200.0], ['cle' => 'petite', 'largeur' => 50.0, 'hauteur' => 50.0]]);

        self::assertSame('geante', $r['refusees'][0]['cle']);
        self::assertStringContainsString('160 cm utiles', $r['refusees'][0]['raison']);
        self::assertSame(['petite'], array_column($r['pieces'], 'cle'));
    }

    public function testTourneeQuandElleEntreSansRehausserLEtagere(): void
    {
        // Étagère de 100 cm de haut ; 60 × 120 ne tient pas debout sans la rehausser, mais tournée (120 × 60) oui.
        $r = ImpositionRouleau::placer(320.0, 10.0, 4.0, [
            ['cle' => 'a', 'largeur' => 150.0, 'hauteur' => 100.0],
            ['cle' => 'b', 'largeur' => 60.0, 'hauteur' => 120.0],
        ]);

        self::assertTrue($r['pieces'][1]['tourne']);
        self::assertSame(0.0, $r['pieces'][1]['y']);
        self::assertSame(100.0, $r['bilan']['longueur']);
    }

    public function testBeaucoupDePetitesPiecesRemplissentLaLargeur(): void
    {
        $pieces = [];
        for ($i = 1; $i <= 40; ++$i) {
            $pieces[] = ['cle' => 'p'.$i, 'largeur' => 50.0, 'hauteur' => 30.0];
        }
        // 300 cm utiles : 5 pièces de 50 + 4 espaces de 4 = 266 ; la 6e ferait 320 > 300.
        $r = ImpositionRouleau::placer(320.0, 10.0, 4.0, $pieces);

        self::assertSame([], $r['refusees']);
        self::assertCount(8, $r['etageres']);
        self::assertSame(8 * 30 + 7 * 4.0, $r['bilan']['longueur']);
        self::assertSame([], ImpositionRouleau::problemes(320.0, 10.0, $r['pieces']));
    }

    public function testLongueurMaximaleDuTirage(): void
    {
        $pieces = [];
        for ($i = 1; $i <= 12; ++$i) {
            $pieces[] = ['cle' => 'b'.$i, 'largeur' => 300.0, 'hauteur' => 300.0];
        }
        // Trente mètres au plus : 9 pièces de 3 m + 8 espaces = 2 732 cm ; la 10e ferait 3 036 cm.
        $r = ImpositionRouleau::placer(320.0, 10.0, 4.0, $pieces, 3000.0);

        self::assertCount(9, $r['pieces']);
        self::assertCount(3, $r['refusees']);
        self::assertStringContainsString('30 m', $r['refusees'][0]['raison']);
        self::assertLessThanOrEqual(3000.0, $r['bilan']['longueur']);
    }

    public function testPlacementManuelControle(): void
    {
        $pieces = [
            ['cle' => 'a', 'x' => 10.0, 'y' => 0.0, 'largeur' => 120.0, 'hauteur' => 200.0],
            ['cle' => 'b', 'x' => 100.0, 'y' => 50.0, 'largeur' => 85.0, 'hauteur' => 200.0],
            ['cle' => 'c', 'x' => 250.0, 'y' => 0.0, 'largeur' => 85.0, 'hauteur' => 100.0],
        ];
        $problemes = ImpositionRouleau::problemes(320.0, 10.0, $pieces);

        self::assertContains('a et b se chevauchent.', $problemes);
        self::assertContains('c sort de la largeur utile (marge de 10 cm de chaque côté).', $problemes);
        self::assertSame(250.0, ImpositionRouleau::bilan(320.0, $pieces)['longueur']);
    }

    public function testCoutMatiereEtEncre(): void
    {
        // 13,056 m² à 550 F + 11,8 m² d'encre à 650 F.
        self::assertSame(14851, ImpositionRouleau::cout(13.056, 11.8, 550, 650));
        self::assertNull(ImpositionRouleau::cout(13.0, 11.8, null, null));
        // Un coût manque (encre de la machine non réglée) : pas de total à moitié.
        self::assertNull(ImpositionRouleau::cout(13.0, 11.8, 550, null));
        self::assertNull(ImpositionRouleau::cout(13.0, 11.8, null, 650));
    }

    public function testCoutDuM2SelonLUniteDeLArticle(): void
    {
        // Au m² : tel quel ; au mètre linéaire : ÷ laize ; au rouleau de 50 m : ÷ (3,20 × 50) m².
        self::assertSame(600.0, ImpositionRouleau::coutM2Matiere('m2', 600, 3.2, 50.0));
        self::assertSame(600.0, ImpositionRouleau::coutM2Matiere('metre', 1920, 3.2, null));
        self::assertSame(1200.0, ImpositionRouleau::coutM2Matiere('rouleau', 192000, 3.2, 50.0));
        // Rien d'inventé : unité sans rapport, laize ou longueur inconnue, coût absent.
        self::assertNull(ImpositionRouleau::coutM2Matiere('kg', 600, 3.2, 50.0));
        self::assertNull(ImpositionRouleau::coutM2Matiere('rouleau', 192000, 3.2, null));
        self::assertNull(ImpositionRouleau::coutM2Matiere('metre', 1920, null, null));
        self::assertNull(ImpositionRouleau::coutM2Matiere('m2', null, 3.2, 50.0));
    }
}
