<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Service\Prepresse\ConversionPdf;
use PHPUnit\Framework\TestCase;

/**
 * Conversion d'un PDF en image (docs/prepresse-retouche.md) : ce que le
 * serveur vérifie sur l'image rendue par le navigateur avant de la ranger.
 */
final class ConversionPdfTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/conversion-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dossier.'/*') ?: []);
        rmdir($this->dossier);
    }

    public function testAccepteUnPngEtUnJpeg(): void
    {
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, $this->dossier.'/a.png');
        imagejpeg($im, $this->dossier.'/a.jpg');

        self::assertSame(['type' => 'image/png', 'extension' => 'png', 'largeur' => 40, 'hauteur' => 30], ConversionPdf::verifierImage($this->dossier.'/a.png'));
        self::assertSame(['type' => 'image/jpeg', 'extension' => 'jpg', 'largeur' => 40, 'hauteur' => 30], ConversionPdf::verifierImage($this->dossier.'/a.jpg'));
    }

    public function testRefuseUnFichierQuiNEstPasUneImage(): void
    {
        // Un PDF (ou un script) renommé en .png : le type est lu dans le contenu.
        file_put_contents($this->dossier.'/faux.png', "%PDF-1.4\n1 0 obj << >> endobj\n%%EOF");
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('n\'est pas une image PNG ou JPEG');
        ConversionPdf::verifierImage($this->dossier.'/faux.png');
    }

    public function testRefuseLesAutresTypesDImage(): void
    {
        $im = imagecreatetruecolor(10, 10);
        imagegif($im, $this->dossier.'/a.gif');
        $this->expectException(\DomainException::class);
        ConversionPdf::verifierImage($this->dossier.'/a.gif');
    }

    public function testRefuseUnFichierVideOuTropLourd(): void
    {
        touch($this->dossier.'/vide.png');
        try {
            ConversionPdf::verifierImage($this->dossier.'/vide.png');
            self::fail('Un fichier vide doit être refusé.');
        } catch (\DomainException $e) {
            self::assertSame('Aucune image reçue.', $e->getMessage());
        }

        $im = imagecreatetruecolor(10, 10);
        imagepng($im, $this->dossier.'/a.png');
        $this->expectExceptionMessage('Image trop lourde');
        ConversionPdf::verifierImage($this->dossier.'/a.png', ConversionPdf::TAILLE_MAX + 1);
    }

    public function testRefuseUneImageAuDelaDesLimitesDuNavigateur(): void
    {
        // En-tête PNG seul : getimagesize lit les dimensions sans décoder l'image.
        $png = static function (int $l, int $h): string {
            $ihdr = 'IHDR'.pack('NNCCCCC', $l, $h, 8, 2, 0, 0, 0);

            return "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
        };
        file_put_contents($this->dossier.'/cote.png', $png(ConversionPdf::COTE_MAX_PX + 1, 100));
        file_put_contents($this->dossier.'/surface.png', $png(12_000, 11_000));
        file_put_contents($this->dossier.'/limite.png', $png(ConversionPdf::COTE_MAX_PX, 7_500));

        foreach (['cote', 'surface'] as $nom) {
            try {
                ConversionPdf::verifierImage($this->dossier.'/'.$nom.'.png');
                self::fail($nom.' : image trop grande acceptée.');
            } catch (\DomainException $e) {
                self::assertStringContainsString('Image trop grande', $e->getMessage());
            }
        }
        self::assertSame(7_500, ConversionPdf::verifierImage($this->dossier.'/limite.png')['hauteur']);
    }

    public function testNomParDefaut(): void
    {
        self::assertSame('affiche-Mme-Diarra_150dpi.png', ConversionPdf::nomParDefaut('affiche Mme Diarra.pdf', 1, 1, 150, 'png'));
        self::assertSame('depliant_p2_300dpi.jpg', ConversionPdf::nomParDefaut('depliant.PDF', 2, 3, 300, 'jpg'));
        self::assertSame('bâche-école_100dpi.png', ConversionPdf::nomParDefaut('bâche école.pdf', 1, 1, 100, 'png'));
        self::assertSame('conversion_72dpi.png', ConversionPdf::nomParDefaut('***.pdf', 1, 1, 72, 'png'));
    }

    public function testReglagesBornes(): void
    {
        $r = ConversionPdf::reglages(['page' => '9', 'pages' => '3', 'dpi' => '99999', 'echelle' => '7', 'fondPerdu' => '1', 'largeurMm' => '120.04', 'hauteurMm' => 'x']);
        self::assertSame(['page' => 3, 'pages' => 3, 'dpi' => ConversionPdf::DPI_MAX, 'echelle' => 1, 'fondPerdu' => true, 'largeurMm' => 120.0, 'hauteurMm' => null], $r);
        self::assertSame(10, ConversionPdf::reglages(['echelle' => 10])['echelle']);
        self::assertSame(ConversionPdf::DPI_MIN, ConversionPdf::reglages([])['dpi']);
    }

    public function testObservationAvecPageEtResolution(): void
    {
        $r = ConversionPdf::reglages(['page' => 2, 'pages' => 2, 'dpi' => 150, 'echelle' => 10, 'largeurMm' => 120, 'hauteurMm' => 200]);
        self::assertSame(
            'Conversion PDF : page 2 sur 2 · 150 dpi à la taille d\'impression (PDF au 1/10, agrandi ×10) · 120 × 200 cm · 7 087 × 11 811 px · JPEG, RVB. PDF d\'origine conservé : bache.pdf.',
            ConversionPdf::observation('bache.pdf', $r, 7087, 11811, 'jpg'),
        );
    }
}
