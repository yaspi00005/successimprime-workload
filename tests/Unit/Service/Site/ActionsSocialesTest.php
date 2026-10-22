<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\ActionSociale;
use App\Entity\ImageSite;
use App\Repository\ActionSocialeRepository;
use App\Repository\AppareilAutoriseRepository;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Service\Site\ActionsSociales;
use App\Service\Site\ContenuSite;
use App\Service\Site\ImagesSite;
use App\Twig\SiteExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * « Nos actions sociales » (docs/site-vitrine.md) : saisie dans Réglages ›
 * Site vitrine, photos réencodées sans EXIF ni GPS et sans original gardé,
 * partie invisible (pied de page, plan du site) tant qu'aucune action n'est
 * montrée.
 */
final class ActionsSocialesTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/si-actions-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (['/originaux', '/public', ''] as $sous) {
            array_map('unlink', glob($this->dossier.$sous.'/*.*') ?: []);
            @rmdir($this->dossier.$sous);
        }
    }

    public function testSaisieEtPublicationDUneAction(): void
    {
        $contenu = $this->contenu();
        $maintenant = new \DateTimeImmutable('2026-10-18 09:00');
        self::assertSame('actions', ContenuSite::typeDe(new ActionSociale()));

        $action = new ActionSociale();
        $retour = $contenu->enregistrer($action, [
            'titre' => 'Fournitures scolaires', 'dateAction' => '2026-10-05', 'lieu' => 'Sébénikoro', 'texte' => 'Remise de cahiers.', 'ordre' => '2', 'publie' => '1',
        ], [], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertSame(['Pour paraître sur le site, il manque une photo.', 'Enregistré sans être publié sur le site.'], $retour['avertissements']);
        self::assertFalse($action->isPublie(), 'sans photo, la case « publié » ne suffit pas');
        self::assertSame(['2026-10-05', 'Sébénikoro', 2], [$action->getDateAction()->format('Y-m-d'), $action->getLieu(), $action->getOrdre()]);
        self::assertEquals($maintenant, $action->getModifieLe(), 'la date de modification part avec la synchronisation');

        // Avec une photo prise au téléphone (EXIF et position GPS) : publiée, photo réencodée sans rien de tout cela.
        $retour = $contenu->enregistrer($action, ['titre' => 'Fournitures scolaires', 'texte' => 'Remise de cahiers.', 'publie' => '1'], ['images' => [$this->envoi(self::pngAvecExif())]], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertSame([], $retour['avertissements']);
        self::assertTrue($action->estVisible());
        $photo = $action->getImagePrincipale();
        self::assertInstanceOf(ImageSite::class, $photo);
        self::assertStringNotContainsString('eXIf', $photo->getOctets());
        self::assertStringNotContainsString('Appareil-essai', $photo->getOctets());
        self::assertSame('Fournitures scolaires', $photo->getTexteAlternatif(), 'description par défaut : le titre');
        self::assertSame([], glob($this->dossier.'/originaux/*') ?: [], 'la photo envoyée n\'est pas gardée telle quelle');

        // Titre vide : refusé, rien de changé.
        $retour = $contenu->enregistrer(new ActionSociale(), ['titre' => ' ', 'texte' => 'x', 'dateAction' => '2026-02-31'], [], $maintenant);
        self::assertContains('Le titre est obligatoire.', $retour['erreurs']);
        self::assertCount(2, $retour['erreurs'], 'date impossible refusée aussi');
    }

    public function testPhotosRefuseesOuEnTrop(): void
    {
        $contenu = $this->contenu();
        $maintenant = new \DateTimeImmutable('2026-10-18 09:00');

        $faux = $this->envoi('<?php echo 1;', 'faux.png');
        $retour = $contenu->enregistrer(new ActionSociale(), ['titre' => 'Don de sang', 'texte' => 'x'], ['images' => [$faux]], $maintenant);
        self::assertSame(['« faux.png » : ce n\'est pas une image JPG, PNG ou WebP.'], $retour['erreurs']);

        $action = (new ActionSociale())->setTitre('Don de sang');
        for ($i = 0; $i < ActionSociale::PHOTOS_MAX; ++$i) {
            $action->ajouterImage(ImageSite::depuis(self::png(), 'image/png', 4, 4));
        }
        $retour = $contenu->enregistrer($action, ['titre' => 'Don de sang', 'texte' => 'x'], ['images' => [$this->envoi(self::png())]], $maintenant);
        self::assertSame([\sprintf('Une action sociale a %d photos au plus : retirez-en avant d\'en ajouter.', ActionSociale::PHOTOS_MAX)], $retour['erreurs']);
        self::assertSame([], glob($this->dossier.'/originaux/*') ?: []);
    }

    public function testPlanDuSiteEtPiedDePageSeulementAvecUneActionMontree(): void
    {
        $sans = new ActionsSociales($this->depot(false));
        self::assertFalse($sans->visibles());
        self::assertSame([], iterator_to_array($sans->pagesDuPlan(), false));
        self::assertNotContains('Nos actions sociales', array_column($this->pied($sans), 'libelle'));

        $avec = new ActionsSociales($this->depot(true));
        $plan = iterator_to_array($avec->pagesDuPlan(), false);
        self::assertSame([ActionsSociales::ROUTE], array_column($plan, 'route'));
        self::assertArrayNotHasKey('images', $plan[0], 'les photos (des personnes, des enfants) ne sont pas poussées vers la recherche d\'images');
        $pied = $this->pied($avec);
        self::assertSame(['Nos équipements', 'Nos actions sociales'], array_column($pied, 'libelle'));
        self::assertSame('/nos-actions-sociales', $pied[1]['url']);

        // Hors du pied fixe : le plan du site ne la prend que par sa source.
        self::assertArrayNotHasKey(ActionsSociales::ROUTE, SiteExtension::PIED);
        self::assertArrayNotHasKey(ActionsSociales::ROUTE, SiteExtension::MENU);
    }

    private function contenu(): ContenuSite
    {
        return new ContenuSite($this->createStub(EntityManagerInterface::class), new ImagesSite($this->dossier.'/public', $this->dossier.'/originaux'));
    }

    private function depot(bool $visible): ActionSocialeRepository
    {
        $depot = $this->createStub(ActionSocialeRepository::class);
        $depot->method('existeVisible')->willReturn($visible);
        $depot->method('derniereModification')->willReturn($visible ? new \DateTimeImmutable('2026-10-18') : null);

        return $depot;
    }

    /** @return list<array{route: string, url: string, libelle: string}> */
    private function pied(ActionsSociales $partie): array
    {
        $routes = new RouteCollection();
        $routes->add('app_site_equipements', new Route('/equipements'));
        $routes->add(ActionsSociales::ROUTE, new Route('/nos-actions-sociales'));
        $acces = new AccesEnLigne(true, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), (new \App\Tests\Unit\Security\FabriqueCodes())->codes(), $this->createStub(NotificationService::class));
        $site = new SiteExtension(new UrlGenerator($routes, new RequestContext()), $this->createStub(EntrepriseRepository::class), $acces, $partie);
        foreach ($site->getFunctions() as $fonction) {
            if ('site_pied' === $fonction->getName()) {
                return ($fonction->getCallable())();
            }
        }
        self::fail('site_pied() absente');
    }

    private function envoi(string $octets, string $nom = 'photo.png'): UploadedFile
    {
        @mkdir($this->dossier, 0o777, true);
        $chemin = tempnam($this->dossier, 'envoi');
        file_put_contents($chemin, $octets);

        return new UploadedFile($chemin, $nom, 'image/png', null, true);
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 15, 69, 144));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** PNG avec un bloc eXIf (marque de l'appareil, position GPS fictive) juste après l'en-tête. */
    private static function pngAvecExif(): string
    {
        $png = self::png();
        $exif = 'MM'."\x00\x2A\x00\x00\x00\x08".'Appareil-essai GPS 12.6517 -7.9299';
        $bloc = pack('N', \strlen($exif)).'eXIf'.$exif.pack('N', crc32('eXIf'.$exif));
        // Signature (8 octets) puis IHDR (4 + 4 + 13 + 4 octets).
        $avecExif = substr($png, 0, 33).$bloc.substr($png, 33);
        self::assertStringContainsString('eXIf', $avecExif);

        return $avecExif;
    }
}
