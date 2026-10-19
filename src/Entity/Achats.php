<?php

namespace App\Entity;

use App\Entity\Trait\SocieteTrait;
use App\Repository\AchatsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AchatsRepository::class)]
class Achats implements AppartientASociete
{
    use SocieteTrait;

    /*
     * Parcours d'une commande d'achat (docs/achats.md) : brouillon, puis
     * « à valider » par la direction au-dessus du seuil, commandée, en
     * route, reçue. « demande » est l'ancien statut des bons créés avant
     * les écrans Achats : il se lit comme « commandée ». Les valeurs
     * « recu » et « annule » restent celles lues par les Finances et la
     * comptabilité.
     */
    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_A_VALIDER = 'a_valider';
    public const STATUT_COMMANDEE = 'commandee';
    public const STATUT_EN_ROUTE = 'en_route';
    public const STATUT_DEMANDE = 'demande';
    public const STATUT_RECU = 'recu';
    public const STATUT_ANNULE = 'annule';

    public const STATUTS = [
        self::STATUT_BROUILLON,
        self::STATUT_A_VALIDER,
        self::STATUT_COMMANDEE,
        self::STATUT_EN_ROUTE,
        self::STATUT_DEMANDE,
        self::STATUT_RECU,
        self::STATUT_ANNULE,
    ];

    public const LIBELLES_STATUTS = [
        self::STATUT_BROUILLON => 'Brouillon',
        self::STATUT_A_VALIDER => 'À valider',
        self::STATUT_COMMANDEE => 'Commandée',
        self::STATUT_EN_ROUTE => 'En route',
        self::STATUT_DEMANDE => 'Commandée',
        self::STATUT_RECU => 'Reçue',
        self::STATUT_ANNULE => 'Annulée',
    ];

