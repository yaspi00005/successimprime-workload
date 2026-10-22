<?php

namespace App\Service\Site;

use App\Entity\ImageSite;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Images du site vitrine : réencodage à l'envoi, fichiers publics tirés de
 * la base.
 *
 * À l'envoi : JPG, PNG ou WebP lu par GD, remis droit, réduit à 1200 px
 * (logo : 600 px ; portrait de l'équipe : recadré en carré de 480 px),
 * réencodé sans ses métadonnées (EXIF, position GPS) en WebP, ou en PNG si
 * GD ne sait pas écrire le WebP, 300 Ko au plus. L'original reste hors de
 * public/ (var/uploads/site/originaux), sauf pour un portrait : la photo
 * d'une personne n'est gardée que réencodée, sans ses métadonnées.
 *
 * Les fichiers publics (public/uploads/site, une grande et une petite
 * taille) sont refaits à partir de l'image en base, réencodés, dès qu'ils
 * manquent : c'est ainsi qu'une image arrivée par la synchronisation
 * devient visible sur le site, sans jamais publier les octets reçus.
 *
 * Sur le serveur, GD doit savoir lire le JPEG et écrire le WebP.
 */
#[AsEntityListener(event: Events::postUpdate, method: 'apresModification', entity: ImageSite::class)]
#[AsEntityListener(event: Events::postRemove, method: 'apresSuppression', entity: ImageSite::class)]
final class ImagesSite
{
    public const ENVOI_MAX = 15 * 1024 * 1024;
    public const PIXELS_MAX = 50_000_000;

    /** Largeur des fichiers publics : [grande, petite]. */
    public const LARGEURS = [
        ImageSite::USAGE_PHOTO => ['grande' => 1200, 'petite' => 480],
        ImageSite::USAGE_LOGO => ['grande' => 600, 'petite' => 240],
        // Rond de 104 à 120 px sur la page : 240 px pour un écran ×2, 480 px au-delà.
        ImageSite::USAGE_PORTRAIT => ['grande' => 480, 'petite' => 240],
    ];

    /** Recadrage d'un portrait plus haut que large : part du haut retirée (le visage est plutôt en haut). */
    public const PORTRAIT_HAUT = 0.25;

    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/site')] private readonly string $dossierPublic,
        #[Autowire('%kernel.project_dir%/var/uploads/site/originaux')] private readonly string $dossierOriginaux,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Image prête à enregistrer, depuis un fichier envoyé dans l'admin.
     *
     * @throws \DomainException message pour l'écran
     */
    public function depuisEnvoi(UploadedFile $fichier, string $usage = ImageSite::USAGE_PHOTO): ImageSite
    {
        $nom = $fichier->getClientOriginalName();
        if (!$fichier->isValid()) {
            throw new \DomainException(\sprintf('« %s » n\'est pas arrivé en entier (fichier trop lourd ?).', $nom));
        }
        if ((int) $fichier->getSize() > self::ENVOI_MAX) {
            throw new \DomainException(\sprintf('« %s » dépasse 15 Mo.', $nom));
        }
        $octets = (string) file_get_contents($fichier->getPathname());
        try {
            [$sortie, $type, $largeur, $hauteur] = self::preparer($octets, self::LARGEURS[$usage]['grande'] ?? 1200, ImageSite::USAGE_PORTRAIT === $usage);
        } catch (\DomainException $e) {
            throw new \DomainException(\sprintf('« %s » : %s', $nom, $e->getMessage()), 0, $e);
        }
        $image = ImageSite::depuis($sortie, $type, $largeur, $hauteur, $usage);
        if (!$image->estPortrait()) {
            // La photo d'une personne n'est pas gardée telle qu'envoyée (EXIF, position GPS du téléphone).
            $this->garderOriginal($image, $octets);
        }

        return $image;
    }

    /**
     * Lit l'image, la remet droite, la réduit et la réencode. Avec $carre,
     * elle est d'abord recadrée en carré (portrait de l'équipe).
     *
     * Le réencodage par GD n'écrit que les pixels : les métadonnées de
     * l'appareil (EXIF, position GPS, profil, commentaires) ne passent pas.
     *
     * @return array{0: string, 1: string, 2: int, 3: int} octets, type, largeur, hauteur
     *
     * @throws \DomainException
     */
    public static function preparer(string $octets, int $largeurMax, bool $carre = false): array
    {
        $infos = '' !== $octets ? @getimagesizefromstring($octets) : false;
        if (false === $infos || !\in_array($infos[2], [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP], true)) {
            throw new \DomainException('ce n\'est pas une image JPG, PNG ou WebP.');
        }
        if ($infos[0] * $infos[1] > self::PIXELS_MAX) {
            throw new \DomainException('image trop grande (50 millions de pixels au plus).');
        }
        if (!self::lit($infos[2])) {
            throw new \DomainException(match ($infos[2]) {
                \IMAGETYPE_JPEG => 'ce serveur ne lit pas les photos JPG (GD sans JPEG). Envoyez un PNG, ou faites activer le JPEG dans GD.',
                \IMAGETYPE_WEBP => 'ce serveur ne lit pas le WebP (GD sans WebP). Envoyez un PNG ou un JPG.',
                default => 'ce serveur ne lit pas ce format.',
            });
        }
        $source = @imagecreatefromstring($octets);
        if (false === $source) {
            throw new \DomainException('l\'image n\'a pas pu être lue.');
        }
        // Photo de téléphone prise de côté : remise droite d'après l'EXIF.
        if (\IMAGETYPE_JPEG === $infos[2] && \function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($octets));
            $angle = match (\is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if (0 !== $angle) {
                $source = imagerotate($source, $angle, 0) ?: $source;
            }
        }
        if ($carre) {
            $source = self::carre($source);
        }
        $image = self::redimensionner($source, $largeurMax);
        [$sortie, $type] = self::encoder($image);

        return [$sortie, $type, imagesx($image), imagesy($image)];
    }

    /**
     * WebP (ou PNG sans WebP) de 300 Ko au plus : qualité baissée, puis
     * image réduite tant qu'il le faut.
     *
     * @return array{0: string, 1: string}
     *
     * @throws \DomainException
     */
    public static function encoder(\GdImage &$image): array
    {
        $webp = self::ecritWebp();
        for ($essai = 0; $essai < 8; ++$essai) {
            foreach ($webp ? [82, 74, 66, 58] : [9] as $qualite) {
                $octets = self::ecrire($image, $webp, $qualite);
                if ('' !== $octets && \strlen($octets) <= ImageSite::TAILLE_MAX) {
                    return [$octets, $webp ? 'image/webp' : 'image/png'];
                }
            }
            if (!$webp && imageistruecolor($image) && 0 === $essai) {
                // PNG d'une photo : 256 couleurs avant de réduire la taille.
                $palette = self::copie($image, imagesx($image), imagesy($image));
                imagetruecolortopalette($palette, true, 256);
                $octets = self::ecrire($palette, false, 9);
                if ('' !== $octets && \strlen($octets) <= ImageSite::TAILLE_MAX) {
                    $image = $palette;

                    return [$octets, 'image/png'];
                }
            }
            $image = self::redimensionner($image, max(1, (int) round(imagesx($image) * 0.8)));
        }

        throw new \DomainException('l\'image reste trop lourde après réduction.');
    }

    public static function ecritWebp(): bool
    {
        return \function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /** GD sait-il lire ce type d'image ? */
    public static function lit(int $type): bool
    {
        $gd = \function_exists('gd_info') ? gd_info() : [];

        return match ($type) {
            \IMAGETYPE_JPEG => (bool) ($gd['JPEG Support'] ?? false),
            \IMAGETYPE_PNG => (bool) ($gd['PNG Support'] ?? false),
            \IMAGETYPE_WEBP => (bool) ($gd['WebP Support'] ?? false),
            default => false,
        };
    }

    /**
     * Fichier public d'une taille (« grande » ou « petite ») : chemin pour
     * asset(), largeur et hauteur. Refait s'il manque ; null si l'image ne
     * peut pas être lue ici.
     *
     * @return array{chemin: string, largeur: int, hauteur: int}|null
     */
    public function fichier(ImageSite $image, string $taille = 'grande'): ?array
    {
        [$largeur, $hauteur] = self::dimensions($image, $taille);
        $nom = $this->nomFichier($image, $largeur);
        if (!is_file($this->dossierPublic.'/'.$nom) && !$this->publier($image, $largeur, $nom)) {
            return null;
        }

        return ['chemin' => 'uploads/site/'.$nom, 'largeur' => $largeur, 'hauteur' => $hauteur];
    }

    /**
     * Largeur et hauteur d'une taille, sans agrandir.
     *
     * @return array{0: int, 1: int}
     */
    public static function dimensions(ImageSite $image, string $taille): array
    {
        $cible = (self::LARGEURS[$image->getUsage()] ?? self::LARGEURS[ImageSite::USAGE_PHOTO])[$taille] ?? 1200;
        $largeur = max(1, min($cible, $image->getLargeur()));
        $hauteur = max(1, (int) round($image->getHauteur() * $largeur / max(1, $image->getLargeur())));

        return [$largeur, $hauteur];
    }

    /** Retire les fichiers publics d'une ancienne version de l'image (ou tous). */
    public function nettoyer(ImageSite $image, bool $tout = false): void
    {
        $prefixe = $image->getPublicId()->toRfc4122().'-';
        $garde = $tout ? null : $prefixe.substr($image->getEmpreinte(), 0, 16).'-';
        foreach (glob($this->dossierPublic.'/'.$prefixe.'*') ?: [] as $chemin) {
            if (null === $garde || !str_starts_with(basename($chemin), $garde)) {
                @unlink($chemin);
            }
        }
        if ($tout) {
            foreach (glob($this->dossierOriginaux.'/'.$image->getPublicId()->toRfc4122().'.*') ?: [] as $chemin) {
                @unlink($chemin);
            }
        }
    }

    /**
     * Ne garde pas l'original envoyé (photo d'une action sociale : des
     * personnes, des enfants) : seule la version réencodée, sans EXIF ni
     * position GPS, reste.
     */
    public function oublierOriginal(ImageSite $image): void
    {
        foreach (glob($this->dossierOriginaux.'/'.$image->getPublicId()->toRfc4122().'.*') ?: [] as $chemin) {
            @unlink($chemin);
        }
    }

    public function apresModification(ImageSite $image): void
    {
        $this->nettoyer($image);
    }

    /**
     * Image supprimée : ses tailles publiques partent tout de suite ;
     * l'original (hors de public/) reste jusqu'à la suppression définitive
     * de la corbeille (docs/corbeille.md), qui le retire.
     */
    public function apresSuppression(ImageSite $image): void
    {
        $prefixe = $image->getPublicId()->toRfc4122().'-';
        foreach (glob($this->dossierPublic.'/'.$prefixe.'*') ?: [] as $chemin) {
            @unlink($chemin);
        }
    }

    private function nomFichier(ImageSite $image, int $largeur): string
    {
        return \sprintf('%s-%s-%d.%s', $image->getPublicId()->toRfc4122(), substr($image->getEmpreinte(), 0, 16), $largeur, self::ecritWebp() ? 'webp' : 'png');
    }

    /** Écrit un fichier public réencodé depuis la base (jamais les octets reçus tels quels). */
    private function publier(ImageSite $image, int $largeur, string $nom): bool
    {
        try {
            $octets = $image->getOctets();
            ImageSite::verifierOctets($octets, $image->getType());
            $source = self::lit('image/png' === $image->getType() ? \IMAGETYPE_PNG : \IMAGETYPE_WEBP) ? @imagecreatefromstring($octets) : false;
            if (false === $source) {
                throw new \DomainException('format non lu par GD sur ce serveur');
            }
            $reduite = self::redimensionner($source, $largeur);
            [$sortie] = self::encoder($reduite);
            if (!is_dir($this->dossierPublic) && !@mkdir($this->dossierPublic, 0775, true) && !is_dir($this->dossierPublic)) {
                throw new \RuntimeException('dossier public/uploads/site impossible à créer');
            }
            $temporaire = $this->dossierPublic.'/.'.$nom.'.'.bin2hex(random_bytes(4));
            if (false === file_put_contents($temporaire, $sortie) || !rename($temporaire, $this->dossierPublic.'/'.$nom)) {
                @unlink($temporaire);
                throw new \RuntimeException('écriture impossible');
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Image du site non publiée : {message}', ['message' => $e->getMessage(), 'image' => $image->getPublicId()->toRfc4122()]);

            return false;
        }
    }

    private function garderOriginal(ImageSite $image, string $octets): void
    {
        $extension = match (@getimagesizefromstring($octets)[2] ?? null) {
            \IMAGETYPE_JPEG => 'jpg',
            \IMAGETYPE_WEBP => 'webp',
            default => 'png',
        };
        if (!is_dir($this->dossierOriginaux) && !@mkdir($this->dossierOriginaux, 0775, true) && !is_dir($this->dossierOriginaux)) {
            return; // l'original n'est qu'une copie de sécurité
        }
        @file_put_contents($this->dossierOriginaux.'/'.$image->getPublicId()->toRfc4122().'.'.$extension, $octets);
    }

    /**
     * Carré pris au milieu de la largeur ; dans une image plus haute que
     * large, plutôt vers le haut (PORTRAIT_HAUT), là où est le visage.
     */
    public static function carre(\GdImage $source): \GdImage
    {
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $cote = min($largeur, $hauteur);
        if ($largeur === $hauteur) {
            return $source;
        }
        $x = (int) round(($largeur - $cote) / 2);
        $y = (int) round(($hauteur - $cote) * self::PORTRAIT_HAUT);
        $cible = imagecreatetruecolor($cote, $cote);
        imagealphablending($cible, false);
        imagesavealpha($cible, true);
        imagecopy($cible, $source, 0, 0, $x, $y, $cote, $cote);

        return $cible;
    }

    private static function redimensionner(\GdImage $source, int $largeurMax): \GdImage
    {
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $echelle = min(1, $largeurMax / max(1, $largeur), ImageSite::COTE_MAX / max(1, $hauteur));
        if ($echelle >= 1 && imageistruecolor($source)) {
            return $source;
        }

        return self::copie($source, max(1, (int) round($largeur * $echelle)), max(1, (int) round($hauteur * $echelle)));
    }

    /** Copie en couleurs vraies, transparence gardée (logos). */
    private static function copie(\GdImage $source, int $largeur, int $hauteur): \GdImage
    {
        $cible = imagecreatetruecolor($largeur, $hauteur);
        imagealphablending($cible, false);
        imagesavealpha($cible, true);
        imagefill($cible, 0, 0, (int) imagecolorallocatealpha($cible, 255, 255, 255, 127));
        imagecopyresampled($cible, $source, 0, 0, 0, 0, $largeur, $hauteur, imagesx($source), imagesy($source));

        return $cible;
    }

    private static function ecrire(\GdImage $image, bool $webp, int $qualite): string
    {
        imagesavealpha($image, true);
        ob_start();
        $ok = $webp ? imagewebp($image, null, $qualite) : imagepng($image, null, $qualite);
        $octets = (string) ob_get_clean();

        return $ok ? $octets : '';
    }
}
