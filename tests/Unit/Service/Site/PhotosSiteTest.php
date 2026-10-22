<?php

namespace App\Tests\Unit\Service\Site;

use App\Controller\SiteController;
use App\Service\Site\MarkdownSite;
use App\Service\Site\PhotosSite;
use App\Twig\ReferencementExtension;
use PHPUnit\Framework\TestCase;

/** Photos livrées avec le site vitrine (docs/site-vitrine.md, « Photos du site »). */
final class PhotosSiteTest extends TestCase
{
    private const PUBLIC = __DIR__.'/../../../../public';

    private static function photos(): PhotosSite
    {
        return new PhotosSite(self::PUBLIC);
    }

    public function testPhotoAvecSesLargeursEtSonTexteAlternatif(): void
    {
        $photo = self::photos()->photo('enseigne-succes-imprim-bamako');
        self::assertNotNull($photo);
        self::assertSame([480, 800, 1200], array_column($photo['fichiers'], 'largeur'));
        self::assertSame('images/site/photos/enseigne-succes-imprim-bamako-800.webp', $photo['src'], '800 px pour un navigateur sans srcset');
        self::assertSame([1200, 675], [$photo['largeur'], $photo['hauteur']]);
        self::assertStringContainsString('Bamako', $photo['alt']);

        self::assertSame('', self::photos()->photo('rubans-encre-couleurs')['alt'] ?? null, 'fond décoratif : texte alternatif vide');
        self::assertNull(self::photos()->photo('inconnue'));
        self::assertNull(self::photos()->photo(null));
    }

    public function testVraiesPhotosDuLocal(): void
    {
        $photos = self::photos();
        self::assertSame(PhotosSite::LOCAL, array_column($photos->local(), 'nom'), 'galerie « Notre local » dans l\'ordre');
        self::assertContains(PhotosSite::FACADE, PhotosSite::LOCAL);
        self::assertContains(PhotosSite::ALLEE, PhotosSite::LOCAL);
        foreach ($photos->local() as $photo) {
            self::assertSame([320, 480, 800, 1200], array_column($photo['fichiers'], 'largeur'), $photo['nom']);
            self::assertSame([1200, 675], [$photo['largeur'], $photo['hauteur']], $photo['nom']);
            self::assertStringContainsString('Succès Imprim', $photo['alt'], 'texte alternatif qui décrit la scène');
            self::assertNotEmpty($photo['legende']);
            // Petites largeurs légères pour la 3G : vignette et téléphone.
            self::assertLessThan(40 * 1024, filesize(self::PUBLIC.'/'.PhotosSite::chemin($photo['nom'], 480)), $photo['nom']);
            self::assertLessThan(80 * 1024, filesize(self::PUBLIC.'/'.PhotosSite::chemin($photo['nom'], 800)), $photo['nom']);
        }
        self::assertStringContainsString('floutées', $photos->photo('jardin-entree-succes-imprim')['alt'] ?? '', 'les personnes à la porte sont floutées');
        // Les visuels de 15/10 n'ont pas de légende.
        self::assertNull($photos->photo('enseigne-succes-imprim-bamako')['legende'] ?? null);
    }

    public function testFichierAbsentPhotoIgnoree(): void
    {
        $dossier = sys_get_temp_dir().'/photos-site-'.bin2hex(random_bytes(4));
        mkdir($dossier.'/'.PhotosSite::DOSSIER, 0o777, true);
        touch($dossier.'/'.PhotosSite::chemin('livraison-moto-bamako', 480));
        try {
            $photos = new PhotosSite($dossier);
            $livraison = $photos->photo('livraison-moto-bamako');
            self::assertSame(['images/site/photos/livraison-moto-bamako-480.webp'], array_column($livraison['fichiers'] ?? [], 'chemin'));
            self::assertSame('images/site/photos/livraison-moto-bamako-480.webp', $livraison['src'] ?? null);
            self::assertNull($photos->photo('enseigne-succes-imprim-bamako'), 'aucun fichier : pas de photo');
            self::assertSame([null], $photos->pourFamilles(['textile']), 'la carte garde son icône');
            self::assertSame([], $photos->local(), 'sans fichier, pas de galerie « Notre local »');
        } finally {
            array_map('unlink', glob($dossier.'/'.PhotosSite::DOSSIER.'/*') ?: []);
            rmdir($dossier.'/'.PhotosSite::DOSSIER);
            rmdir($dossier.'/images/site');
            rmdir($dossier.'/images');
            rmdir($dossier);
        }
    }

    public function testUniversDeLAccueilSansDoublon(): void
    {
        $dejaPrises = [SiteController::PHOTO_ACCROCHE, SiteController::PHOTO_PROJET];
        self::assertSame(
            ['banniere-grand-format-couleurs', 'depliants-imprimes-succes-imprim', 't-shirts-dtf-succes-imprim', 'vitrophanie-porte-vitrine'],
            self::photos()->pourFamilles(['grand', 'petit', 'textile', 'signaletique'], $dejaPrises),
        );
        // Deux univers de la même famille : la photo suivante, puis plus rien.
        self::assertSame(['t-shirts-dtf-succes-imprim', null], self::photos()->pourFamilles(['textile', 'textile']));
        self::assertSame(['banniere-grand-format-couleurs', 'banniere-vinyle-succes-imprim'], self::photos()->pourFamilles(['grand', 'grand']));
        self::assertSame([null], self::photos()->pourFamilles(['textile'], ['t-shirts-dtf-succes-imprim']));
        self::assertSame([null], self::photos()->pourFamilles(['inconnue']));
    }

