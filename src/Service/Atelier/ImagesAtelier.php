<?php

namespace App\Service\Atelier;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Service\AnalyseFichierImpression;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\ReclamationsClients;
use App\Service\Site\ImagesSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Images de l'Atelier 3D (docs/atelier-3d.md).
 *
 * Le logo ou la photo du client est contrôlé dès son dépôt (vrai PNG, JPG
 * ou WebP, poids, nombre de pixels), réencodé sans ses métadonnées (EXIF,
 * position GPS) et gardé hors de public/, dans var/uploads/atelier, au nom
 * de la session qui l'a déposé : une autre session ne peut pas s'en
 * servir. À la validation du modèle, il devient un fichier du client
 * (var/uploads/commandes, comme « Mes fichiers ») et monte au local avec
 * les vues rendues par la page, elles aussi relues et réencodées.
 */
final class ImagesAtelier
{
    public const ENVOI_MAX = 15 * 1024 * 1024;

    /** Plus grand côté gardé pour l'impression (plus de 450 dpi sur 28 cm). */
    public const COTE_MAX = 5000;

    /** Au-delà, l'image n'est pas lue (mémoire du serveur). */
    public const PIXELS_MAX = 36_000_000;

    /** Vue rendue par la page : PNG ou WebP, 2 048 px et 4 Mo au plus. */
    public const RENDU_COTE_MAX = 2048;
    public const RENDU_OCTETS_MAX = 4 * 1024 * 1024;

    /** Résolution conseillée sur textile (DTF), et en dessous de laquelle l'image sera floue. */
    public const DPI_CONSEILLE = 150;
    public const DPI_MINIMUM = 100;

    public const SESSION = 'atelier_depots';

    /** Images déposées et pas encore validées, au plus, par session. */
    public const DEPOTS_MAX = 12;

    /** Un dépôt non validé est effacé après ce délai. */
    public const DUREE_DEPOT = '-1 day';

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /**
     * Dépose l'image d'une face. Renvoie ce que la page doit savoir
     * (jamais le chemin sur le disque).
     *
     * @return array{jeton: string, nom: string, largeur: int, hauteur: int}
     *
     * @throws \DomainException message pour le client
     */
    public function deposer(UploadedFile $fichier, SessionInterface $session, \DateTimeImmutable $le): array
    {
        if (!$fichier->isValid()) {
            throw new \DomainException(\in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? \sprintf('Image trop lourde : %d Mo au plus.', intdiv(self::envoiMax(), 1024 * 1024))
                : 'L\'image n\'est pas arrivée : réessayez.');
        }
        if ((int) $fichier->getSize() > self::envoiMax()) {
            throw new \DomainException(\sprintf('Image trop lourde : %d Mo au plus.', intdiv(self::envoiMax(), 1024 * 1024)));
        }
        $this->nettoyer($le);
        $depots = self::depots($session);
        if (\count($depots) >= self::DEPOTS_MAX) {
            // Les plus anciens laissent la place.
            foreach (\array_slice(array_keys($depots), 0, \count($depots) - self::DEPOTS_MAX + 1) as $ancien) {
                @unlink($this->cheminDepot($ancien, $depots[$ancien]['extension']));
                unset($depots[$ancien]);
            }
        }

        $image = self::reencoder((string) file_get_contents($fichier->getPathname()));
        $jeton = bin2hex(random_bytes(24));
        $chemin = $this->cheminDepot($jeton, $image['extension']);
        if (false === file_put_contents($chemin, $image['octets'], \LOCK_EX)) {
            throw new \RuntimeException('Image impossible à ranger');
        }
        chmod($chemin, 0640);
        $nom = FichiersClient::nomPropre((string) $fichier->getClientOriginalName());
        $depots[$jeton] = ['nom' => $nom, 'largeur' => $image['largeur'], 'hauteur' => $image['hauteur'], 'extension' => $image['extension'], 'mime' => $image['mime'], 'le' => $le->getTimestamp()];
        $session->set(self::SESSION, $depots);

        return ['jeton' => $jeton, 'nom' => $nom, 'largeur' => $image['largeur'], 'hauteur' => $image['hauteur']];
    }

