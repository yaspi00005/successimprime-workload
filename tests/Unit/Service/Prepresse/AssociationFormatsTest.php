<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Service\Prepresse\AssociationFormats;
use PHPUnit\Framework\TestCase;

/**
 * Association des fichiers déposés aux formats d'une commande (maquette
 * PQ02) : la commande d'étiquettes de Fofana, 6 formats.
 */
final class AssociationFormatsTest extends TestCase
{
    /** @return list<array{id: int, largeurMm: float, hauteurMm: float, aFichier?: bool}> */
    private static function fofana(): array
    {
        return [
            ['id' => 1, 'largeurMm' => 75.0, 'hauteurMm' => 80.0, 'aFichier' => true],
            ['id' => 2, 'largeurMm' => 75.0, 'hauteurMm' => 75.0, 'aFichier' => true],
            ['id' => 3, 'largeurMm' => 55.0, 'hauteurMm' => 70.0],
            ['id' => 4, 'largeurMm' => 55.0, 'hauteurMm' => 50.0],
            ['id' => 5, 'largeurMm' => 55.0, 'hauteurMm' => 40.0],
            ['id' => 6, 'largeurMm' => 55.0, 'hauteurMm' => 55.0],
        ];
    }

    public function testLesCinqCasDeLaMaquette(): void
    {
        $r = AssociationFormats::associer(self::fofana(), [
            ['cle' => 'a', 'nom' => 'fofana_5,5×4.pdf', 'largeurMm' => 55.0, 'hauteurMm' => 40.0, 'vectoriel' => true],
            ['cle' => 'b', 'nom' => 'scan_0042.png', 'largeurPx' => 650, 'hauteurPx' => 827, 'dpi' => 300.0],
            ['cle' => 'c', 'nom' => 'IMG_2041.jpg', 'largeurPx' => 1200, 'hauteurPx' => 1100, 'photo' => true],
            ['cle' => 'd', 'nom' => 'logo rond.pdf', 'largeurMm' => 100.0, 'hauteurMm' => 100.0, 'vectoriel' => true],
            ['cle' => 'e', 'nom' => 'IMG_2044.jpg', 'largeurPx' => 4032, 'hauteurPx' => 3024, 'photo' => true],
        ]);
        $f = $r['fichiers'];

        self::assertSame([5, AssociationFormats::NOM, AssociationFormats::SUR], [$f['a']['format'], $f['a']['methode'], $f['a']['certitude']]);
        self::assertSame('nom du fichier', $f['a']['libelle']);
        self::assertSame('55 × 40 mm · vectoriel', $f['a']['dimensions']);

        self::assertSame([3, AssociationFormats::DIMENSIONS, AssociationFormats::SUR], [$f['b']['format'], $f['b']['methode'], $f['b']['certitude']]);
        self::assertSame('650 × 827 px · 300 dpi', $f['b']['dimensions']);

        // 1200 × 1100 : proche de 5,5 × 5 (pas sûr : à vérifier).
        self::assertSame([4, AssociationFormats::PROPORTIONS, AssociationFormats::VERIFIER], [$f['c']['format'], $f['c']['methode'], $f['c']['certitude']]);

        // Carré : le seul format carré qui attend encore son fichier (7,5 × 7,5 a déjà le sien).
        self::assertSame([6, AssociationFormats::CARREE, AssociationFormats::SUR], [$f['d']['format'], $f['d']['methode'], $f['d']['certitude']]);

        // Photo 4:3 : aucun format proche, à choisir.
        self::assertNull($f['e']['format']);
        self::assertSame(AssociationFormats::CHOISIR, $f['e']['certitude']);
        self::assertSame('4 032 × 3 024 px · photo', $f['e']['dimensions']);

        self::assertSame(['sur' => 3, 'verifier' => 1, 'choisir' => 1], $r['resume']);
    }

    public function testDimensionsAvecFondsPerdusEtFichierTourne(): void
    {
        $formats = [['id' => 'a', 'largeurMm' => 850.0, 'hauteurMm' => 2000.0], ['id' => 'b', 'largeurMm' => 210.0, 'hauteurMm' => 297.0]];
        // A4 avec 3 mm de fonds perdus, en paysage.
        $r = AssociationFormats::associer($formats, [['cle' => 'x', 'nom' => 'flyer.pdf', 'largeurMm' => 303.0, 'hauteurMm' => 216.0, 'vectoriel' => true]]);
        self::assertSame('b', $r['fichiers']['x']['format']);
        self::assertSame(AssociationFormats::DIMENSIONS, $r['fichiers']['x']['methode']);

        // Roll-up dessiné à l'échelle 1/10.
        $r = AssociationFormats::associer($formats, [['cle' => 'y', 'nom' => 'rollup.pdf', 'largeurMm' => 85.0, 'hauteurMm' => 200.0, 'vectoriel' => true]]);
        self::assertSame('a', $r['fichiers']['y']['format']);
    }

    public function testDeuxLignesDeMemeFormatCelleSansFichierDAbord(): void
    {
        $formats = [['id' => 1, 'largeurMm' => 55.0, 'hauteurMm' => 55.0, 'aFichier' => true], ['id' => 2, 'largeurMm' => 55.0, 'hauteurMm' => 55.0]];
        $r = AssociationFormats::associer($formats, [['cle' => 'x', 'nom' => 'rond 5.5x5.5.png']]);
        self::assertSame(2, $r['fichiers']['x']['format']);
    }

    public function testResolutionTropBasseIgnoree(): void
    {
        $r = AssociationFormats::associer(self::fofana(), [['cle' => 'x', 'nom' => 'capture.png', 'largeurPx' => 55, 'hauteurPx' => 70, 'dpi' => 72.0]]);
        // 72 dpi : pas de dimensions sûres, seulement les proportions.
        self::assertSame(AssociationFormats::PROPORTIONS, $r['fichiers']['x']['methode']);
        self::assertSame(3, $r['fichiers']['x']['format']);
    }

    public function testDimensionsDansNom(): void
    {
        self::assertSame([[75.0, 80.0]], AssociationFormats::dimensionsDansNom('fofana_7,5×8.pdf'));
        self::assertSame([[55.0, 70.0]], AssociationFormats::dimensionsDansNom('etiquette 55x70mm.png'));
        self::assertSame([[1200.0, 3000.0]], AssociationFormats::dimensionsDansNom('bache 1.2X3m.pdf'));
        self::assertSame([[55.0, 55.0]], AssociationFormats::dimensionsDansNom('CMD-000378_5.5*5.5.ai'));
        self::assertSame([], AssociationFormats::dimensionsDansNom('IMG_2041.jpg'));
    }

    public function testLibelleFormat(): void
    {
        self::assertSame('7,5 × 8 cm', AssociationFormats::libelleFormat(75, 80));
        self::assertSame('85 × 200 cm', AssociationFormats::libelleFormat(850, 2000));
    }
}