    public function testCartesDesEquipementsDApresLeursMots(): void
    {
        // Texte provisoire de la page (ContenuInitial) : une photo par machine.
        $sections = MarkdownSite::sections(<<<'MD'
            ### Impression grand format
            *Grand format*
            - Bâches, mesh, vinyle

            ### Roll-up, vitrines et adhésifs
            *Grand format*
            - Vinyle adhésif et vitrophanie

            ### Impression textile DTF
            *Textile*
            - T-shirts, polos, tote bags

            ## Finitions en atelier
            Œillets · Ourlets · Fourreaux
            MD)['sections'];
        self::assertSame([['banniere-vinyle-succes-imprim', 'vitrophanie-porte-vitrine', 't-shirts-dtf-succes-imprim'], []], self::photos()->pourSections($sections));

        // Les vraies machines : le titre d'abord, chaque photo une seule fois, une carte sans mot garde son dessin.
        self::assertSame(
            ['banniere-vinyle-succes-imprim', 'banniere-grand-format-couleurs', 't-shirts-dtf-succes-imprim', null],
            self::photos()->pourCartes([
                ['titre' => 'Imprimante solvant 3,20 m', 'texte' => 'Bâches, mesh, vinyle'],
                ['titre' => 'Imprimante solvant 1,80 m', 'texte' => 'Roll-up, vitrophanie, micro-perforé'],
                ['titre' => 'Imprimante DTF Yinghe', 'texte' => 'Couleurs vives sur tout tissu'],
                ['titre' => 'Massicot', 'texte' => 'Coupe nette'],
            ]),
        );
        self::assertSame(['vitrophanie-porte-vitrine'], PhotosSite::selonMots('Vitrines et ADHÉSIFS'));
        self::assertSame([], PhotosSite::selonMots('Contextile'), 'un mot pris au milieu d\'un autre ne compte pas');
    }

    /** Les fichiers livrés : WebP sans métadonnée, proportions déclarées, 200 Ko au plus. */
    public function testFichiersLivres(): void
    {
        foreach (PhotosSite::PHOTOS as $nom => $definition) {
            $plusGrande = max($definition['largeurs']);
            foreach ($definition['largeurs'] as $largeur) {
                $fichier = self::PUBLIC.'/'.PhotosSite::chemin($nom, $largeur);
                self::assertFileExists($fichier);
                self::assertLessThanOrEqual(200 * 1024, filesize($fichier), $fichier);
                $debut = (string) file_get_contents($fichier, false, null, 0, 16);
                // Format WebP simple (« VP8 ») : aucun bloc EXIF, XMP ni profil possible.
                self::assertSame('RIFF', substr($debut, 0, 4), $fichier);
                self::assertSame('WEBPVP8 ', substr($debut, 8, 8), $fichier);
                $taille = getimagesize($fichier);
                self::assertNotFalse($taille);
                self::assertSame($largeur, $taille[0], $fichier);
                self::assertEqualsWithDelta($definition['hauteur'] * $largeur / $plusGrande, $taille[1], 1, $fichier);
            }
            self::assertSame($plusGrande, $definition['largeur'], $nom);
        }

        // Photo du haut de l'accueil sur téléphone (800 px au plus) : moins de 80 Ko.
        self::assertLessThan(80 * 1024, filesize(self::PUBLIC.'/'.PhotosSite::chemin(SiteController::PHOTO_ACCROCHE, 800)));
    }

    public function testImagesDeLaFicheGoogle(): void
    {
        $tailles = [];
        foreach (ReferencementExtension::IMAGES_FICHE as $chemin) {
            $fichier = self::PUBLIC.'/'.$chemin;
            self::assertFileExists($fichier);
            self::assertLessThanOrEqual(200 * 1024, filesize($fichier), $chemin);
            self::assertSame('WEBPVP8 ', (string) file_get_contents($fichier, false, null, 8, 8), 'WebP simple, sans métadonnée : '.$chemin);
            $taille = getimagesize($fichier);
            self::assertNotFalse($taille);
            $tailles[] = [$taille[0], $taille[1]];
        }
        // 16:9, 4:3 et 1:1, au moins 50 000 pixels chacune (recommandation de Google).
        self::assertSame([[1200, 675], [1200, 900], [1200, 1200]], $tailles);
        self::assertSame(PhotosSite::chemin(PhotosSite::FACADE, 1200), ReferencementExtension::IMAGES_FICHE[0], 'la vraie façade');
    }

    public function testImageDePartage(): void
    {
        $fichier = self::PUBLIC.'/'.ReferencementExtension::IMAGE_PARTAGE['chemin'];
        $taille = getimagesize($fichier);
        self::assertNotFalse($taille);
        self::assertSame([1200, 630, \IMAGETYPE_JPEG], [$taille[0], $taille[1], $taille[2]]);
        self::assertLessThanOrEqual(200 * 1024, filesize($fichier));
        self::assertStringNotContainsString('Exif', (string) file_get_contents($fichier, false, null, 0, 4096), 'sans EXIF');
        self::assertFileExists(self::PUBLIC.'/'.ReferencementExtension::IMAGE_PARTAGE_LOGO['chemin'], 'le logo reste en secours');
    }
}
