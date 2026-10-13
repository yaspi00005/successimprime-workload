<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ReclamationClient;
use App\Entity\ReclamationClientPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Réclamations des clients (maquette 18) : le client l'écrit dans son
 * espace avec jusqu'à trois photos ; l'atelier répond depuis la fiche
 * Clients › Réclamations.
 */
final class ReclamationsClients
{
    /** Réclamations ouvertes au plus par client. */
    public const OUVERTES_MAX = 5;

    /** Photo envoyée : 12 Mo au plus, réduite à 1600 px de côté. */
    public const PHOTO_OCTETS_MAX = 12 * 1024 * 1024;
    public const PHOTO_COTE_MAX = 1600;

    /** Photo gardée sans réduction (serveur sans JPEG dans GD). */
    public const PHOTO_BRUTE_MAX = 5 * 1024 * 1024;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<ReclamationClient> */
    public function duClient(Clients $client): array
    {
        return $this->em->getRepository(ReclamationClient::class)->findBy(['client' => $client], ['id' => 'DESC'], 50);
    }

    public function duClientParId(Clients $client, string $publicId): ?ReclamationClient
    {
        foreach ($this->duClient($client) as $r) {
            if ((string) $r->getPublicId() === $publicId) {
                return $r;
            }
        }

        return null;
    }

    /**
     * @param list<UploadedFile> $photos
     *
     * @throws \DomainException message à montrer
     */
    public function creer(CompteClient $compte, ?Commandes $commande, string $motif, string $objet, string $description, array $photos, \DateTimeImmutable $le): ReclamationClient
    {
        $client = $compte->getClient() ?? throw new \DomainException('Compte sans fiche client.');
        if ('' === trim($objet) || '' === trim($description)) {
            throw new \DomainException('Donnez un titre et expliquez le problème.');
        }
        if (null !== $commande && $commande->getClients() !== $client) {
            throw new \DomainException('Commande inconnue.');
        }
        $ouvertes = array_filter($this->duClient($client), static fn (ReclamationClient $r): bool => !$r->estClose());
        if (\count($ouvertes) >= self::OUVERTES_MAX) {
            throw new \DomainException('Vous avez déjà plusieurs réclamations en cours : nous y répondons d\'abord.');
        }
        $photos = array_values(array_filter($photos, static fn ($p): bool => $p instanceof UploadedFile));
        if (\count($photos) > ReclamationClient::PHOTOS_MAX) {
            throw new \DomainException(\sprintf('%d photos au plus.', ReclamationClient::PHOTOS_MAX));
        }
        // Toutes les photos sont lues avant d'enregistrer quoi que ce soit.
        $reduites = array_map(static function (UploadedFile $f): array {
            if (!$f->isValid() || $f->getSize() > self::PHOTO_OCTETS_MAX) {
                throw new \DomainException('Une photo est trop lourde (12 Mo au plus).');
            }

            return self::reduire((string) file_get_contents($f->getPathname()));
        }, $photos);

        $reclamation = ReclamationClient::nouvelle($client, $objet, $description, $le)
            ->setMotif($motif)
            ->setCommande($commande)
            ->setAuteur(CompteClient::clientAPlusieursComptes($client) ? $compte->getNomAffiche() : null);
        $this->em->persist($reclamation);
        foreach ($reduites as [$octets, $type, $largeur, $hauteur]) {
            $this->em->persist(ReclamationClientPhoto::pour($reclamation, $octets, $type, $largeur, $hauteur));
        }
        $this->em->flush();
        // Le numéro vient de l'id ; en ligne il reçoit le préfixe W-.
        $reclamation->setNumero(\sprintf('R-%04d', $reclamation->getId()));
        $this->em->flush();

        return $reclamation;
    }

    /**
     * Réponse de l'atelier. Le client est prévenu par le site en ligne
     * quand la réponse y arrive (NotificationsClient : application, SMS
     * ou e-mail, sans détail).
     *
     * @throws \DomainException
     */
    public function repondre(ReclamationClient $reclamation, string $reponse, string $statut, string $par, \DateTimeImmutable $le): void
    {
        $reclamation->repondre($reponse, $statut, $par, $le);
        $this->em->flush();
    }

    /** @return list<ReclamationClient> */
    public function liste(bool $closes): array
    {
        $qb = $this->em->getRepository(ReclamationClient::class)->createQueryBuilder('r')
            ->orderBy('r.id', 'DESC')->setMaxResults(200);
        $qb->where($closes ? 'r.statut IN (:etats)' : 'r.statut NOT IN (:etats)')
            ->setParameter('etats', [ReclamationClient::RESOLUE, ReclamationClient::REFUSEE]);

        return $qb->getQuery()->getResult();
    }

