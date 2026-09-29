<?php

namespace App\Entity;

use App\Repository\CommandeDetailFichierRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommandeDetailFichierRepository::class)]
#[ORM\Table(name: 'commande_detail_fichier')]
class CommandeDetailFichier
{
    public const STATUT_EN_ATTENTE = 'EN_ATTENTE';
    public const STATUT_EN_COURS = 'EN_COURS';
    public const STATUT_TERMINE = 'TERMINE';
    public const STATUT_ECHEC = 'ECHEC';

    public const ORIGINE_CLIENT = 'client';
    public const ORIGINE_INTERNE = 'interne';
    public const ORIGINE_EXTERNE = 'externe';

    public const ETAT_ORIGINAL = 'original';
    public const ETAT_A_TRAITER = 'a_traiter';
    public const ETAT_EN_TRAITEMENT = 'en_traitement';
    public const ETAT_TRAITE = 'traite';
    public const ETAT_PRET_IMPRESSION = 'pret_impression';
    public const ETAT_REJETE = 'rejete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /*
     * Nullable pendant l’envoi temporaire du fichier.
     * Le détail devra obligatoirement être renseigné
     * avant la validation de la commande.
     */
    #[ORM\ManyToOne(inversedBy: 'fichiers')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?CommandesDetails $commandeDetail = null;

    /*
     * Fichier original ayant servi à produire cette version.
     * Exemple : fichier client → fichier corrigé par le prépresse.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $fichierSource = null;

    #[ORM\Column(length: 64, unique: true)]
    private ?string $jetonUpload = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $morceauxRecus = 0;

    #[ORM\Column(nullable: true)]
    private ?int $nombreMorceaux = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineLe = null;

    #[ORM\Column(length: 255)]
    private ?string $nomOriginal = null;

    /*
     * Nom physique du fichier enregistré sur le serveur.
     */
    #[ORM\Column(length: 255)]
    private ?string $nomStockage = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $chemin = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $typeMime = null;

    #[ORM\Column(nullable: true)]
    private ?int $taille = null;

    /*
     * Statut technique de l’envoi :
     * EN_ATTENTE, EN_COURS, TERMINE ou ECHEC.
     */
    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_EN_ATTENTE;

    /*
     * Origine métier :
     * client, interne ou externe.
     */
    #[ORM\Column(length: 30)]
    private string $origine = self::ORIGINE_CLIENT;

    /*
     * État prépresse :
     * original, a_traiter, en_traitement,
     * traite, pret_impression ou rejete.
     */
    #[ORM\Column(length: 30)]
    private string $etat = self::ETAT_ORIGINAL;

    /*
     * Indique que le fichier était déjà prêt
     * lors de son ajout.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $dejaTraite = false;

    /*
     * Indique que cette version est celle
     * officiellement transmise à la production.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $fichierProduction = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    /*
     * Quantité attribuée au modèle ou au groupe.
     */
    #[ORM\Column(nullable: true)]
    private ?int $quantiteAProduire = null;

