<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Service\AnalyseFichierImpression;
use App\Service\Atelier\ImagesAtelier;
use PHPUnit\Framework\TestCase;

/** Images du client dans l'Atelier 3D : contrôlées et réencodées côté serveur (docs/atelier-3d.md). */
final class ImagesAtelierTest extends TestCase
{
    public function testResolutionALaTailleDImpression(): void
    {
        // T-shirt : zone 28 × 35 cm, image à 60 % avec un texte (49 %).
        $ok = ImagesAtelier::controleResolution(2400, 2400, 28.0, 35.0, 49);
        self::assertSame(13.7, $ok['largeurCm']);
        self::assertSame(AnalyseFichierImpression::OK, $ok['etat']);
        self::assertNull($ok['message']);

        $juste = ImagesAtelier::controleResolution(600, 600, 28.0, 35.0, 49);
        self::assertSame(AnalyseFichierImpression::ATTENTION, $juste['etat']);
        self::assertSame(111, $juste['dpi']);

        $flou = ImagesAtelier::controleResolution(300, 300, 28.0, 35.0, 49);
        self::assertSame(AnalyseFichierImpression::ERREUR, $flou['etat']);
        self::assertStringContainsString('au moins 811 px', (string) $flou['message']);

        // Une bannière très large tient dans la largeur de la zone, pas dans sa hauteur.
        $large = ImagesAtelier::controleResolution(3000, 500, 28.0, 35.0, 100);
        self::assertSame([28.0, 4.7], [$large['largeurCm'], $large['hauteurCm']]);
    }

    public function testPngReencodeEtReduit(): void
    {
        $image = imagecreatetruecolor(ImagesAtelier::COTE_MAX + 1000, 60);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        // Un bloc de texte (auteur, logiciel…) glissé avant les données.
        $texte = 'tEXtAuthor'."\0".'Moussa Traore';
        $chunk = pack('N', \strlen($texte) - 4).$texte.pack('N', crc32($texte));
        $png = substr($png, 0, 33).$chunk.substr($png, 33);

        $r = ImagesAtelier::reencoder($png);
        self::assertSame('image/png', $r['mime']);
        self::assertSame(ImagesAtelier::COTE_MAX, $r['largeur']);
        self::assertStringNotContainsString('Moussa', $r['octets']);
        self::assertSame(\IMAGETYPE_PNG, getimagesizefromstring($r['octets'])[2]);
    }

    public function testPhotoJpegSansSesMetadonnees(): void
    {
        $segment = static fn (int $m, string $d): string => "\xFF".\chr($m).pack('n', \strlen($d) + 2).$d;
        // EXIF : orientation 6 (photo prise de côté) et une position.
        $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVV', 0x0112, 3, 1, 6).pack('V', 0);
        $exif = $segment(0xE1, "Exif\0\0".$tiff.'GPS 12.65N 8.00W');
        if ((bool) (gd_info()['JPEG Support'] ?? false)) {
            $im = imagecreatetruecolor(40, 20);
            ob_start();
            imagejpeg($im);
            $base = (string) ob_get_clean();
        } else {
            // Serveur sans JPEG dans GD : un JPEG minimal (dimensions lisibles), nettoyé sans être décodé.
            $base = "\xFF\xD8".$segment(0xDB, str_repeat("\x01", 65)).$segment(0xC0, "\x08".pack('nn', 20, 40)."\x01\x01\x11\x00")."\xFF\xDA".pack('n', 4)."\0\0DONNEES\xFF\xD9";
        }
        $r = ImagesAtelier::reencoder("\xFF\xD8".$exif.substr($base, 2));

        self::assertSame('image/jpeg', $r['mime']);
        self::assertStringNotContainsString('Exif', $r['octets']);
        self::assertStringNotContainsString('GPS', $r['octets']);
        self::assertContains([$r['largeur'], $r['hauteur']], [[40, 20], [20, 40]], 'remise droite quand EXIF est lisible');
    }

    public function testFauxFichierRefuse(): void
    {
        foreach (['<?php echo 1;', '', 'GIF89a'.str_repeat("\0", 20)] as $octets) {
            try {
                ImagesAtelier::reencoder($octets);
                self::fail('refus attendu');
            } catch (\DomainException $e) {
                self::assertStringContainsString('PNG, JPG ou WebP', $e->getMessage());
            }
        }
    }

    public function testVueRenduePngSeulementEtDeTailleRaisonnable(): void
    {
        $im = imagecreatetruecolor(64, 64);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        self::assertSame(64, ImagesAtelier::lireRendu($png)['largeur']);

        $this->expectException(\DomainException::class);
        ImagesAtelier::lireRendu(str_repeat('a', 100));
    }
}