    /**
     * Photo réduite à 1600 px de côté et réencodée (JPEG, ou PNG quand le
     * serveur ne sait pas écrire le JPEG) : ce qui n'est pas une image est
     * refusé, et les données EXIF (position GPS) disparaissent.
     *
     * @return array{0: string, 1: string, 2: int, 3: int} octets, type, largeur, hauteur
     *
     * @throws \DomainException
     */
    public static function reduire(string $octets): array
    {
        $infos = '' !== $octets ? @getimagesizefromstring($octets) : false;
        if (false === $infos || !\in_array($infos[2], [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP], true)) {
            throw new \DomainException('Une des pièces jointes n\'est pas une photo (JPG, PNG ou WEBP).');
        }
        if ($infos[0] * $infos[1] > 50_000_000) {
            throw new \DomainException('Une photo est trop grande.');
        }
        $gd = gd_info();
        $lisible = match ($infos[2]) {
            \IMAGETYPE_JPEG => (bool) ($gd['JPEG Support'] ?? false),
            \IMAGETYPE_PNG => (bool) ($gd['PNG Support'] ?? false),
            default => (bool) ($gd['WebP Support'] ?? false),
        };
        if (!$lisible) {
            // Serveur sans JPEG dans GD : la photo reste telle quelle, sans ses métadonnées.
            if (\IMAGETYPE_JPEG !== $infos[2]) {
                throw new \DomainException('Ce format de photo n\'est pas lu ici : envoyez un JPG.');
            }
            if (\strlen($octets) > self::PHOTO_BRUTE_MAX) {
                throw new \DomainException('Une photo est trop lourde (5 Mo au plus).');
            }

            return [self::jpegSansMetadonnees($octets), 'image/jpeg', $infos[0], $infos[1]];
        }

        $source = @imagecreatefromstring($octets);
        if (false === $source) {
            throw new \DomainException('Une photo n\'a pas pu être lue.');
        }
        // Photo de téléphone prise de côté : remise droite d'après l'EXIF.
        if (\IMAGETYPE_JPEG === $infos[2] && \function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($octets));
            $angle = match ($exif['Orientation'] ?? 1) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if (0 !== $angle) {
                $source = imagerotate($source, $angle, 0) ?: $source;
            }
        }
        $largeur = imagesx($source);
        $hauteur = imagesy($source);
        $echelle = min(1, self::PHOTO_COTE_MAX / max($largeur, $hauteur));
        $l = max(1, (int) round($largeur * $echelle));
        $h = max(1, (int) round($hauteur * $echelle));
        $cible = imagecreatetruecolor($l, $h);
        imagefill($cible, 0, 0, (int) imagecolorallocate($cible, 255, 255, 255));
        imagecopyresampled($cible, $source, 0, 0, 0, 0, $l, $h, $largeur, $hauteur);
        ob_start();
        $jpeg = (bool) ($gd['JPEG Support'] ?? false);
        $jpeg ? imagejpeg($cible, null, 82) : imagepng($cible, null, 9);

        return [(string) ob_get_clean(), $jpeg ? 'image/jpeg' : 'image/png', $l, $h];
    }

    /**
     * JPEG sans ses segments de métadonnées (EXIF et XMP, IPTC,
     * commentaires) : l'image elle-même ne change pas.
     *
     * @throws \DomainException
     */
    public static function jpegSansMetadonnees(string $jpeg): string
    {
        if (!str_starts_with($jpeg, "\xFF\xD8")) {
            throw new \DomainException('Une photo n\'a pas pu être lue.');
        }
        $sortie = "\xFF\xD8";
        $i = 2;
        $taille = \strlen($jpeg);
        while ($i + 4 <= $taille && "\xFF" === $jpeg[$i]) {
            $marqueur = \ord($jpeg[$i + 1]);
            if (0xDA === $marqueur) {
                // Début des données de l'image : tout le reste est gardé.
                return $sortie.substr($jpeg, $i);
            }
            $longueur = unpack('n', substr($jpeg, $i + 2, 2))[1];
            $segment = substr($jpeg, $i, 2 + $longueur);
            // APP1 à APP15 sauf APP14 (couleurs Adobe), et les commentaires.
            $metadonnee = ($marqueur >= 0xE1 && $marqueur <= 0xEF && 0xEE !== $marqueur) || 0xFE === $marqueur;
            if (!$metadonnee) {
                $sortie .= $segment;
            }
            $i += 2 + $longueur;
        }

        throw new \DomainException('Une photo n\'a pas pu être lue.');
    }
}