    /*
     * recto, verso, recto-verso, devant, dos ou autre.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $face = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $designation = null;

    /*
     * Permet de regrouper, par exemple,
     * le recto et le verso d’une même carte.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $groupeFichier = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $ajoutePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }

    public function setCommandeDetail(
        ?CommandesDetails $commandeDetail
    ): static {
        $this->commandeDetail = $commandeDetail;

        return $this;
    }

    public function getFichierSource(): ?self
    {
        return $this->fichierSource;
    }

    public function setFichierSource(?self $fichierSource): static
    {
        if ($fichierSource === $this) {
            throw new \LogicException(
                'Un fichier ne peut pas être sa propre source.'
            );
        }

        $this->fichierSource = $fichierSource;

        return $this;
    }

    public function getJetonUpload(): ?string
    {
        return $this->jetonUpload;
    }

    public function setJetonUpload(string $jetonUpload): static
    {
        $this->jetonUpload = $jetonUpload;

        return $this;
    }

    public function getMorceauxRecus(): int
    {
        return $this->morceauxRecus;
    }

    public function setMorceauxRecus(int $morceauxRecus): static
    {
        if ($morceauxRecus < 0) {
            throw new \InvalidArgumentException(
                'Le nombre de morceaux reçus ne peut pas être négatif.'
            );
        }

        $this->morceauxRecus = $morceauxRecus;

        return $this;
    }

    public function incrementerMorceauxRecus(): static
    {
        ++$this->morceauxRecus;

        return $this;
    }

    public function getNombreMorceaux(): ?int
    {
        return $this->nombreMorceaux;
    }

    public function setNombreMorceaux(?int $nombreMorceaux): static
    {
        if ($nombreMorceaux !== null && $nombreMorceaux < 1) {
            throw new \InvalidArgumentException(
                'Le nombre total de morceaux doit être supérieur à zéro.'
            );
        }

        $this->nombreMorceaux = $nombreMorceaux;

        return $this;
    }

    public function getTermineLe(): ?\DateTimeImmutable
    {
        return $this->termineLe;
    }

    public function setTermineLe(
        ?\DateTimeImmutable $termineLe
    ): static {
        $this->termineLe = $termineLe;

        return $this;
    }

    public function getNomOriginal(): ?string
    {
        return $this->nomOriginal;
    }

    public function setNomOriginal(string $nomOriginal): static
    {
        $this->nomOriginal = $nomOriginal;

        return $this;
    }

    public function getNomStockage(): ?string
    {
        return $this->nomStockage;
    }

    public function setNomStockage(string $nomStockage): static
    {
        $this->nomStockage = $nomStockage;

        return $this;
    }

    public function getChemin(): ?string
    {
        return $this->chemin;
    }

    public function setChemin(?string $chemin): static
    {
        $this->chemin = $chemin;

        return $this;
    }

    public function getTypeMime(): ?string
    {
        return $this->typeMime;
    }

    public function setTypeMime(?string $typeMime): static
    {
        $this->typeMime = $typeMime;

        return $this;
    }

    public function getTaille(): ?int
    {
        return $this->taille;
    }

    public function setTaille(?int $taille): static
    {
        if ($taille !== null && $taille < 0) {
            throw new \InvalidArgumentException(
                'La taille du fichier ne peut pas être négative.'
            );
        }

        $this->taille = $taille;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $statutsAutorises = [
            self::STATUT_EN_ATTENTE,
            self::STATUT_EN_COURS,
            self::STATUT_TERMINE,
            self::STATUT_ECHEC,
        ];

        if (!in_array($statut, $statutsAutorises, true)) {
            throw new \InvalidArgumentException(
                sprintf('Statut technique invalide : %s.', $statut)
            );
        }

        $this->statut = $statut;

        return $this;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $originesAutorisees = [
            self::ORIGINE_CLIENT,
            self::ORIGINE_INTERNE,
            self::ORIGINE_EXTERNE,
        ];

        if (!in_array($origine, $originesAutorisees, true)) {
            throw new \InvalidArgumentException(
                sprintf('Origine de fichier invalide : %s.', $origine)
            );
        }

        $this->origine = $origine;

        return $this;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function setEtat(string $etat): static
    {
        $etatsAutorises = [
            self::ETAT_ORIGINAL,
            self::ETAT_A_TRAITER,
            self::ETAT_EN_TRAITEMENT,
            self::ETAT_TRAITE,
            self::ETAT_PRET_IMPRESSION,
            self::ETAT_REJETE,
        ];

        if (!in_array($etat, $etatsAutorises, true)) {
            throw new \InvalidArgumentException(
                sprintf('État prépresse invalide : %s.', $etat)
            );
        }

        $this->etat = $etat;

        return $this;
    }

    public function isDejaTraite(): bool
    {
        return $this->dejaTraite;
    }

    public function setDejaTraite(bool $dejaTraite): static
    {
        $this->dejaTraite = $dejaTraite;

        if ($dejaTraite) {
            $this->etat = self::ETAT_PRET_IMPRESSION;
        } elseif ($this->etat === self::ETAT_PRET_IMPRESSION) {
            $this->etat = self::ETAT_ORIGINAL;
            $this->fichierProduction = false;
        }

        return $this;
    }

    public function isFichierProduction(): bool
    {
        return $this->fichierProduction;
    }

    public function setFichierProduction(
        bool $fichierProduction
    ): static {
        if (
            $fichierProduction
            && $this->etat !== self::ETAT_PRET_IMPRESSION
        ) {
            throw new \LogicException(
                'Seul un fichier prêt pour impression peut être envoyé en production.'
            );
        }

        $this->fichierProduction = $fichierProduction;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        if (!$actif) {
            $this->fichierProduction = false;
        }

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        if ($version < 1) {
            throw new \InvalidArgumentException(
                'Le numéro de version doit être supérieur ou égal à 1.'
            );
        }

        $this->version = $version;

        return $this;
    }

    public function getQuantiteAProduire(): ?int
    {
        return $this->quantiteAProduire;
    }

    public function setQuantiteAProduire(
        ?int $quantiteAProduire
    ): static {
        if (
            $quantiteAProduire !== null
            && $quantiteAProduire < 1
        ) {
            throw new \InvalidArgumentException(
                'La quantité à produire doit être supérieure à zéro.'
            );
        }

        $this->quantiteAProduire = $quantiteAProduire;

        return $this;
    }

    public function getFace(): ?string
    {
        return $this->face;
    }

    public function setFace(?string $face): static
    {
        $this->face = $face;

        return $this;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $this->designation = $designation;

        return $this;
    }

    public function getGroupeFichier(): ?string
    {
        return $this->groupeFichier;
    }

    public function setGroupeFichier(
        ?string $groupeFichier
    ): static {
        $this->groupeFichier = $groupeFichier;

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $this->observation = $observation;

        return $this;
    }

    public function getAjoutePar(): ?User
    {
        return $this->ajoutePar;
    }

    public function setAjoutePar(?User $ajoutePar): static
    {
        $this->ajoutePar = $ajoutePar;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(
        \DateTimeImmutable $creeLe
    ): static {
        $this->creeLe = $creeLe;

        return $this;
    }

    public function estUploadTermine(): bool
    {
        return $this->statut === self::STATUT_TERMINE
            && $this->termineLe !== null;
    }

    public function estPretPourImpression(): bool
    {
        return $this->actif
            && $this->etat === self::ETAT_PRET_IMPRESSION
            && $this->estUploadTermine();
    }

    public function marquerUploadTermine(): static
    {
        $this->statut = self::STATUT_TERMINE;
        $this->termineLe = new \DateTimeImmutable();

        return $this;
    }

    public function marquerCommePretImpression(): static
    {
        if (!$this->estUploadTermine()) {
            throw new \LogicException(
                'Le fichier doit être entièrement envoyé avant sa validation.'
            );
        }

        $this->etat = self::ETAT_PRET_IMPRESSION;

        return $this;
    }
}