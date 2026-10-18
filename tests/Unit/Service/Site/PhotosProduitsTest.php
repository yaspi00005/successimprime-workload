<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\PhotosProduits;
use PHPUnit\Framework\TestCase;

/** Photos des produits envoyées par Yaya le 16/10/2026 (docs/site-vitrine.md, « Photos des produits »). */
final class PhotosProduitsTest extends TestCase
{
    private const PUBLIC = __DIR__.'/../../../../public';

    public function testChaqueProduitDeLaDemonstrationASaPhoto(): void
    {
        $attendues = [
            'Micro-perforé vitrine (démo)' => 'vitrine-micro-perforee',
            'Kakémono 80 × 180 cm (démo)' => 'kakemono',
            'Roll-up 85 × 200 cm (démo)' => 'roll-up',
            'Panneau Akylux (démo)' => 'panneau-akylux',
            'Casquette personnalisée (démo)' => 'casquette',
            'Polo personnalisé (démo)' => 'polo',
            'T-shirt personnalisé DTF (démo)' => 't-shirt',
            'Brochure A4 8 pages (démo)' => 'brochure',
            'Affiches A3 (démo)' => 'affiches',
            'Cartes de visite (démo)' => 'cartes-de-visite',
            'Flyers A5 (démo)' => 'flyers',
            'Vinyle adhésif (démo)' => 'vinyle-adhesif',
        ];
        foreach ($attendues as $produit => $photo) {
            self::assertSame($photo, PhotosProduits::nomPour($produit), $produit);
        }
        self::assertSame(array_keys(PhotosProduits::PHOTOS), array_values($attendues), 'les douze photos, dans l\'ordre de l\'envoi');

        // Le plus précis d'abord : la vitrine micro-perforée n'est pas le rouleau de vinyle.
        self::assertSame('vitrine-micro-perforee', PhotosProduits::nomPour('Vinyle micro-perforé pour vitrine'));
        self::assertSame('vinyle-adhesif', PhotosProduits::nomPour('Autocollants découpés'));
        self::assertSame('t-shirt', PhotosProduits::nomPour('TEE-SHIRT coton'));
        self::assertSame('cartes-de-visite', PhotosProduits::nomPour('Carte de visite pelliculée'));
        // Sans mot reconnu : le dessin reste.
        self::assertNull(PhotosProduits::nomPour('Bâche imprimée (démo)'));
        self::assertNull(PhotosProduits::nomPour('X-banner'));
        self::assertNull(PhotosProduits::nomPour(null));
        // Un mot pris au milieu d'un autre ne compte pas.
        self::assertNull(PhotosProduits::nomPour('Contrepolo'));
    }

    public function testPhotoPreteAvecSesLargeurs(): void
    {
        $photo = (new PhotosProduits(self::PUBLIC))->photo('Flyers A5 (démo)');
        self::assertNotNull($photo);
        self::assertSame([240, 360, 480], array_column($photo['fichiers'], 'largeur'));
        self::assertSame('images/site/produits/flyers-240.webp', $photo['src']);
        self::assertSame([480, 480], [$photo['largeur'], $photo['hauteur']]);
        self::assertNotSame('', $photo['alt']);
        self::assertNull((new PhotosProduits(self::PUBLIC))->photo('Bâche imprimée'));
        self::assertNull((new PhotosProduits(sys_get_temp_dir().'/si-sans-photos'))->photo('Flyers A5'), 'fichiers absents : le dessin reste');
    }

    /** WebP simple (« VP8 », donc sans EXIF, XMP ni profil), carré, 40 Ko au plus. */
    public function testFichiersLivres(): void
    {
        foreach (array_keys(PhotosProduits::PHOTOS) as $nom) {
            foreach (PhotosProduits::LARGEURS as $largeur) {
                $fichier = self::PUBLIC.'/'.PhotosProduits::chemin($nom, $largeur);
                self::assertFileExists($fichier);
                self::assertLessThanOrEqual(40 * 1024, filesize($fichier), $fichier);
                $debut = (string) file_get_contents($fichier, false, null, 0, 16);
                self::assertSame('RIFF', substr($debut, 0, 4), $fichier);
                self::assertSame('WEBPVP8 ', substr($debut, 8, 8), $fichier);
                $taille = getimagesize($fichier);
                self::assertNotFalse($taille);
                self::assertSame([$largeur, $largeur], [$taille[0], $taille[1]], $fichier);
            }
            self::assertMatchesRegularExpression('/^[A-ZÉ]/u', PhotosProduits::PHOTOS[$nom]['alt'], $nom.' : texte alternatif en français');
        }
        // Les originaux PNG envoyés ne sont pas dans le dépôt.
        self::assertSame([], glob(self::PUBLIC.'/'.PhotosProduits::DOSSIER.'/*.png') ?: []);
    }
}
