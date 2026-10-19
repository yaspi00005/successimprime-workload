<?php

namespace App\Tests\Unit\Service;

use App\Service\FichiersPrives;
use App\Service\PiecesJointesChat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Pièces jointes du chat (audit D3 et D9) : rangées hors de public/, et
 * photos réencodées sans leurs données EXIF (position GPS du téléphone).
 */
final class PiecesJointesChatTest extends TestCase
{
    private string $projet;
    private PiecesJointesChat $pieces;

    protected function setUp(): void
    {
        $this->projet = sys_get_temp_dir().'/si-chat-'.bin2hex(random_bytes(4));
        mkdir($this->projet.'/envois', 0777, true);
        $this->pieces = new PiecesJointesChat(new FichiersPrives($this->projet));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->projet));
    }

    private function envoi(string $contenu, string $nom): UploadedFile
    {
        $chemin = $this->projet.'/envois/'.bin2hex(random_bytes(4));
        file_put_contents($chemin, $contenu);

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    /** Segment JPEG (marqueur + longueur + données). */
    private static function segment(int $marqueur, string $donnees): string
    {
        return "\xFF".\chr($marqueur).pack('n', \strlen($donnees) + 2).$donnees;
    }

    /**
     * EXIF comme celui d'un téléphone : appareil, et bloc GPS avec la
     * latitude et la longitude de Bamako (12°39' N, 8°0' O).
     */
    private static function exifAvecPosition(): string
    {
        // TIFF petit-boutiste : IFD0 à 8 (2 entrées), bloc GPS juste après, puis les valeurs.
        $gpsA = 8 + 2 + 2 * 12 + 4;          // 0x26
        $marque = $gpsA + 2 + 4 * 12 + 4;    // 0x5C
        $latitude = $marque + 8;
        $longitude = $latitude + 24;
        $ifd0 = pack('v', 2)
            .pack('vvVV', 0x010F, 2, 8, $marque)     // Make
            .pack('vvVV', 0x8825, 4, 1, $gpsA)       // GPSInfo
            .pack('V', 0);
        $gps = pack('v', 4)
            .pack('vvV', 0x0001, 2, 2).'N'."\0\0\0"  // GPSLatitudeRef
            .pack('vvVV', 0x0002, 5, 3, $latitude)   // GPSLatitude (3 rationnels)
            .pack('vvV', 0x0003, 2, 2).'W'."\0\0\0"  // GPSLongitudeRef
            .pack('vvVV', 0x0004, 5, 3, $longitude)  // GPSLongitude
            .pack('V', 0);
        $tiff = "II*\0".pack('V', 8).$ifd0.$gps.'Moussa'."\0\0".pack('V6', 12, 1, 39, 1, 0, 1).pack('V6', 8, 1, 0, 1, 0, 1);

        return self::segment(0xE1, "Exif\0\0".$tiff);
    }

    public function testPhotoJpegRangeeHorsDePublicSansExifNiPosition(): void
    {
        if ((bool) (gd_info()['JPEG Support'] ?? false)) {
            $image = imagecreatetruecolor(40, 20);
            ob_start();
            imagejpeg($image);
            $jpeg = (string) ob_get_clean();
        } else {
            // Serveur sans JPEG dans GD : un JPEG minimal, nettoyé sans être décodé.
            $jpeg = "\xFF\xD8".self::segment(0xDB, str_repeat("\x01", 65)).self::segment(0xC0, "\x08".pack('nn', 20, 40)."\x01\x01\x11\x00")."\xFF\xDA".pack('n', 4)."\0\0DONNEES\xFF\xD9";
        }
        $avecExif = "\xFF\xD8".self::exifAvecPosition().substr($jpeg, 2);
        self::assertStringContainsString("\xFF\xE1", $avecExif);
        if (\function_exists('exif_read_data')) {
            self::assertSame('N', (exif_read_data('data://image/jpeg;base64,'.base64_encode($avecExif)) ?: [])['GPSLatitudeRef'] ?? null, 'la photo envoyée a bien une position');
        }

        $range = $this->pieces->ranger($this->envoi($avecExif, 'IMG_2026.jpeg'));

        self::assertMatchesRegularExpression('/^chat_[0-9a-f]{24}\.jpg$/', $range['fichier']);
        self::assertSame('IMG_2026.jpeg', $range['nomOriginal']);
        $chemin = $this->projet.'/var/uploads/chat/'.$range['fichier'];
        self::assertFileExists($chemin);
        self::assertDirectoryDoesNotExist($this->projet.'/public/uploads/chat', 'rien sous public/');
        $octets = (string) file_get_contents($chemin);
        self::assertStringStartsWith("\xFF\xD8", $octets);
        self::assertStringNotContainsString("\xFF\xE1", $octets, 'plus de segment EXIF');
        self::assertStringNotContainsString('Exif', $octets);
        self::assertStringNotContainsString('Moussa', $octets);
        self::assertSame(\IMAGETYPE_JPEG, getimagesizefromstring($octets)[2]);
        if (\function_exists('exif_read_data')) {
            self::assertArrayNotHasKey('GPSLatitudeRef', (array) @exif_read_data($chemin));
        }

        self::assertSame(realpath($chemin), $this->pieces->chemin($range['fichier']));
        self::assertNull($this->pieces->chemin('../'.$range['fichier']), 'jamais hors du dossier');
    }

    public function testCaptureEnPngSansSesTextesCaches(): void
    {
        $image = imagecreatetruecolor(30, 30);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        $texte = 'tEXtAuthor'."\0".'Moussa Traore, Bamako';
        $png = substr($png, 0, 33).pack('N', \strlen($texte) - 4).$texte.pack('N', crc32($texte)).substr($png, 33);

        $range = $this->pieces->ranger($this->envoi($png, 'capture.png'));

        self::assertStringEndsWith('.png', $range['fichier']);
        self::assertStringNotContainsString('Moussa', (string) file_get_contents($this->projet.'/var/uploads/chat/'.$range['fichier']));
    }

    public function testWebpReencodeEnPngOuRefuseSiLeServeurNeLeLitPas(): void
    {
        $webp = (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
        if ((bool) (gd_info()['WebP Support'] ?? false)) {
            $range = $this->pieces->ranger($this->envoi($webp, 'photo.webp'));
            self::assertStringEndsWith('.png', $range['fichier']);
            self::assertSame('photo.png', $range['nomOriginal']);

            return;
        }
        // Jamais gardé tel quel : un WebP peut porter de l'EXIF.
        $this->expectException(\DomainException::class);
        $this->pieces->ranger($this->envoi($webp, 'photo.webp'));
    }

    public function testDocumentsGardesPagesEtScriptsRefuses(): void
    {
        $pdf = $this->pieces->ranger($this->envoi("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'devis/../../client.pdf'));
        self::assertStringEndsWith('.pdf', $pdf['fichier']);
        self::assertSame('client.pdf', $pdf['nomOriginal'], 'nom sans chemin');
        self::assertFileExists($this->projet.'/var/uploads/chat/'.$pdf['fichier']);

        foreach ([
            ['<!doctype html><html><body><script>alert(1)</script></body></html>', 'facture.pdf'],
            ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png'],
            ['<?php system($_GET["c"]);', 'photo.jpg'],
        ] as [$contenu, $nom]) {
            try {
                $this->pieces->ranger($this->envoi($contenu, $nom));
                self::fail($nom.' accepté');
            } catch (\DomainException $e) {
                self::assertSame(PiecesJointesChat::REFUS, $e->getMessage());
            }
        }
        self::assertCount(1, glob($this->projet.'/var/uploads/chat/*') ?: []);
    }
}
