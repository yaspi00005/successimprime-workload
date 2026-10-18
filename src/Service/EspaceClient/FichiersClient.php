<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Service\AnalyseFichierImpression;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fichiers envoyés par le client depuis son espace (maquette 16) : envoi
 * par morceaux (jusqu'à 500 Mo, repris après une coupure), contrôle
 * automatique à l'arrivée, logos gardés pour les prochaines commandes.
 *
 * Le fichier est rangé hors de public/ et n'est servi qu'à son client et
 * à l'atelier.
 */
final class FichiersClient
{
    public const TAILLE_MAX = 500 * 1024 * 1024;
    public const TAILLE_MAX_LOGO = 20 * 1024 * 1024;

    /** Taille d'un morceau envoyé par le navigateur (sous la limite d'envoi de PHP). */
    public const MORCEAU = 4 * 1024 * 1024;

    /** Envois commencés et pas finis, au plus, par client. */
    public const EN_COURS_MAX = 5;

    /** Place prise au plus par les fichiers d'un client. */
    public const QUOTA = 5 * 1024 * 1024 * 1024;

    /** Extension => signatures possibles du début du fichier. */
    private const TYPES = [
        'pdf' => ['%PDF'],
        'ai' => ['%PDF', '%!PS'],
        'eps' => ['%!PS', "\xC5\xD0\xD3\xC6"],
        'psd' => ['8BPS'],
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89PNG"],
        'tif' => ["II*\x00", "MM\x00*"],
        'tiff' => ["II*\x00", "MM\x00*"],
    ];

    public const MIME = [
        'pdf' => 'application/pdf', 'ai' => 'application/postscript', 'eps' => 'application/postscript',
        'psd' => 'image/vnd.adobe.photoshop', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff',
    ];

    public const OK = 'ok';
    public const A_REFAIRE = 'a_refaire';
    public const ATTENTION = 'attention';
    public const A_VERIFIER = 'a_verifier';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AnalyseFichierImpression $analyse,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /** @return list<string> extensions acceptées */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Commence un envoi.
     *
     * @throws \DomainException message à montrer au client
     */
    public function commencer(Clients $client, string $nom, int $taille, bool $logo, \DateTimeImmutable $maintenant): CommandeDetailFichier
    {
        $nom = self::nomPropre($nom);
        $extension = strtolower(pathinfo($nom, \PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            throw new \DomainException('Format non accepté. Envoyez un PDF, AI, EPS, PSD, JPG, PNG ou TIFF.');
        }
        if ($taille <= 0) {
            throw new \DomainException('Ce fichier est vide.');
        }
        if ($taille > ($logo ? self::TAILLE_MAX_LOGO : self::TAILLE_MAX)) {
            throw new \DomainException($logo ? 'Un logo ne doit pas dépasser 20 Mo.' : 'Le fichier dépasse 500 Mo : déposez-le en boutique ou envoyez un lien.');
        }
        $this->oublierEnvoisAbandonnes($client, $maintenant);
        if (\count($this->em->getRepository(CommandeDetailFichier::class)->findBy(['client' => $client, 'statut' => CommandeDetailFichier::STATUT_EN_COURS])) >= self::EN_COURS_MAX) {
            throw new \DomainException('Plusieurs envois sont déjà en cours : attendez qu\'ils finissent.');
        }
        if ($this->placeUtilisee($client) + $taille > self::QUOTA) {
            throw new \DomainException('Votre espace fichiers est plein : supprimez d\'anciens fichiers.');
        }

        $jeton = bin2hex(random_bytes(32));
        $fichier = (new CommandeDetailFichier())
            ->setJetonUpload($jeton)
            ->setNomOriginal($nom)
            ->setNomStockage($jeton.'.'.$extension)
            ->setTypeMime(self::MIME[$extension])
            ->setTaille($taille)
            ->setNombreMorceaux((int) ceil($taille / self::MORCEAU))
            ->setMorceauxRecus(0)
            ->setStatut(CommandeDetailFichier::STATUT_EN_COURS)
            ->setOrigine(CommandeDetailFichier::ORIGINE_CLIENT)
            ->setClient($client)
            ->setLogo($logo);
        $this->em->persist($fichier);
        $this->em->flush();

        return $fichier;
    }

    /**
     * Ajoute un morceau à la suite ; à la fin, vérifie le type réel,
     * range le fichier et le contrôle (à la taille d'impression prévue,
     * si le client l'a donnée).
     *
     * @return array{recu: int, termine: bool}
     *
     * @throws \DomainException
     */
    public function ajouter(CommandeDetailFichier $fichier, int $position, string $morceau, ?float $largeurCm = null, ?float $hauteurCm = null): array
    {
        $taille = (int) $fichier->getTaille();
        if ($fichier->estUploadTermine()) {
            return ['recu' => $taille, 'termine' => true];
        }
        $partiel = $this->partiel($fichier);
        clearstatcache();
        $deja = is_file($partiel) ? (int) filesize($partiel) : 0;
        if ($position !== $deja) {
            return ['recu' => $deja, 'termine' => false]; // le navigateur reprend au bon endroit
        }
        if ('' === $morceau || $deja + \strlen($morceau) > $taille || \strlen($morceau) > self::MORCEAU) {
            throw new \DomainException('Morceau refusé : recommencez l\'envoi.');
        }
        if (0 === $deja) {
            $this->verifierType($fichier, $morceau);
        }
        file_put_contents($partiel, $morceau, \FILE_APPEND | \LOCK_EX);
        $recu = $deja + \strlen($morceau);
        $fichier->setMorceauxRecus((int) ceil($recu / self::MORCEAU));
        if ($recu < $taille) {
            $this->em->flush();

            return ['recu' => $recu, 'termine' => false];
        }

        $final = $this->dossier().'/'.basename((string) $fichier->getNomStockage());
        chmod($partiel, 0640);
        rename($partiel, $final);
        $fichier->setEmpreinte((string) hash_file('sha256', $final));
        $fichier->setAnalyse($this->analyse->analyser($final, $largeurCm > 0 ? $largeurCm : null, $hauteurCm > 0 ? $hauteurCm : null));
        $fichier->marquerUploadTermine();
        $this->em->flush();

        return ['recu' => $recu, 'termine' => true];
    }

    /**
     * Refait le contrôle pour une taille d'impression donnée (le fichier
     * choisi dans une commande se contrôle à la taille commandée).
     */
    public function recontroler(CommandeDetailFichier $fichier, ?float $largeurCm, ?float $hauteurCm): void
    {
        $chemin = $this->chemin($fichier);
        if (null !== $chemin) {
            $fichier->setAnalyse($this->analyse->analyser($chemin, $largeurCm, $hauteurCm));
        }
    }

    /**
     * « Refaire » une commande (Nouvelle commande, « Commander à nouveau ») :
     * le fichier que le client avait envoyé pour cette ligne devient un
     * nouveau fichier à lui, pour la nouvelle commande (même octets, lien
     * dur sinon copie). null si ce n'est pas son fichier ou si les octets
     * ne sont pas ici (l'atelier le reprend d'après la note).
     */
    public function reprendre(CommandeDetailFichier $source, Clients $client): ?CommandeDetailFichier
    {
        $chemin = $this->chemin($source);
        if (null === $chemin || $source->getClient()?->getId() !== $client->getId() || !$source->isActif()
            || CommandeDetailFichier::ORIGINE_CLIENT !== $source->getOrigine() || $source->isLogo() || !$source->estUploadTermine()) {
            return null;
        }
        $extension = strtolower(pathinfo((string) $source->getNomStockage(), \PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$extension])) {
            return null;
        }
        $jeton = bin2hex(random_bytes(32));
        $cible = $this->dossier().'/'.$jeton.'.'.$extension;
        if (!@link($chemin, $cible) && !@copy($chemin, $cible)) {
            return null;
        }
        $fichier = (new CommandeDetailFichier())
            ->setJetonUpload($jeton)
            ->setNomOriginal((string) $source->getNomOriginal())
            ->setNomStockage($jeton.'.'.$extension)
            ->setTypeMime($source->getTypeMime())
            ->setTaille($source->getTaille())
            ->setNombreMorceaux(max(1, (int) ceil((int) $source->getTaille() / self::MORCEAU)))
            ->setMorceauxRecus(max(1, (int) ceil((int) $source->getTaille() / self::MORCEAU)))
            ->setOrigine(CommandeDetailFichier::ORIGINE_CLIENT)
            ->setClient($client)
            ->setLogo(false)
            ->setFichierSource($source)
            ->setEmpreinte($source->getEmpreinte())
            ->setAnalyse($source->getAnalyse())
            ->marquerUploadTermine();
        $this->em->persist($fichier);

        return $fichier;
    }

    /** @return list<CommandeDetailFichier> les fichiers du client, les plus récents d'abord */
    public function liste(Clients $client, bool $logos = false, int $nombre = 60): array
    {
        return $this->em->getRepository(CommandeDetailFichier::class)->findBy(
            ['client' => $client, 'actif' => true, 'logo' => $logos, 'statut' => CommandeDetailFichier::STATUT_TERMINE],
            ['id' => 'DESC'],
            $nombre,
        );
    }

    /** Un fichier du client, ou null s'il est à un autre. */
    public function trouver(Clients $client, int $id): ?CommandeDetailFichier
    {
        $f = $this->em->find(CommandeDetailFichier::class, $id);

        return $f instanceof CommandeDetailFichier && $f->getClient()?->getId() === $client->getId() && $f->isActif() ? $f : null;
    }

    public function trouverEnvoi(Clients $client, string $jeton): ?CommandeDetailFichier
    {
        $f = $this->em->getRepository(CommandeDetailFichier::class)->findOneBy(['jetonUpload' => $jeton]);

        return $f instanceof CommandeDetailFichier && $f->getClient()?->getId() === $client->getId() && $f->isActif() ? $f : null;
    }

    /**
     * Le client retire un fichier : seulement s'il n'est pas déjà dans une
     * commande (l'atelier en a besoin).
     *
     * @throws \DomainException
     */
    public function retirer(CommandeDetailFichier $fichier): void
    {
        if (null !== $fichier->getCommandeDetail()) {
            throw new \DomainException('Ce fichier est dans une commande : l\'atelier le garde.');
        }
        $fichier->setActif(false);
        $this->em->flush();
        if (null !== ($chemin = $this->chemin($fichier))) {
            @unlink($chemin);
        }
    }

    public function chemin(CommandeDetailFichier $fichier): ?string
    {
        $nom = basename(trim((string) $fichier->getNomStockage()));
        $chemin = $this->dossier().'/'.$nom;

        return '' !== $nom && '.' !== $nom && is_file($chemin) ? $chemin : null;
    }

    /**
     * Ce que montre la liste « Contrôle automatique » : un état et une phrase.
     *
     * @param list<array{libelle: string, valeur: string, etat: string}>|null $analyse
     *
     * @return array{etat: string, libelle: string, texte: string}
     */
    public static function resume(?array $analyse): array
    {
        $controles = $analyse ?? [];
        if ([] === $controles) {
            return ['etat' => self::A_VERIFIER, 'libelle' => 'À vérifier', 'texte' => 'Contrôlé par l\'atelier'];
        }
        $pire = null;
        $rang = [AnalyseFichierImpression::ERREUR => 3, AnalyseFichierImpression::ATTENTION => 2, AnalyseFichierImpression::A_VERIFIER => 1, AnalyseFichierImpression::OK => 0];
        foreach ($controles as $c) {
            if (null === $pire || ($rang[$c['etat']] ?? 0) > ($rang[$pire['etat']] ?? 0)) {
                $pire = $c;
            }
        }
        $texte = static fn (array $c): string => $c['libelle'].' : '.$c['valeur'];
        $bons = array_values(array_filter($controles, static fn (array $c): bool => AnalyseFichierImpression::OK === $c['etat']));

        // Ce qui ne se lit pas dans le fichier (« à vérifier ») est revu par l'atelier : il ne bloque pas.
        return match ($pire['etat']) {
            AnalyseFichierImpression::ERREUR => ['etat' => self::A_REFAIRE, 'libelle' => 'À refaire', 'texte' => $texte($pire)],
            AnalyseFichierImpression::ATTENTION => ['etat' => self::ATTENTION, 'libelle' => 'Attention', 'texte' => $texte($pire)],
            default => [] === $bons
                ? ['etat' => self::A_VERIFIER, 'libelle' => 'À vérifier', 'texte' => 'Vérifié par l\'atelier · '.$texte($pire)]
                : ['etat' => self::OK, 'libelle' => 'OK', 'texte' => implode(' · ', array_map($texte, \array_slice($bons, 0, 2)))],
        };
    }

    public static function nomPropre(string $nom): string
    {
        $nom = basename(str_replace('\\', '/', $nom));
        $nom = preg_replace('/[\x00-\x1F\x7F]+/u', '', $nom) ?? '';

        return mb_substr(trim($nom), -200) ?: 'fichier';
    }

    /** Le début du fichier correspond-il à son extension (pas d'exécutable renommé) ? */
    public static function signatureValide(string $extension, string $debut): bool
    {
        foreach (self::TYPES[strtolower($extension)] ?? [] as $signature) {
            // Un PDF peut commencer par quelques octets avant %PDF.
            if (str_starts_with($debut, $signature) || ('%PDF' === $signature && str_contains(substr($debut, 0, 1024), '%PDF'))) {
                return true;
            }
        }

        return false;
    }

    private function verifierType(CommandeDetailFichier $fichier, string $debut): void
    {
        $extension = strtolower(pathinfo((string) $fichier->getNomStockage(), \PATHINFO_EXTENSION));
        if (self::signatureValide($extension, $debut)) {
            return;
        }
        $fichier->setStatut(CommandeDetailFichier::STATUT_ECHEC)->setActif(false);
        $this->em->flush();

        throw new \DomainException(\sprintf('Ce fichier n\'est pas un vrai %s : enregistrez-le de nouveau depuis votre logiciel.', strtoupper($extension)));
    }

    private function partiel(CommandeDetailFichier $fichier): string
    {
        $dossier = $this->projet.'/var/uploads/commande_tmp';
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier d\'envoi impossible à créer');
        }

        return $dossier.'/client-'.$fichier->getJetonUpload().'.part';
    }

    private function dossier(): string
    {
        $dossier = $this->projet.'/var/uploads/commandes';
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des fichiers impossible à créer');
        }

        return $dossier;
    }

    private function placeUtilisee(Clients $client): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(f.taille), 0)')->from(CommandeDetailFichier::class, 'f')
            ->where('f.client = :c')->andWhere('f.actif = true')->setParameter('c', $client)
            ->getQuery()->getSingleScalarResult();
    }

    /** Un envoi laissé en plan plus d'un jour est oublié (et son morceau effacé). */
    private function oublierEnvoisAbandonnes(Clients $client, \DateTimeImmutable $maintenant): void
    {
        foreach ($this->em->getRepository(CommandeDetailFichier::class)->findBy(['client' => $client, 'statut' => CommandeDetailFichier::STATUT_EN_COURS]) as $f) {
            if ($f->getCreeLe() < $maintenant->modify('-1 day')) {
                @unlink($this->partiel($f));
                $f->setStatut(CommandeDetailFichier::STATUT_ECHEC)->setActif(false);
            }
        }
        $this->em->flush();
    }
}
