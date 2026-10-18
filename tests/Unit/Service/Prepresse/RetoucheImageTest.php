<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Controller\RetoucheImageController;
use App\Service\Prepresse\GenerateurRetouche;
use App\Service\Prepresse\GeometrieRetouche as G;
use App\Service\Prepresse\PdfImage;
use PHPUnit\Framework\TestCase;

/**
 * Retouche prépresse (docs/prepresse-retouche.md) : calculs des maquettes
 * PP01 et PP04, contours, fichier final, original jamais modifié.
 */
final class RetoucheImageTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/retouche-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dossier.'/*') ?: []);
        rmdir($this->dossier);
    }

    public function testKakemonoRecadreCommeLaMaquette(): void
    {
        // PP01 : 3 543 × 8 000 px en 850 × 2 000 mm, recadrer → 102 dpi, 72 px coupés de chaque côté (4 %), 5 020 × 11 811 px à 150 dpi.
        $p = G::placement(3543, 8000, 850, 2000, G::RECADRER, false, 150);
        self::assertSame(102, G::dpiEffectif($p));
        self::assertSame(['horizontal' => 72, 'vertical' => 0, 'pourcent' => 4], G::coupe(3543, 8000, $p));
        self::assertSame(['l' => 5020, 'h' => 11811], $p['sortiePx']);
        $r = G::reglages(['largeurMm' => 850, 'hauteurMm' => 2000, 'mode' => 'recadrer', 'dpiSortie' => 150]);
        self::assertSame([], G::bloquants(G::controles(3543, 8000, $r)));
    }

    public function testBacheEtireeBloqueeCommeLaMaquette(): void
    {
        // PP04 : 1 600 × 1 200 px étiré en 3 × 1 m → 14 dpi et +125 %, deux contrôles bloquants.
        $r = G::reglages(['largeurMm' => 3000, 'hauteurMm' => 1000, 'mode' => 'etirer', 'dpiSortie' => 100]);
        $controles = G::controles(1600, 1200, $r);
        self::assertSame(['resolution', 'deformation'], array_column(G::bloquants($controles), 'cle'));
        self::assertSame(14, $controles[0]['dpi']);
        self::assertSame(125, $controles[1]['pourcent']);
        // En « adapter », plus de déformation ; la résolution reste trop faible.
        $r['mode'] = G::ADAPTER;
        self::assertSame(['resolution'], array_column(G::bloquants(G::controles(1600, 1200, $r)), 'cle'));
    }

    public function testPetitFormatEtFondPerdu(): void
    {
        $p = G::placement(1000, 1000, 100, 100, G::ADAPTER, true, 300);
        self::assertEqualsWithDelta(110.0, $p['sortieL'], 0.001);
        self::assertEqualsWithDelta(5.0, $p['dest']['x'], 0.001);
        self::assertSame(254, G::dpiEffectif($p));
        $r = G::reglages(['largeurMm' => 100, 'hauteurMm' => 100, 'mode' => 'adapter', 'dpiSortie' => 300]);
        self::assertSame('attention', G::controles(1000, 1000, $r)[0]['etat']);
        self::assertSame(300, G::dpiSortieConseille(85, 55));
        self::assertSame(100, G::dpiSortieConseille(3000, 1000));
    }

    public function testReglagesFauxRefuses(): void
    {
        $this->expectException(\DomainException::class);
        G::reglages(['largeurMm' => 2, 'hauteurMm' => 100, 'dpiSortie' => 150]);
    }

    public function testContourRectangleVaVersLInterieur(): void
    {
        $source = $this->dossier.'/photo.jpg';
        $im = imagecreatetruecolor(400, 400);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagejpeg($im, $source, 95);
        $avant = hash_file('sha256', $source);

        $r = G::reglages(['largeurMm' => 100, 'hauteurMm' => 100, 'mode' => 'recadrer', 'dpiSortie' => 254, 'contour' => ['actif' => 1, 'type' => 'rectangle', 'couleur' => '#ff0000', 'epaisseurMm' => 1, 'retraitMm' => 0]]);
        $sortie = $this->dossier.'/sortie.png';
        $res = (new GenerateurRetouche())->generer($source, $r, 'png', $sortie, 'essai');
        self::assertSame(1000, $res['largeurPx']);
        $out = imagecreatefrompng($sortie);
        // 1 mm à 254 dpi = 10 px de rouge depuis le bord, puis l'image.
        self::assertSame([255, 0, 0], $this->rvb($out, 0, 500));
        self::assertSame([255, 0, 0], $this->rvb($out, 9, 500));
        self::assertSame([255, 255, 255], $this->rvb($out, 12, 500));
        self::assertSame([254, 254], imageresolution($out));
        self::assertSame($avant, hash_file('sha256', $source), 'L\'original n\'est jamais modifié.');
    }

    public function testContourDeFormeSuitLaTransparence(): void
    {
        $source = $this->dossier.'/logo.png';
        $im = imagecreatetruecolor(200, 200);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 50, 50, 149, 149, imagecolorallocate($im, 0, 0, 255));
        imagepng($im, $source);
        $r = G::reglages(['largeurMm' => 20, 'hauteurMm' => 20, 'mode' => 'adapter', 'fond' => 'transparent', 'dpiSortie' => 254, 'contour' => ['actif' => 1, 'type' => 'forme', 'couleur' => '#000000', 'epaisseurMm' => 0.5, 'source' => 'alpha']]);
        $sortie = $this->dossier.'/logo-sortie.png';
        $res = (new GenerateurRetouche())->generer($source, $r, 'png', $sortie, 'logo');
        self::assertTrue($res['transparence']);
        $out = imagecreatefrompng($sortie);
        self::assertSame(127, (imagecolorat($out, 10, 10) >> 24) & 0x7F, 'Rien n\'est ajouté à l\'extérieur.');
        self::assertSame([0, 0, 0], $this->rvb($out, 52, 100), 'Le trait est juste à l\'intérieur du bord.');
        self::assertSame([0, 0, 255], $this->rvb($out, 100, 100));
    }

    public function testDetourageImpossibleSurFondCharge(): void
    {
        $source = $this->dossier.'/degrade.jpg';
        $im = imagecreatetruecolor(100, 100);
        for ($x = 0; $x < 100; ++$x) {
            imageline($im, $x, 0, $x, 99, imagecolorallocate($im, $x * 2, 255 - $x * 2, 0));
        }
        imagejpeg($im, $source);
        $r = G::reglages(['largeurMm' => 50, 'hauteurMm' => 50, 'mode' => 'recadrer', 'dpiSortie' => 100, 'contour' => ['actif' => 1, 'type' => 'forme', 'epaisseurMm' => 1, 'source' => 'auto']]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/rectangle intérieur/');
        (new GenerateurRetouche())->generer($source, $r, 'png', $this->dossier.'/x.png', 'x');
    }

    public function testPdfALaTaillePhysique(): void
    {
        $source = $this->dossier.'/a.jpg';
        imagejpeg(imagecreatetruecolor(300, 200), $source);
        $r = G::reglages(['largeurMm' => 150, 'hauteurMm' => 100, 'mode' => 'recadrer', 'dpiSortie' => 50, 'fondPerdu' => true]);
        $pdf = $this->dossier.'/a.pdf';
        (new GenerateurRetouche())->generer($source, $r, 'pdf', $pdf, 'CMD-1 (essai)');
        self::assertSame(['largeurMm' => 160.0, 'hauteurMm' => 110.0], PdfImage::lireTaille($pdf));
        self::assertStringContainsString('/TrimBox [14.173 14.173 439.37 297.638]', (string) file_get_contents($pdf));
    }

    public function testNomsDeFichier(): void
    {
        self::assertSame('CMD-D00764_850x2000_v2.pdf', RetoucheImageController::nomParDefaut('CMD-D00764', ['largeurMm' => 850.0, 'hauteurMm' => 2000.0], 2, 'pdf'));
        self::assertSame('mon-fichier.png', RetoucheImageController::nomFichier('mon/fichier.pdf', 'png'));
        self::assertNull(RetoucheImageController::nomFichier(' .. ', 'pdf'));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rvb(\GdImage $im, int $x, int $y): array
    {
        $c = imagecolorat($im, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }
}
