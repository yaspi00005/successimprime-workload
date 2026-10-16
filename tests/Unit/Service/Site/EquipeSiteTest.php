<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\ImageSite;
use App\Entity\MembreEquipe;
use App\Service\Site\ContenuSite;
use App\Service\Site\EquipeDemo;
use App\Service\Site\ImagesSite;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * « Notre équipe » (Réglages › Site vitrine › Équipe) : photo recadrée en
 * carré, sans métadonnées ni original gardé ; rien sur le site sans
 * l'accord de la personne. GD lit et écrit au moins le PNG.
 */
final class EquipeSiteTest extends TestCase
{
    private string $dossier = '';

    /** @var list<object> objets retirés par ContenuSite */
    private array $retires = [];

    protected function setUp(): void
    {
        if (!\function_exists('imagepng') || !ImagesSite::lit(\IMAGETYPE_PNG)) {
            self::markTestSkipped('GD sans PNG');
        }
        $this->dossier = sys_get_temp_dir().'/equipe-site-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
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

    /**
     * PNG plus haut que large : moitié haute rouge, le reste bleu, avec un
     * bloc « eXIf » (position GPS) comme une photo de téléphone.
     */
    private function photo(int $largeur = 600, int $hauteur = 900, string $nom = 'portrait.png'): UploadedFile
    {
        $image = imagecreatetruecolor($largeur, $hauteur);
        imagefilledrectangle($image, 0, 0, $largeur - 1, (int) ($hauteur / 4) - 1, (int) imagecolorallocate($image, 220, 20, 40));
        imagefilledrectangle($image, 0, (int) ($hauteur / 4), $largeur - 1, $hauteur - 1, (int) imagecolorallocate($image, 15, 69, 144));
        ob_start();
        imagepng($image);
        $octets = (string) ob_get_clean();
        $exif = 'Exif GPS 12.6517 -7.9299 secret-du-telephone';
        $octets = substr($octets, 0, 33).pack('N', \strlen($exif)).'eXIf'.$exif.pack('N', crc32('eXIf'.$exif)).substr($octets, 33);
        file_put_contents($this->dossier.'/'.$nom, $octets);

        return new UploadedFile($this->dossier.'/'.$nom, $nom, 'image/png', null, true);
    }

    private function contenu(): ContenuSite
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $objet): void {
            $this->retires[] = $objet;
        });

        return new ContenuSite($em, new ImagesSite($this->dossier.'/public', $this->dossier.'/originaux'));
    }

    public function testUnPortraitEstCarreSansMetadonneesNiOriginal(): void
    {
        $images = new ImagesSite($this->dossier.'/public', $this->dossier.'/originaux');
        $image = $images->depuisEnvoi($this->photo(), ImageSite::USAGE_PORTRAIT);

        self::assertTrue($image->estPortrait());
        self::assertSame([480, 480], [$image->getLargeur(), $image->getHauteur()], 'carré, réduit à 480 px');
        self::assertStringNotContainsString('secret-du-telephone', $image->getOctets(), 'métadonnées retirées au réencodage');
        self::assertStringNotContainsString('eXIf', $image->getOctets());
        self::assertDirectoryDoesNotExist($this->dossier.'/originaux', 'la photo d\'une personne n\'est pas gardée telle qu\'envoyée');

        // Recadrage plutôt vers le haut : le quart haut (rouge) de l'image d'origine reste en haut du carré.
        $gd = imagecreatefromstring($image->getOctets());
        self::assertInstanceOf(\GdImage::class, $gd);
        $haut = imagecolorsforindex($gd, imagecolorat($gd, 240, 100)); // rouge jusqu'à 120 px (centré : 60 px)
        $bas = imagecolorsforindex($gd, imagecolorat($gd, 240, 470));
        self::assertGreaterThan(150, $haut['red'], 'le haut de la photo est gardé');
        self::assertGreaterThan(100, $bas['blue']);

        self::assertSame([240, 240], ImagesSite::dimensions($image, 'petite'));
        self::assertSame([480, 480], ImagesSite::dimensions($image, 'grande'));
        $petite = $images->fichier($image, 'petite');
        self::assertNotNull($petite);
        self::assertSame([240, 240], [$petite['largeur'], $petite['hauteur']]);

        // Une photo ordinaire garde son original hors de public/ (copie de sécurité), pas un portrait.
        $images->depuisEnvoi($this->photo(800, 400, 'atelier.png'));
        self::assertDirectoryExists($this->dossier.'/originaux');
        self::assertCount(1, glob($this->dossier.'/originaux/*') ?: []);
    }

    public function testCarreDUneImageLarge(): void
    {
        $large = imagecreatetruecolor(300, 100);
        $carre = ImagesSite::carre($large);
        self::assertSame([100, 100], [imagesx($carre), imagesy($carre)]);
        $deja = imagecreatetruecolor(50, 50);
        self::assertSame($deja, ImagesSite::carre($deja));
    }

    public function testSansAccordNiPhotoNiSite(): void
    {
        $contenu = $this->contenu();
        $maintenant = new \DateTimeImmutable('2026-10-16 09:00');
        self::assertSame('equipe', ContenuSite::typeDe(new MembreEquipe()));

        // Photo envoyée sans la case d'accord : rien n'est enregistré.
        $membre = new MembreEquipe();
        $retour = $contenu->enregistrer($membre, ['nom' => 'Awa', 'poste' => 'Graphiste PAO', 'publie' => '1'], ['photo' => $this->photo()], $maintenant);
        self::assertCount(1, $retour['erreurs']);
        self::assertStringContainsString('cochez d\'abord « La personne a accepté', $retour['erreurs'][0]);
        self::assertNull($membre->getPhoto());

        // « Visible » sans accord : enregistrée, mais masquée.
        $retour = $contenu->enregistrer($membre, ['nom' => 'Awa', 'poste' => 'Graphiste PAO', 'publie' => '1'], [], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertContains('Enregistré sans être publié sur le site.', $retour['avertissements']);
        self::assertFalse($membre->isVisible());
        self::assertFalse($membre->estVisible());

        // Avec l'accord : photo carrée, texte pour les malvoyants, visible.
        $retour = $contenu->enregistrer($membre, ['nom' => 'Awa', 'poste' => 'Graphiste PAO', 'ordre' => '3', 'publie' => '1', 'accordPersonne' => '1'], ['photo' => $this->photo()], $maintenant);
        self::assertSame(['erreurs' => [], 'avertissements' => []], $retour);
        self::assertTrue($membre->estVisible());
        $photo = $membre->getPhoto();
        self::assertNotNull($photo);
        self::assertTrue($photo->estPortrait());
        self::assertSame('Photo de Awa, Graphiste PAO', $photo->getTexteAlternatif());
        self::assertSame([$photo], ContenuSite::imagesDe($membre), 'supprimée avec la personne');

        // Accord retiré : la photo est retirée (ici et, par la synchronisation, du site), la personne masquée.
        $retour = $contenu->enregistrer($membre, ['nom' => 'Awa', 'poste' => 'Graphiste PAO', 'publie' => '1'], [], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertNull($membre->getPhoto());
        self::assertContains($photo, $this->retires);
        self::assertFalse($membre->estVisible());
        self::assertStringContainsString('sa photo n\'est pas gardée', implode(' ', $retour['avertissements']));
    }

    public function testNomEtPosteObligatoires(): void
    {
        $retour = $this->contenu()->enregistrer(new MembreEquipe(), ['nom' => ' ', 'poste' => '', 'accordPersonne' => '1'], [], new \DateTimeImmutable());
        self::assertCount(2, $retour['erreurs']);
    }

    public function testEquipeDeDemonstration(): void
    {
        $personnes = EquipeDemo::personnes();
        self::assertGreaterThanOrEqual(5, \count($personnes));
        self::assertLessThanOrEqual(6, \count($personnes));
        $sansAccord = array_values(array_filter($personnes, static fn (array $p): bool => !$p['accord']));
        self::assertCount(1, $sansAccord, 'une personne sans accord, pour montrer qu\'elle reste cachée');
        self::assertTrue($sansAccord[0]['visible'], 'même avec « Visible » coché');
        self::assertNull($sansAccord[0]['avatar'], 'pas de photo sans accord');
        self::assertNotSame([], array_filter($personnes, static fn (array $p): bool => null === $p['avatar'] && $p['accord']), 'au moins une personne montrée avec ses initiales');
        foreach ($personnes as $p) {
            self::assertNotSame('', trim($p['poste']), $p['nom']);
            self::assertLessThanOrEqual(MembreEquipe::NOM_MAX, mb_strlen($p['nom']));
        }
        self::assertSame(900001, EquipeDemo::PREMIER_ID);
        foreach (EquipeDemo::PURGE as $sql) {
            self::assertStringContainsString('>= 900000', $sql, 'la purge ne touche que la démonstration');
        }

        // Avatar dessiné, réencodé comme une photo envoyée.
        $avatar = EquipeDemo::image(['teinte' => 'bleu', 'peau' => [122, 78, 48], 'coiffure' => 'foulard']);
        self::assertTrue($avatar->estPortrait());
        self::assertSame([480, 480], [$avatar->getLargeur(), $avatar->getHauteur()]);
        ImageSite::verifierOctets($avatar->getOctets(), $avatar->getType());
    }
}
