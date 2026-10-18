<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\ImageSite;
use App\Entity\MembreEquipe;
use App\Service\Site\ContenuSite;
use App\Service\Site\EquipeDemo;
use App\Service\Site\ImagesSite;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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

    /** @param list<MembreEquipe> $gerants gérants déjà enregistrés */
    private function contenu(array $gerants = []): ContenuSite
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $objet): void {
            $this->retires[] = $objet;
        });
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findBy')->willReturn($gerants);
        $em->method('getRepository')->willReturn($depot);

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

    public function testLeMotDuGerant(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-17 15:40');
        $ancien = (new MembreEquipe())->setNom('Moussa')->setPoste('Gérant')->setGerant(true)->setMotGerant('Ancien mot.');
        $contenu = $this->contenu([$ancien]);

        // « Rédiger le mot du gérant » : la fiche arrive cochée « Gérant ».
        $gerant = $contenu->nouveau('equipe', null, true);
        self::assertInstanceOf(MembreEquipe::class, $gerant);
        self::assertTrue($gerant->isGerant());
        self::assertFalse($contenu->nouveau('equipe')->isGerant());

        // Trop long : refusé, rien n'est enregistré.
        $champs = ['nom' => 'Kadiatou T.', 'poste' => 'Gérante', 'publie' => '1', 'accordPersonne' => '1', 'gerant' => '1'];
        $retour = $contenu->enregistrer($gerant, $champs + ['motGerant' => str_repeat('a', MembreEquipe::MOT_MAX + 1)], [], $maintenant);
        self::assertCount(1, $retour['erreurs']);
        self::assertStringContainsString('900 au plus', $retour['erreurs'][0]);

        // Texte sans photo : enregistré, visible dans l'équipe, mais le mot reste caché.
        $retour = $contenu->enregistrer($gerant, $champs + ['motGerant' => "Bienvenue chez Succès Imprim.\r\nUn BAT avant chaque impression."], [], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertTrue($gerant->estVisible());
        self::assertFalse($gerant->estMotVisible());
        self::assertContains('Le mot du gérant reste caché sur le site : il manque la photo.', $retour['avertissements']);
        // Un seul gérant : l'ancien est décoché, son mot gardé.
        self::assertFalse($ancien->isGerant());
        self::assertSame('Ancien mot.', $ancien->getMotGerant());
        self::assertContains('Moussa n\'est plus indiqué comme gérant : un seul mot du gérant paraît sur le site.', $retour['avertissements']);

        // Avec la photo (mêmes règles que l'équipe : carrée, sans métadonnées) : le mot paraît.
        $retour = $contenu->enregistrer($gerant, $champs + ['motGerant' => $gerant->getMotGerant()], ['photo' => $this->photo()], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertTrue($gerant->estMotVisible());
        self::assertStringNotContainsString('secret-du-telephone', (string) $gerant->getPhoto()?->getOctets());
        self::assertSame(['Bienvenue chez Succès Imprim.', 'Un BAT avant chaque impression.'], $gerant->getParagraphesMot());
        self::assertFalse($gerant->isAussiDansEquipe());

        // Accord retiré : photo retirée, mot caché (et parti vide au site).
        $photo = $gerant->getPhoto();
        $retour = $contenu->enregistrer($gerant, ['nom' => 'Kadiatou T.', 'poste' => 'Gérante', 'publie' => '1', 'gerant' => '1', 'motGerant' => 'Bienvenue.'], [], $maintenant);
        self::assertContains($photo, $this->retires);
        self::assertFalse($gerant->estMotVisible());
        self::assertNull(MembreEquipe::motPublic($gerant->getMotGerant(), $gerant));

        // Décocher « Gérant » : plus de mot sur le site, ni d'avertissement à son sujet.
        $retour = $contenu->enregistrer($gerant, $champs + ['motGerant' => 'Bienvenue.'], [], $maintenant);
        self::assertContains('Le mot du gérant reste caché sur le site : il manque la photo.', $retour['avertissements']);
        $retour = $contenu->enregistrer($gerant, ['nom' => 'Kadiatou T.', 'poste' => 'Gérante', 'publie' => '1', 'accordPersonne' => '1', 'motGerant' => 'Bienvenue.', 'aussiDansEquipe' => '1'], [], $maintenant);
        self::assertSame(['erreurs' => [], 'avertissements' => []], $retour);
        self::assertFalse($gerant->isGerant());
        self::assertTrue($gerant->isAussiDansEquipe());
    }

    public function testPasDeMontantDansLeMotNiLePoste(): void
    {
        $contenu = $this->contenu();
        $maintenant = new \DateTimeImmutable('2026-10-18 09:00');
        $champs = ['nom' => 'Kadiatou T.', 'poste' => 'Gérante', 'publie' => '1', 'accordPersonne' => '1', 'gerant' => '1'];

        foreach (['Banderoles à partir de 5 000 F le mètre.', "Bienvenue.\nFlyers dès 25 000 FCFA.", 'Un roll-up pour 45.000 F CFA !', 'Cartes à 10 000 francs.', 'Moins de 15 €.', 'Le mètre à 5000 fr seulement.', 'Dès 2 500F.'] as $mot) {
            $gerant = $contenu->nouveau('equipe', null, true);
            $retour = $contenu->enregistrer($gerant, $champs + ['motGerant' => $mot], [], $maintenant);
            self::assertCount(1, $retour['erreurs'], $mot);
            self::assertStringContainsString('Pas de prix ni de montant', $retour['erreurs'][0], $mot);
        }
        $retour = $contenu->enregistrer(new MembreEquipe(), ['nom' => 'Awa', 'poste' => 'Caisse, 2 000 F de fond', 'accordPersonne' => '1'], [], $maintenant);
        self::assertStringContainsString('retirez « 2 000 F »', implode(' ', $retour['erreurs']), 'le poste aussi');

        // Une année, un pourcentage, des formats ou un numéro ne sont pas des montants.
        $gerant = $contenu->nouveau('equipe', null, true);
        $retour = $contenu->enregistrer($gerant, $champs + ['motGerant' => "Depuis 2015, Fatou et l'équipe impriment 7j/7 : 100 % des BAT validés, 2 formats A3 et A4, au +223 70 00 00 00. EquipeDemo : 3 fois merci.\nEn 2010, F. Traoré ouvrait l'atelier avec 2 fruitiers devant la porte."], [], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertNull((new MembreEquipe())->setMotGerant(EquipeDemo::MOT)->montantEcrit());
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
        $gerants = array_values(array_filter($personnes, static fn (array $p): bool => isset($p['mot'])));
        self::assertCount(1, $gerants, 'une gérante, avec son mot');
        self::assertTrue($gerants[0]['accord'] && $gerants[0]['visible'] && null !== $gerants[0]['avatar'], 'son mot paraît : photo, accord, visible');
        self::assertLessThanOrEqual(MembreEquipe::MOT_MAX, mb_strlen(EquipeDemo::MOT));
        self::assertDoesNotMatchRegularExpression('/\d|FCFA|CFA|€/u', EquipeDemo::MOT, 'aucun montant');
        self::assertSame('Le mot de la gérante', (new MembreEquipe())->setPoste($gerants[0]['poste'])->getTitreMot());

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