    /**
     * Le dépôt de cette session pour ce jeton, ou null (autre session,
     * jeton inventé, fichier effacé).
     *
     * @return array{nom: string, largeur: int, hauteur: int, extension: string, mime: string, chemin: string}|null
     */
    public function depot(SessionInterface $session, mixed $jeton): ?array
    {
        if (!\is_string($jeton) || !preg_match('/^[0-9a-f]{48}$/', $jeton)) {
            return null;
        }
        $d = self::depots($session)[$jeton] ?? null;
        if (null === $d) {
            return null;
        }
        $chemin = $this->cheminDepot($jeton, $d['extension']);

        return is_file($chemin) ? $d + ['chemin' => $chemin] : null;
    }

    /** Dépôt utilisé (modèle validé) : il ne sert plus. */
    public function oublier(SessionInterface $session, string $jeton): void
    {
        $depots = self::depots($session);
        if (isset($depots[$jeton])) {
            @unlink($this->cheminDepot($jeton, $depots[$jeton]['extension']));
            unset($depots[$jeton]);
            $session->set(self::SESSION, $depots);
        }
    }

    /**
     * Lit, contrôle et réencode une image du client : PNG (transparence
     * gardée) ou JPEG, sans métadonnées, réduite à COTE_MAX.
     *
     * @return array{octets: string, mime: string, extension: string, largeur: int, hauteur: int}
     *
     * @throws \DomainException
     */
    public static function reencoder(string $octets): array
    {
        $infos = '' !== $octets ? @getimagesizefromstring($octets) : false;
        if (false === $infos || !\in_array($infos[2], [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP], true)) {
            throw new \DomainException('Ce fichier n\'est pas une image PNG, JPG ou WebP.');
        }
        if ($infos[0] < 1 || $infos[1] < 1 || $infos[0] * $infos[1] > self::PIXELS_MAX) {
            throw new \DomainException('Image trop grande : 36 millions de pixels au plus (6 000 × 6 000).');
        }
        if (!ImagesSite::lit($infos[2])) {
            if (\IMAGETYPE_JPEG !== $infos[2]) {
                throw new \DomainException('Ce format n\'est pas lu ici : envoyez un PNG ou un JPG.');
            }
            // Serveur sans JPEG dans GD : la photo reste telle quelle, sans ses métadonnées.
            $propre = ReclamationsClients::jpegSansMetadonnees($octets);

            return ['octets' => $propre, 'mime' => 'image/jpeg', 'extension' => 'jpg', 'largeur' => (int) $infos[0], 'hauteur' => (int) $infos[1]];
        }

        $source = @imagecreatefromstring($octets);
        if (false === $source) {
            throw new \DomainException('L\'image n\'a pas pu être lue.');
        }
        if (\IMAGETYPE_JPEG === $infos[2] && \function_exists('exif_read_data')) {
            // Photo de téléphone prise de côté : remise droite d'après l'EXIF, qui disparaît ensuite.
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($octets));
            $angle = match (\is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if (0 !== $angle) {
                $source = imagerotate($source, $angle, 0) ?: $source;
            }
        }
        $image = self::reduire($source, self::COTE_MAX);
        // Une photo reste en JPEG (bien plus léger) ; un logo garde sa transparence en PNG.
        $jpeg = \IMAGETYPE_JPEG === $infos[2] && \function_exists('imagejpeg') && (bool) (gd_info()['JPEG Support'] ?? false);
        ob_start();
        if ($jpeg) {
            imagejpeg($image, null, 92);
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagepng($image, null, 6);
        }
        $sortie = (string) ob_get_clean();
        if ('' === $sortie) {
            throw new \DomainException('L\'image n\'a pas pu être enregistrée.');
        }

        return ['octets' => $sortie, 'mime' => $jpeg ? 'image/jpeg' : 'image/png', 'extension' => $jpeg ? 'jpg' : 'png', 'largeur' => imagesx($image), 'hauteur' => imagesy($image)];
    }

