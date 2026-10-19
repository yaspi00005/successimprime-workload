<?php

namespace App\Service\Prepresse;

use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\JournalActivite;
use App\Entity\User;
use App\Service\DossiersClients\NommageFichier;
use App\Service\FichiersPrives;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rattache des fichiers aux formats d'une commande (maquette PQ02,
 * docs/prepresse.md) : fichiers envoyés par morceaux depuis la fenêtre
 * « Déposer les fichiers » (FichierUploadController, var/uploads/commandes)
 * et fichiers pris dans le dossier partagé (déplacés, jamais lus en
 * mémoire).
 *
 * Chaque fichier rattaché est noté (qui, quand, d'où) dans son
 * observation et au journal de la commande ; sa ligne passe « à
 * contrôler » ; une copie va dans le dossier du client ; une commande en
 * pause (J+15) retrouve sa date de livraison.
 */
final class DepotFichiers
{
    /** Où FichierUploadController range les fichiers des commandes. */
    public const DOSSIER = 'var/uploads/commandes';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DimensionsFichier $dimensions,
        private readonly RangementDossierClient $rangement,
        private readonly FichiersPrives $fichiersPrives,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /**
     * Les lignes de la commande qui passent par la prépresse : ni « non
     * requise » (hors imprimerie, articles), ni annulées, ni déjà en
     * production.
     *
     * @return list<CommandesDetails>
     */
    public static function lignes(Commandes $commande): array
    {
        return array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $l): bool => $l->isPrePresseNecessaire() && CommandesDetails::PRODUCTION_A_PRODUIRE === $l->getStatutProduction(),
        ));
    }

    /** Le format fini de la ligne en mm (largeur, hauteur), ou null. */
    public static function formatMm(CommandesDetails $ligne): ?array
    {
        $l = (float) $ligne->getLargeur();
        $h = (float) $ligne->getLongueur();
        if ($l > 0 && $h > 0) {
            // Largeur et longueur des lignes : en mètres.
            return [round($l * 1000, 2), round($h * 1000, 2)];
        }
        $format = $ligne->getFormat();
        if (null !== $format && (float) $format->getLargeur() > 0 && (float) $format->getHauteur() > 0) {
            $facteur = match (strtolower($format->getUnite())) {
                'cm' => 10.0,
                'm' => 1000.0,
                default => 1.0,
            };

            return [(float) $format->getLargeur() * $facteur, (float) $format->getHauteur() * $facteur];
        }

        return null;
    }

    /** « 7,5 × 8 cm », ou le nom du format, ou la désignation. */
    public static function libelleFormat(CommandesDetails $ligne): string
    {
        $mm = self::formatMm($ligne);
        if (null !== $mm) {
            return AssociationFormats::libelleFormat($mm[0], $mm[1]);
        }

        return trim((string) ($ligne->getFormat()?->getNom() ?? EtatPrepresse::sansDimensions((string) $ligne->getDesignation()))) ?: 'Format';
    }

    /**
     * Fichiers reçus sur la ligne : actifs et complets (un envoi en cours
     * ne compte pas), ou fichier lié dans le dossier du client (Mes produits).
     *
     * @return list<CommandeDetailFichier>
     */
    public static function fichiersRecus(CommandesDetails $ligne): array
    {
        return array_values(array_filter(
            $ligne->getFichiers()->toArray(),
            static fn (CommandeDetailFichier $f): bool => $f->isActif() && CommandeDetailFichier::STATUT_TERMINE === $f->getStatut(),
        ));
    }

    public static function aFichier(CommandesDetails $ligne): bool
    {
        return [] !== self::fichiersRecus($ligne) || [] !== ($ligne->getFichierLie() ?? []);
    }

    /**
     * Les formats de la commande pour AssociationFormats.
     *
     * @return list<array{id: int, largeurMm: float, hauteurMm: float, aFichier: bool}>
     */
    public static function formats(Commandes $commande): array
    {
        $formats = [];
        foreach (self::lignes($commande) as $l) {
            $mm = self::formatMm($l);
            if (null !== $mm) {
                $formats[] = ['id' => (int) $l->getId(), 'largeurMm' => $mm[0], 'hauteurMm' => $mm[1], 'aFichier' => self::aFichier($l)];
            }
        }

        return $formats;
    }

    /** Chemin sur le disque d'un fichier de commande, ou null. */
    public function chemin(CommandeDetailFichier $fichier): ?string
    {
        if (null !== $fichier->getChemin() && '' !== $fichier->getChemin()) {
            return $this->fichiersPrives->chemin($fichier->getChemin());
        }
        $nom = basename((string) $fichier->getNomStockage());
        $base = realpath($this->projet.'/'.self::DOSSIER);
        $reel = '' !== $nom && false !== $base ? realpath($base.'/'.$nom) : false;

        return false !== $base && false !== $reel && str_starts_with($reel, $base.\DIRECTORY_SEPARATOR) && is_file($reel) ? $reel : null;
    }

    /**
     * Ce que AssociationFormats sait d'un fichier sur le disque.
     *
     * @return array{cle: string, nom: string, largeurPx: ?int, hauteurPx: ?int, dpi: ?float, largeurMm: ?float, hauteurMm: ?float, vectoriel: bool, photo: bool}
     */
    public function decrire(string $cle, string $nom, ?string $chemin): array
    {
        return ['cle' => $cle, 'nom' => $nom] + (null !== $chemin ? $this->dimensions->lire($chemin, $nom) : $this->dimensions->lire('', $nom));
    }

    /**
     * Un fichier envoyé par morceaux (jeton) devient le fichier de la
     * ligne. Refusé : envoi incomplet, déjà rattaché, fichier d'un client
     * (espace client), programme.
     */
    public function rattacherEnvoi(CommandeDetailFichier $fichier, CommandesDetails $ligne, User $par, \DateTimeImmutable $le): void
    {
        if (CommandeDetailFichier::STATUT_TERMINE !== $fichier->getStatut()) {
            throw new \DomainException(\sprintf('« %s » n\'est pas encore entièrement envoyé.', $fichier->getNomOriginal()));
        }
        if (null !== $fichier->getCommandeDetail() || null !== $fichier->getClient()) {
            throw new \DomainException(\sprintf('« %s » est déjà rattaché ailleurs.', $fichier->getNomOriginal()));
        }
        self::verifierType((string) $fichier->getNomOriginal());
        $chemin = $this->chemin($fichier) ?? throw new \DomainException(\sprintf('« %s » est introuvable sur le disque.', $fichier->getNomOriginal()));
        $ligne->addFichier($fichier);
        $fichier->setAjoutePar($par);
        $fichier->setObservation(trim(($fichier->getObservation() ?? '')."\n".\sprintf('Déposé à la prépresse par %s le %s.', $par->getUserIdentifier(), $le->format('d/m/Y à H:i'))));
        $this->apresRattachement($ligne, $fichier, $chemin, $par, $le, 'depot');
    }

    /**
     * Un fichier du dossier partagé devient le fichier de la ligne : il est
     * DÉPLACÉ (rename, instantané sur le même disque ; sinon copie par
     * blocs puis retrait), renommé « commande_format.ext ».
     */
    public function rattacherPartage(string $source, CommandesDetails $ligne, ?User $par, \DateTimeImmutable $le, string $comment = 'à la main'): CommandeDetailFichier
    {
        $nomOrigine = basename($source);
        self::verifierType($nomOrigine);
        $commande = $ligne->getCommande() ?? throw new \DomainException('Ligne sans commande.');
        $taille = (int) (@filesize($source) ?: 0);
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($nomOrigine, \PATHINFO_EXTENSION)));
        $nomStockage = bin2hex(random_bytes(32)).('' !== $extension ? '.'.$extension : '');
        $dossier = $this->projet.'/'.self::DOSSIER;
        if (!is_dir($dossier) && !@mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \DomainException('Le dossier des fichiers de commande n\'a pas pu être créé.');
        }
        $cible = $dossier.'/'.$nomStockage;
        if (!@rename($source, $cible)) {
            // Autre disque : copie par blocs, puis retrait de la zone de dépôt.
            if (!@copy($source, $cible) || filesize($cible) !== $taille) {
                @unlink($cible);
                throw new \DomainException(\sprintf('« %s » n\'a pas pu être déplacé.', $nomOrigine));
            }
            @unlink($source);
        }
        $type = @mime_content_type($cible);
        $fichier = (new CommandeDetailFichier())
            ->setJetonUpload(bin2hex(random_bytes(32)))
            ->setNomOriginal(NomFichierPartage::nomRange((string) $commande->getNumero(), str_replace(' cm', 'cm', self::libelleFormat($ligne)), $nomOrigine))
            ->setNomStockage($nomStockage)
            ->setTypeMime(\is_string($type) ? $type : 'application/octet-stream')
            ->setTaille($taille)
            ->setNombreMorceaux(1)
            ->setMorceauxRecus(1)
            ->marquerUploadTermine();
        $fichier->setAjoutePar($par);
        $fichier->setObservation(\sprintf('Pris dans le dossier partagé (%s) le %s%s, nom d\'origine « %s ».', $comment, $le->format('d/m/Y à H:i'), null !== $par ? ' par '.$par->getUserIdentifier() : '', $nomOrigine));
        $ligne->addFichier($fichier);
        $this->em->persist($fichier);
        $this->apresRattachement($ligne, $fichier, $cible, $par, $le, 'dossier_partage');

        return $fichier;
    }

    /**
     * Commande en pause (J+15) dont un fichier est arrivé : elle reprend
     * avec sa date de livraison. true si elle a repris.
     */
    public function reprendreSiFichier(Commandes $commande, ?User $par, \DateTimeImmutable $le): bool
    {
        if (!$commande->estEnPauseFichiers()) {
            return false;
        }
        $arrive = false;
        foreach (self::lignes($commande) as $l) {
            $arrive = $arrive || self::aFichier($l);
        }
        if (!$arrive) {
            return false;
        }
        $avant = $commande->getDateLivraisonAvantPause();
        $commande->reprendreApresPause();
        $this->noter($commande, $par, ['pause' => 'reprise', 'dateLivraison' => $avant?->format('d/m/Y')], $le);

        return true;
    }

    /** Programme ou script : jamais rattaché (même règle que les dossiers clients). */
    public static function verifierType(string $nom): void
    {
        if (\in_array(strtolower(pathinfo($nom, \PATHINFO_EXTENSION)), NommageFichier::PROGRAMMES, true)) {
            throw new \DomainException(\sprintf('« %s » est un programme : il n\'est pas accepté.', $nom));
        }
    }

    /** @param array<string, mixed> $details */
    public function noter(Commandes $commande, ?User $par, array $details, \DateTimeImmutable $le): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite('Commandes')
            ->setEntiteId((int) $commande->getId())
            ->setAction('prepresse')
            ->setDonneesApres(['commande' => $commande->getNumero(), 'le' => $le->format('d/m/Y H:i')] + $details)
            ->setUtilisateur($par));
    }

    private function apresRattachement(CommandesDetails $ligne, CommandeDetailFichier $fichier, string $chemin, ?User $par, \DateTimeImmutable $le, string $origine): void
    {
        $commande = $ligne->getCommande();
        if (null === $commande) {
            return;
        }
        $this->noter($commande, $par, ['fichier' => $fichier->getNomOriginal(), 'ligne' => $ligne->getId(), 'format' => self::libelleFormat($ligne), 'origine' => $origine], $le);
        $this->reprendreSiFichier($commande, $par, $le);
        $client = $commande->getClients();
        if (null !== $client) {
            $this->rangement->ranger($client, $chemin, NomFichierPartage::nomRange((string) $commande->getNumero(), str_replace(' cm', 'cm', self::libelleFormat($ligne)), (string) $fichier->getNomOriginal()), $par, $le);
        }
    }
}
