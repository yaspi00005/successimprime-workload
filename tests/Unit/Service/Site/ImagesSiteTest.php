<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\ImageSite;
use App\Service\Site\ImagesSite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Images du site vitrine. Seul le PNG est supposé lu et écrit par GD
 * (le conteneur de développement n'a ni WebP ni JPEG) : la sortie est
 * du WebP là où GD sait l'écrire, du PNG sinon.
 */
final class ImagesSiteTest extends TestCase
{
    private string $dossier = '';

    protected function setUp(): void
    {
        if (!\function_exists('imagepng') || !ImagesSite::lit(\IMAGETYPE_PNG)) {
            self::markTestSkipped('GD sans PNG');
        }
    }

    protected function tearDown(): void
    {
        if ('' !== $this->dossier && is_dir($this->dossier)) {
            $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($fichiers as $fichier) {
                $fichier->isDir() ? rmdir($fichier->getPathname()) : unlink($fichier->getPathname());
            }
            rmdir($this->dossier);
        }
    }

    /** PNG généré ici, avec un bloc de métadonnées (comme l'EXIF d'une photo) après l'en-tête. */
    private static function png(int $largeur, int $hauteur, string $metadonnees = ''): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        for ($x = 0; $x < $largeur; $x += 10) {
            imagefilledrectangle($image, $x, 0, $x + 9, $hauteur - 1, (int) imagecolorallocate($image, ($x * 7) % 256, ($x * 3) % 256, 120));
        }
        ob_start();
        imagepng($image);
        $octets = (string) ob_get_clean();
        if ('' === $metadonnees) {
            return $octets;
        }
        // Bloc « eXIf » glissé après IHDR (8 octets de signature + 25 octets d'IHDR).
        $bloc = pack('N', \strlen($metadonnees)).'eXIf'.$metadonnees.pack('N', crc32('eXIf'.$metadonnees));

        return substr($octets, 0, 33).$bloc.substr($octets, 33);
    }

    public function testControleDesOctets(): void
    {
        ImageSite::verifierOctets(self::png(40, 30), 'image/png');
        $refus = [
            ['', 'image/png'],
            ['<?php echo 1;', 'image/png'],
            [self::png(40, 30), 'image/jpeg'],
            [self::png(40, 30), 'image/webp'],
            ["\x89PNG\r\n\x1A\n".str_repeat('x', ImageSite::TAILLE_MAX), 'image/png'],
            [self::png(ImageSite::COTE_MAX + 1, 2), 'image/png'],
        ];
        foreach ($refus as $i => [$octets, $type]) {
            try {
                ImageSite::verifierOctets($octets, $type);
                self::fail('refus attendu (cas '.$i.')');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testUneImageGardeSonEmpreinte(): void
    {
        $octets = self::png(40, 30);
        $image = ImageSite::depuis($octets, 'image/png', 40, 30, 'inconnu');
        self::assertSame(ImageSite::USAGE_PHOTO, $image->getUsage());
        self::assertSame($octets, $image->getOctets());
        self::assertSame(hash('sha256', $octets), $image->getEmpreinte());
    }

    public function testReencodeeReduiteEtSansMetadonnees(): void
    {
        $original = self::png(1800, 900, 'Exif GPS 12.6392 -8.0029 secret');
        self::assertStringContainsString('secret', $original);

        [$octets, $type, $largeur, $hauteur] = ImagesSite::preparer($original, 1200);
        self::assertSame([1200, 600], [$largeur, $hauteur]);
        self::assertSame(ImagesSite::ecritWebp() ? 'image/webp' : 'image/png', $type);
        self::assertStringNotContainsString('secret', $octets, 'métadonnées retirées au réencodage');
        self::assertLessThanOrEqual(ImageSite::TAILLE_MAX, \strlen($octets));
        ImageSite::verifierOctets($octets, $type);

        // Plus petite que la largeur demandée : jamais agrandie.
        self::assertSame([300, 200], \array_slice(ImagesSite::preparer(self::png(300, 200), 1200), 2));
    }

    public function testCeQuiNEstPasUneImageEstRefuse(): void
    {
        foreach (['', 'GIF89a', '<svg xmlns="http://www.w3.org/2000/svg"/>', '%PDF-1.4'] as $octets) {
            try {
                ImagesSite::preparer($octets, 1200);
                self::fail('refus attendu');
            } catch (\DomainException $e) {
                self::assertStringContainsString('JPG, PNG ou WebP', $e->getMessage());
            }
        }
    }

    public function testFormatsLusSelonGd(): void
    {
        $gd = gd_info();
        self::assertSame((bool) ($gd['JPEG Support'] ?? false), ImagesSite::lit(\IMAGETYPE_JPEG));
        self::assertSame((bool) ($gd['WebP Support'] ?? false), ImagesSite::lit(\IMAGETYPE_WEBP));
        self::assertFalse(ImagesSite::lit(\IMAGETYPE_GIF));
    }

    public function testEnvoiOriginalHorsDuPublicEtFichiersRefaits(): void
    {
        $this->dossier = sys_get_temp_dir().'/images-site-'.bin2hex(random_bytes(4));
        $public = $this->dossier.'/public';
        $originaux = $this->dossier.'/originaux';
        mkdir($this->dossier);
        file_put_contents($this->dossier.'/photo.png', self::png(1600, 800));
        $images = new ImagesSite($public, $originaux);

        $image = $images->depuisEnvoi(new UploadedFile($this->dossier.'/photo.png', 'photo.png', 'image/png', null, true));
        self::assertSame([1200, 600], [$image->getLargeur(), $image->getHauteur()]);
        self::assertFileExists($originaux.'/'.$image->getPublicId()->toRfc4122().'.png');

        $petite = $images->fichier($image, 'petite');
        self::assertNotNull($petite);
        self::assertSame([480, 240], [$petite['largeur'], $petite['hauteur']]);
        self::assertStringStartsWith('uploads/site/'.$image->getPublicId()->toRfc4122().'-', $petite['chemin']);
        self::assertFileExists($public.'/'.basename($petite['chemin']));
        self::assertSame([600, 300], ImagesSite::dimensions(ImageSite::depuis($image->getOctets(), $image->getType(), 1200, 600, ImageSite::USAGE_LOGO), 'grande'));

        $images->nettoyer($image, true);
        self::assertFileDoesNotExist($public.'/'.basename($petite['chemin']));
        self::assertFileDoesNotExist($originaux.'/'.$image->getPublicId()->toRfc4122().'.png');
    }

    public function testUnEnvoiIllisibleDonneUnMessageClair(): void
    {
        $this->dossier = sys_get_temp_dir().'/images-site-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
        file_put_contents($this->dossier.'/faux.jpg', 'pas une image');
        $images = new ImagesSite($this->dossier.'/public', $this->dossier.'/originaux');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('« faux.jpg » : ce n\'est pas une image JPG, PNG ou WebP.');
        $images->depuisEnvoi(new UploadedFile($this->dossier.'/faux.jpg', 'faux.jpg', 'image/jpeg', null, true));
    }
}