    /*
     * Commandée chez le fournisseur et pas encore arrivée.
     */
    public const STATUTS_EN_COURS = [
        self::STATUT_COMMANDEE,
        self::STATUT_EN_ROUTE,
        self::STATUT_DEMANDE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, nullable: true, unique: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne(inversedBy: 'achats')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Fournisseurs $fournisseur = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: self::STATUTS,
        message: 'Statut d’achat invalide.'
    )]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column]
    private ?\DateTimeImmutable $dateDemande = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateReception = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalHt = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $tva = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalTtc = 0;

    /*
     * Envoi de la commande au fournisseur (après validation s'il en faut une).
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dateCommande = null;

    /*
     * Date d'arrivée promise par le fournisseur : la ponctualité se
     * mesure par rapport à elle.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $datePromise = null;

    /*
     * Frais pour amener la marchandise à Bamako (maquette SU03), répartis
     * sur chaque m² (ou unité) acheté dans le coût de revient.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $fraisTransport = 0;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $fraisTransportLibelle = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fraisTransportBase = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $fraisTransit = 0;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $fraisTransitLibelle = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $fraisTransitBase = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $acomptePourcentage = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $validePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    /*
     * Article dont l'alerte de réapprovisionnement a lancé la commande.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Articles $origineAlerte = null;

    /**
     * @var Collection<int, AchatDetail>
     */
    #[ORM\OneToMany(
        targetEntity: AchatDetail::class,
        mappedBy: 'achat',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $lignes;

    public function __construct()
    {
        $this->lignes = new ArrayCollection();
        $this->dateDemande = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getFournisseur(): ?Fournisseurs
    {
        return $this->fournisseur;
    }

    public function setFournisseur(?Fournisseurs $fournisseur): static
    {
        $this->fournisseur = $fournisseur;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = in_array($statut, self::STATUTS, true)
            ? $statut
            : self::STATUT_BROUILLON;

        return $this;
    }

    public function getLibelleStatut(): string
    {
        return self::LIBELLES_STATUTS[$this->statut] ?? $this->statut;
    }

    /**
     * Encore modifiable : brouillon, ou ancienne demande pas encore reçue.
     */
    public function estEnDemande(): bool
    {
        return \in_array($this->statut, [self::STATUT_BROUILLON, self::STATUT_DEMANDE], true);
    }

    public function estBrouillon(): bool
    {
        return $this->statut === self::STATUT_BROUILLON;
    }

    public function estAValider(): bool
    {
        return $this->statut === self::STATUT_A_VALIDER;
    }

    /**
     * Commandée et pas encore reçue : elle peut être réceptionnée.
     */
    public function estEnCours(): bool
    {
        return \in_array($this->statut, self::STATUTS_EN_COURS, true);
    }

    public function estRecu(): bool
    {
        return $this->statut === self::STATUT_RECU;
    }

    public function estAnnule(): bool
    {
        return $this->statut === self::STATUT_ANNULE;
    }

    public function getDateDemande(): ?\DateTimeImmutable
    {
        return $this->dateDemande;
    }

    public function setDateDemande(\DateTimeImmutable $dateDemande): static
    {
        $this->dateDemande = $dateDemande;

        return $this;
    }

    public function getDateReception(): ?\DateTimeImmutable
    {
        return $this->dateReception;
    }

    public function setDateReception(?\DateTimeImmutable $dateReception): static
    {
        $this->dateReception = $dateReception;

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

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function setTotalHt(int $totalHt): static
    {
        $this->totalHt = max(0, $totalHt);

        return $this;
    }

    public function getTva(): int
    {
        return $this->tva;
    }

    public function setTva(int $tva): static
    {
        $this->tva = max(0, $tva);

        return $this;
    }

    public function getTotalTtc(): int
    {
        return $this->totalTtc;
    }

    public function setTotalTtc(int $totalTtc): static
    {
        $this->totalTtc = max(0, $totalTtc);

        return $this;
    }

    public function getDateCommande(): ?\DateTimeImmutable
    {
        return $this->dateCommande;
    }

    public function setDateCommande(?\DateTimeImmutable $dateCommande): static
    {
        $this->dateCommande = $dateCommande;

        return $this;
    }

    public function getDatePromise(): ?\DateTimeImmutable
    {
        return $this->datePromise;
    }

    public function setDatePromise(?\DateTimeImmutable $datePromise): static
    {
        $this->datePromise = $datePromise?->setTime(0, 0);

        return $this;
    }

    public function getFraisTransport(): int
    {
        return $this->fraisTransport;
    }

    public function setFraisTransport(int $fraisTransport): static
    {
        $this->fraisTransport = max(0, $fraisTransport);

        return $this;
    }

    public function getFraisTransportLibelle(): ?string
    {
        return $this->fraisTransportLibelle;
    }

    public function setFraisTransportLibelle(?string $libelle): static
    {
        $this->fraisTransportLibelle = self::texte($libelle);

        return $this;
    }

    public function getFraisTransportBase(): ?string
    {
        return $this->fraisTransportBase;
    }

    public function setFraisTransportBase(?string $base): static
    {
        $this->fraisTransportBase = self::texte($base);

        return $this;
    }

    public function getFraisTransit(): int
    {
        return $this->fraisTransit;
    }

    public function setFraisTransit(int $fraisTransit): static
    {
        $this->fraisTransit = max(0, $fraisTransit);

        return $this;
    }

    public function getFraisTransitLibelle(): ?string
    {
        return $this->fraisTransitLibelle;
    }

    public function setFraisTransitLibelle(?string $libelle): static
    {
        $this->fraisTransitLibelle = self::texte($libelle);

        return $this;
    }

    public function getFraisTransitBase(): ?string
    {
        return $this->fraisTransitBase;
    }

    public function setFraisTransitBase(?string $base): static
    {
        $this->fraisTransitBase = self::texte($base);

        return $this;
    }

    public function getAcomptePourcentage(): int
    {
        return $this->acomptePourcentage;
    }

    public function setAcomptePourcentage(int $acomptePourcentage): static
    {
        $this->acomptePourcentage = max(0, min(100, $acomptePourcentage));

        return $this;
    }

    /**
     * Marchandise + transport + transit : ce que coûte la commande
     * rendue à Bamako.
     */
    public function getTotalRendu(): int
    {
        return $this->totalTtc + $this->fraisTransport + $this->fraisTransit;
    }

    /**
     * Acompte à verser à la commande, sur la marchandise.
     */
    public function getMontantAcompte(): int
    {
        return (int) round($this->totalTtc * $this->acomptePourcentage / 100);
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function setValidePar(?User $validePar): static
    {
        $this->validePar = $validePar;

        return $this;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function setValideLe(?\DateTimeImmutable $valideLe): static
    {
        $this->valideLe = $valideLe;

        return $this;
    }

    public function getOrigineAlerte(): ?Articles
    {
        return $this->origineAlerte;
    }

    public function setOrigineAlerte(?Articles $origineAlerte): static
    {
        $this->origineAlerte = $origineAlerte;

        return $this;
    }

    /**
     * @return Collection<int, AchatDetail>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(AchatDetail $ligne): static
    {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setAchat($this);
        }

        return $this;
    }

    public function removeLigne(AchatDetail $ligne): static
    {
        if (
            $this->lignes->removeElement($ligne)
            && $ligne->getAchat() === $this
        ) {
            $ligne->setAchat(null);
        }

        return $this;
    }

    /**
     * Recalcule totalHt/totalTtc à partir des lignes actuelles.
     */
    public function recalculerTotaux(): static
    {
        $totalHt = 0;

        foreach ($this->lignes as $ligne) {
            $totalHt += $ligne->getTotalHt();
        }

        $this->totalHt = $totalHt;
        $this->totalTtc = (int) round(
            $totalHt * (1 + $this->tva / 100)
        );

        return $this;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Nouvel achat';
    }

    private static function texte(?string $valeur): ?string
    {
        $valeur = $valeur !== null ? trim($valeur) : null;

        return $valeur !== '' ? $valeur : null;
    }
}
