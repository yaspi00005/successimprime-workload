<?php

namespace App\Entity;

use App\Repository\DevisRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: DevisRepository::class)]
#[ORM\Table(name: 'devis')]

class Devis
{
    /*
     * ============================================================
     * ÉMETTEURS
     * ============================================================
     */

    public const EMETTEUR_DREPA = 'drepa';
    public const EMETTEUR_MDG_SUCCESS = 'mdg_success';
    public const EMETTEUR_MDG = 'mdg';

    public const EMETTEURS = [
        self::EMETTEUR_DREPA,
        self::EMETTEUR_MDG_SUCCESS,
        self::EMETTEUR_MDG,
    ];

    public const EMETTEURS_LABELS = [
        self::EMETTEUR_DREPA =>
            'DREPA TECHNOLOGIE',

        self::EMETTEUR_MDG_SUCCESS =>
            'MADIAL GROUP SARL / SUCCESS IMPRIM',

        self::EMETTEUR_MDG =>
            'MADIAL GROUP SARL',
    ];


    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(
        length: 30,
        options: [
            'default' => self::EMETTEUR_MDG_SUCCESS,
        ]
    )]
    #[Assert\Choice(choices: self::EMETTEURS)]
    private string $emetteur = self::EMETTEUR_MDG_SUCCESS;

    #[ORM\ManyToOne(inversedBy: 'devis')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Veuillez sélectionner un client.')]
    private ?Clients $clients = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $agents = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeInterface $dateDevis = null;


    #[ORM\Column(options: ['default' => 0])]
    private int $remise = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $tva = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalHt = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalTtc = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $montantApayer = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $deleted = false;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(
        choices: [
            self::STATUT_BROUILLON,
            self::STATUT_ENVOYE,
            self::STATUT_ACCEPTE,
            self::STATUT_REFUSE,
            self::STATUT_EXPIRE,
            self::STATUT_CONVERTI,
            self::STATUT_ANNULE,
        ],
        message: 'Le statut du devis est invalide.'
    )]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(options: ['default' => true])]
    private bool $etat = true;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(
        name: 'commande_id',
        referencedColumnName: 'id',
        nullable: true,
        unique: true,
        onDelete: 'SET NULL'
    )]
    private ?Commandes $commande = null;

    /**
     * @var Collection<int, DevisDetails>
     */
    #[ORM\OneToMany(
        targetEntity: DevisDetails::class,
        mappedBy: 'devis',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Count(
        min: 1,
        minMessage: 'Ajoutez au moins un détail au devis.'
    )]
    #[Assert\Valid]
    private Collection $devisDetails;


    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $tokenAuthenticite = null;

    /*
     * ============================================================
     * FACTURATION À UN TIERS
     * ============================================================
     *
     * Le demandeur du devis n'est pas toujours celui qui paie
     * (ex : un employé demande, son entreprise règle et reçoit
     * le document). Quand facturerAUnTiers est actif, ces
     * coordonnées remplacent celles du client sur le PDF, sans
     * modifier la fiche client elle-même.
     * ============================================================
     */

    #[ORM\Column(options: ['default' => false])]
    private bool $facturerAUnTiers = false;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $nomFacturation = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseFacturation = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephoneFacturation = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'L’adresse email de facturation n’est pas valide.')]
    private ?string $emailFacturation = null;



    public function __construct()
    {
        $this->dateDevis = new \DateTimeImmutable();
        $this->devisDetails = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getEmetteur(): string
    {
        return $this->emetteur;
    }

    public function setEmetteur(string $emetteur): static
    {
        if (!in_array($emetteur, self::EMETTEURS, true)) {
            throw new \InvalidArgumentException(
                'Émetteur invalide.'
            );
        }

        $this->emetteur = $emetteur;

        return $this;
    }

    public function getEmetteurLabel(): string
    {
        return self::EMETTEURS_LABELS[$this->emetteur]
            ?? $this->emetteur;
    }

    public static function getEmetteursPourFormulaire(): array
    {
        return array_flip(self::EMETTEURS_LABELS);
    }

    public function getClients(): ?Clients
    {
        return $this->clients;
    }

    public function setClients(?Clients $clients): static
    {
        $this->clients = $clients;

        return $this;
    }

    public function getAgents(): ?User
    {
        return $this->agents;
    }

    public function setAgents(?User $agents): static
    {
        $this->agents = $agents;

        return $this;
    }

    public function getDateDevis(): ?\DateTimeImmutable
    {
        return $this->dateDevis;
    }

    public function setDateDevis(
        ?\DateTimeImmutable $dateDevis
    ): static {
        $this->dateDevis =
            $dateDevis ?? new \DateTimeImmutable();

        return $this;
    }


    public function getRemise(): int
    {
        return $this->remise;
    }

    public function setRemise(?int $remise): static
    {
        $this->remise = max(0, $remise ?? 0);

        return $this;
    }

    public function getTva(): int
    {
        return $this->tva;
    }

    public function setTva(?int $tva): static
    {
        $this->tva = max(0, $tva ?? 0);

        return $this;
    }

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function setTotalHt(?int $totalHt): static
    {
        $this->totalHt = max(0, $totalHt ?? 0);

        return $this;
    }

    public function getTotalTtc(): int
    {
        return $this->totalTtc;
    }

    public function setTotalTtc(?int $totalTtc): static
    {
        $this->totalTtc = max(0, $totalTtc ?? 0);

        return $this;
    }

    public function getMontantApayer(): int
    {
        return $this->montantApayer;
    }

    public function setMontantApayer(?int $montantApayer): static
    {
        $this->montantApayer = max(0, $montantApayer ?? 0);

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

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): static
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $statutsAutorises = [
            self::STATUT_BROUILLON,
            self::STATUT_ENVOYE,
            self::STATUT_ACCEPTE,
            self::STATUT_REFUSE,
            self::STATUT_EXPIRE,
            self::STATUT_CONVERTI,
            self::STATUT_ANNULE,
        ];

        if (!in_array($statut, $statutsAutorises, true)) {
            throw new \InvalidArgumentException(
                'Statut de devis invalide.'
            );
        }

        $this->statut = $statut;

        return $this;
    }

    public function isEtat(): bool
    {
        return $this->etat;
    }

    public function setEtat(bool $etat): static
    {
        $this->etat = $etat;

        return $this;
    }

    /**
     * @return Collection<int, DevisDetails>
     */
    public function getDevisDetails(): Collection
    {
        return $this->devisDetails;
    }

    public function addDevisDetail(
        DevisDetails $devisDetail
    ): static {
        if (!$this->devisDetails->contains($devisDetail)) {
            $this->devisDetails->add($devisDetail);
            $devisDetail->setDevis($this);
        }

        return $this;
    }

    public function removeDevisDetail(
        DevisDetails $devisDetail
    ): static {
        if ($this->devisDetails->removeElement($devisDetail)) {
            if ($devisDetail->getDevis() === $this) {
                $devisDetail->setDevis(null);
            }
        }

        return $this;
    }



    public function __toString(): string
    {
        return $this->numero ?? 'Nouveau devis';
    }


    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_ENVOYE = 'envoye';
    public const STATUT_ACCEPTE = 'accepte';
    public const STATUT_REFUSE = 'refuse';
    public const STATUT_EXPIRE = 'expire';
    public const STATUT_CONVERTI = 'converti';
    public const STATUT_ANNULE = 'annule';

    public function getTokenAuthenticite(): ?string
    {
        return $this->tokenAuthenticite;
    }

    public function setTokenAuthenticite(?string $tokenAuthenticite): static
    {
        $this->tokenAuthenticite = $tokenAuthenticite;

        return $this;
    }

    public function genererTokenAuthenticite(): static
    {
        $this->tokenAuthenticite = bin2hex(random_bytes(16));

        return $this;
    }

    /*
     * ============================================================
     * FACTURATION À UN TIERS
     * ============================================================
     */

    public function isFacturerAUnTiers(): bool
    {
        return $this->facturerAUnTiers;
    }

    public function setFacturerAUnTiers(bool $facturerAUnTiers): static
    {
        $this->facturerAUnTiers = $facturerAUnTiers;

        return $this;
    }

    public function getNomFacturation(): ?string
    {
        return $this->nomFacturation;
    }

    public function setNomFacturation(?string $nomFacturation): static
    {
        $this->nomFacturation = $nomFacturation;

        return $this;
    }

    public function getAdresseFacturation(): ?string
    {
        return $this->adresseFacturation;
    }

    public function setAdresseFacturation(?string $adresseFacturation): static
    {
        $this->adresseFacturation = $adresseFacturation;

        return $this;
    }

    public function getTelephoneFacturation(): ?string
    {
        return $this->telephoneFacturation;
    }

    public function setTelephoneFacturation(?string $telephoneFacturation): static
    {
        $this->telephoneFacturation = $telephoneFacturation;

        return $this;
    }

    public function getEmailFacturation(): ?string
    {
        return $this->emailFacturation;
    }

    public function setEmailFacturation(?string $emailFacturation): static
    {
        $this->emailFacturation = $emailFacturation;

        return $this;
    }
}