    /**
     * Vue de face ou de dos rendue par la page : un vrai PNG ou WebP de
     * taille raisonnable, réencodé en PNG (rien d'autre ne passe).
     *
     * @return array{octets: string, largeur: int, hauteur: int}
     *
     * @throws \DomainException
     */
    public static function lireRendu(string $octets): array
    {
        if ('' === $octets || \strlen($octets) > self::RENDU_OCTETS_MAX) {
            throw new \DomainException('Vue du modèle manquante ou trop lourde : réessayez.');
        }
        $infos = @getimagesizefromstring($octets);
        if (false === $infos || !\in_array($infos[2], [\IMAGETYPE_PNG, \IMAGETYPE_WEBP, \IMAGETYPE_JPEG], true)
            || $infos[0] < 16 || $infos[1] < 16 || $infos[0] > self::RENDU_COTE_MAX || $infos[1] > self::RENDU_COTE_MAX) {
            throw new \DomainException('Vue du modèle illisible : réessayez.');
        }
        if (!ImagesSite::lit($infos[2])) {
            throw new \DomainException('Vue du modèle illisible : réessayez.');
        }
        $image = @imagecreatefromstring($octets);
        if (false === $image) {
            throw new \DomainException('Vue du modèle illisible : réessayez.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 6);

        return ['octets' => (string) ob_get_clean(), 'largeur' => imagesx($image), 'hauteur' => imagesy($image)];
    }

    /**
     * Taille imprimée et résolution de l'image d'une face : l'image tient
     * dans la zone d'impression réduite à « taille » %, sans être déformée.
     *
     * @return array{largeurCm: float, hauteurCm: float, dpi: int, etat: string, message: ?string}
     */
    public static function controleResolution(int $largeurPx, int $hauteurPx, float $zoneLargeurCm, float $zoneHauteurCm, int $taille): array
    {
        $taille = max(5, min(100, $taille));
        $boiteL = $zoneLargeurCm * $taille / 100;
        $boiteH = $zoneHauteurCm * $taille / 100;
        $echelle = min($boiteL / max(1, $largeurPx), $boiteH / max(1, $hauteurPx));
        $largeurCm = round($largeurPx * $echelle, 1);
        $hauteurCm = round($hauteurPx * $echelle, 1);
        $dpi = (int) floor($largeurPx / max(0.1, $largeurPx * $echelle / 2.54));
        $conseil = (int) ceil($largeurPx * $echelle / 2.54 * self::DPI_CONSEILLE);

        [$etat, $message] = match (true) {
            $dpi >= self::DPI_CONSEILLE => [AnalyseFichierImpression::OK, null],
            $dpi >= self::DPI_MINIMUM => [AnalyseFichierImpression::ATTENTION, \sprintf('Image un peu juste (%d px de large) pour %s cm : préférez au moins %s px, ou réduisez la taille.', $largeurPx, self::cm($largeurCm), number_format($conseil, 0, ',', ' '))],
            default => [AnalyseFichierImpression::ERREUR, \sprintf('Image en basse résolution (%d px de large) : risque de flou à l\'impression sur %s cm. Préférez au moins %s px.', $largeurPx, self::cm($largeurCm), number_format($conseil, 0, ',', ' '))],
        };

        return ['largeurCm' => $largeurCm, 'hauteurCm' => $hauteurCm, 'dpi' => $dpi, 'etat' => $etat, 'message' => $message];
    }

    /**
     * Range un fichier final comme fichier du client (hors de public/),
     * prêt à monter au local avec son empreinte.
     *
     * @param list<array{libelle: string, valeur: string, etat: string}>|null $analyse
     */
    public function ranger(string $octets, string $extension, string $mime, string $nomOriginal, ?Clients $client, string $face, string $designation, ?array $analyse): CommandeDetailFichier
    {
        $jeton = bin2hex(random_bytes(32));
        $chemin = $this->dossierFichiers().'/'.$jeton.'.'.$extension;
        if (false === file_put_contents($chemin, $octets, \LOCK_EX)) {
            throw new \RuntimeException('Fichier impossible à ranger');
        }
        chmod($chemin, 0640);
        $f = (new CommandeDetailFichier())
            ->setJetonUpload($jeton)
            ->setNomOriginal(FichiersClient::nomPropre($nomOriginal))
            ->setNomStockage($jeton.'.'.$extension)
            ->setTypeMime($mime)
            ->setTaille(\strlen($octets))
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->setOrigine(CommandeDetailFichier::ORIGINE_CLIENT)
            ->setClient($client)
            ->setFace($face)
            ->setDesignation(mb_substr($designation, 0, 255))
            ->setEmpreinte(hash('sha256', $octets))
            ->setAnalyse($analyse)
            ->marquerUploadTermine();
        $this->em->persist($f);

        return $f;
    }

    /** Copie d'un fichier (« Commander à nouveau ») : chaque commande garde le sien. */
    public function copier(CommandeDetailFichier $source): ?CommandeDetailFichier
    {
        $chemin = $this->chemin($source);
        if (null === $chemin) {
            return null;
        }

        return $this->ranger(
            (string) file_get_contents($chemin),
            strtolower(pathinfo((string) $source->getNomStockage(), \PATHINFO_EXTENSION)) ?: 'png',
            (string) $source->getTypeMime(),
            (string) $source->getNomOriginal(),
            $source->getClient(),
            (string) $source->getFace(),
            (string) $source->getDesignation(),
            $source->getAnalyse(),
        );
    }

    /** Chemin d'un fichier du client sur le disque, ou null s'il manque. */
    public function chemin(CommandeDetailFichier $f): ?string
    {
        $nom = basename(trim((string) $f->getNomStockage()));
        $chemin = $this->dossierFichiers().'/'.$nom;

        return '' !== $nom && '.' !== $nom && is_file($chemin) ? $chemin : null;
    }

    /** Efface les dépôts abandonnés (toutes sessions). */
    public function nettoyer(\DateTimeImmutable $le): void
    {
        $limite = $le->modify(self::DUREE_DEPOT)->getTimestamp();
        foreach (glob($this->dossierDepots().'/*.{png,jpg}', \GLOB_BRACE) ?: [] as $f) {
            if ((int) @filemtime($f) < $limite) {
                @unlink($f);
            }
        }
    }

    /** Ce que le serveur accepte vraiment (limite de PHP comprise). */
    public static function envoiMax(): int
    {
        return (int) min(self::ENVOI_MAX, UploadedFile::getMaxFilesize());
    }

    /** @return array<string, array{nom: string, largeur: int, hauteur: int, extension: string, mime: string, le: int}> */
    private static function depots(SessionInterface $session): array
    {
        $d = $session->get(self::SESSION, []);

        return \is_array($d) ? $d : [];
    }

    private function cheminDepot(string $jeton, string $extension): string
    {
        return $this->dossierDepots().'/'.$jeton.'.'.('jpg' === $extension ? 'jpg' : 'png');
    }

    private function dossierDepots(): string
    {
        return $this->dossier('atelier');
    }

    private function dossierFichiers(): string
    {
        return $this->dossier('commandes');
    }

    private function dossier(string $nom): string
    {
        $dossier = $this->projet.'/var/uploads/'.$nom;
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des fichiers impossible à créer');
        }

        return $dossier;
    }

    private static function reduire(\GdImage $source, int $coteMax): \GdImage
    {
        $l = imagesx($source);
        $h = imagesy($source);
        $echelle = min(1, $coteMax / max($l, $h));
        if ($echelle >= 1 && imageistruecolor($source)) {
            return $source;
        }
        $nl = max(1, (int) round($l * $echelle));
        $nh = max(1, (int) round($h * $echelle));
        $cible = imagecreatetruecolor($nl, $nh);
        imagealphablending($cible, false);
        imagesavealpha($cible, true);
        imagefill($cible, 0, 0, (int) imagecolorallocatealpha($cible, 255, 255, 255, 127));
        imagecopyresampled($cible, $source, 0, 0, 0, 0, $nl, $nh, $l, $h);

        return $cible;
    }

    private static function cm(float $cm): string
    {
        return rtrim(rtrim(number_format($cm, 1, ',', ' '), '0'), ',');
    }
}
