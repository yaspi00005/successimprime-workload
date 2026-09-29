<?php

namespace App\Entity;

use App\Repository\CommandesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommandesRepository::class)]
#[ORM\Table(name: 'commandes')]
class Commandes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Veuillez sélectionner un client.')]
    private ?Clients $clients = null;

    #[ORM\ManyToOne(inversedBy: 'commandes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $agents = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeInterface $dateCommande = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateLivraison = null;

    /*
     * true si le client est venu récupérer la commande lui-même
     * (retrait au dépôt), plutôt qu'une livraison faite par un
     * livreur.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $recupereParClient = false;

    /*
     * Dernière modification (y compris une modification d'une
     * commande normalement verrouillée, faite par un administrateur).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $modifieLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

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

    #[ORM\Column(options: ['default' => true])]
    private bool $statut = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $etat = true;
    #[ORM\Column(
        name: 'statut_paiement',
        length: 20,
        options: ['default' => 'impayee']
    )]
    private string $statutPaiement = 'impayee';

    /**
     * @var Collection<int, CommandesDetails>
     */
    #[ORM\OneToMany(
        targetEntity: CommandesDetails::class,
        mappedBy: 'commande',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[Assert\Count(
        min: 1,
        minMessage: 'Ajoutez au moins un détail à la commande.'
    )]
    #[Assert\Valid]
    private Collection $commandesDetails;

    /**
     * @var Collection<int, Paiements>
     */
    #[ORM\OneToMany(
        targetEntity: Paiements::class,
        mappedBy: 'commande',
        cascade: ['persist']
    )]
    private Collection $paiements;

    /**
     * @var Collection<int, Factures>
     */
    #[ORM\OneToMany(
        targetEntity: Factures::class,
        mappedBy: 'commande',
        cascade: ['persist']
    )]
    private Collection $factures;

    #[ORM\Column]
    private ?int $resteAPayer = 0;

    #[ORM\Column]
    private ?int $totalPaye = 0;
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $publicId;

    #[ORM\Column(options: ['default' => false])]
    private bool $bonusPlafondApplique = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $montantBonusPlafond = 0;

    public function __construct()
    {
        $this->dateCommande = new \DateTime();
        $this->commandesDetails = new ArrayCollection();
        $this->paiements = new ArrayCollection();
        $this->factures = new ArrayCollection();
        $this->publicId = Uuid::v7();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
    public function getPublicId(): Uuid
    {
        return $this->publicId;
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

    public function getDateCommande(): ?\DateTimeImmutable
    {
        return $this->dateCommande;
    }

    public function setDateCommande(
        ?\DateTimeImmutable $dateCommande
    ): static {
        $this->dateCommande =
            $dateCommande ?? new \DateTimeImmutable();

        return $this;
    }

    public function getDateLivraison(): ?\DateTimeImmutable
    {
        return $this->dateLivraison;
    }

    public function setDateLivraison(
        ?\DateTimeInterface $dateLivraison
    ): static {
        $this->dateLivraison = $dateLivraison;

        return $this;
    }

    public function isRecupereParClient(): bool
    {
        return $this->recupereParClient;
    }

    public function setRecupereParClient(bool $recupereParClient): static
    {
        $this->recupereParClient = $recupereParClient;

        return $this;
    }

    public function getModifieLe(): ?\DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function setModifieLe(
        ?\DateTimeInterface $modifieLe
    ): static {
        $this->modifieLe = $modifieLe;

        return $this;
    }

    public function getModifiePar(): ?User
    {
        return $this->modifiePar;
    }

    public function setModifiePar(?User $modifiePar): static
    {
        $this->modifiePar = $modifiePar;

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

    public function isStatut(): bool
    {
        return $this->statut;
    }

    public function setStatut(bool $statut): static
    {
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
     * Résumé de l'avancement des travaux, calculé à partir du
     * statut réel de chaque ligne (contrairement à $etat, qui est
     * un simple booléen jamais mis à jour après la création).
     *
     * Valeurs possibles :
     * "vide", "preparation", "livraison", "livree", "annulee".
     */
    public function getStatutTravaux(): string
    {
        $lignesActives = $this->commandesDetails->filter(
            static fn (CommandesDetails $ligne): bool =>
                $ligne->getStatutProduction()
                !== CommandesDetails::PRODUCTION_ANNULEE
        );

        if ($lignesActives->isEmpty()) {
            return $this->commandesDetails->isEmpty()
                ? 'vide'
                : 'annulee';
        }

        $enPreparation = [
            CommandesDetails::PRODUCTION_A_PRODUIRE,
            CommandesDetails::PRODUCTION_EN_COURS,
            CommandesDetails::PRODUCTION_TERMINEE,
            CommandesDetails::PRODUCTION_NON_REQUISE,
        ];

        $enLivraison = [
            CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
            CommandesDetails::PRODUCTION_EN_LIVRAISON,
        ];

        $toutesLivrees = true;

        foreach ($lignesActives as $ligne) {
            $statut = $ligne->getStatutProduction();

            if (in_array($statut, $enPreparation, true)) {
                return 'preparation';
            }

            if (in_array($statut, $enLivraison, true)) {
                $toutesLivrees = false;
            }
        }

        return $toutesLivrees ? 'livree' : 'livraison';
    }

    /**
     * @return Collection<int, CommandesDetails>
     */
    public function getCommandesDetails(): Collection
    {
        return $this->commandesDetails;
    }

    public function addCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if (!$this->commandesDetails->contains($commandesDetail)) {
            $this->commandesDetails->add($commandesDetail);
            $commandesDetail->setCommande($this);
        }

        return $this;
    }

    public function removeCommandesDetail(
        CommandesDetails $commandesDetail
    ): static {
        if ($this->commandesDetails->removeElement($commandesDetail)) {
            if ($commandesDetail->getCommande() === $this) {
                $commandesDetail->setCommande(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Paiements>
     */
    public function getPaiements(): Collection
    {
        return $this->paiements;
    }

    public function addPaiement(Paiements $paiement): static
    {
        if (!$this->paiements->contains($paiement)) {
            $this->paiements->add($paiement);
            $paiement->setCommande($this);
        }

        return $this;
    }

    public function removePaiement(Paiements $paiement): static
    {
        if ($this->paiements->removeElement($paiement)) {
            if ($paiement->getCommande() === $this) {
                $paiement->setCommande(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Factures>
     */
    public function getFactures(): Collection
    {
        return $this->factures;
    }

    public function addFacture(Factures $facture): static
    {
        if (!$this->factures->contains($facture)) {
            $this->factures->add($facture);
            $facture->setCommande($this);
        }

        return $this;
    }

    public function removeFacture(Factures $facture): static
    {
        if ($this->factures->removeElement($facture)) {
            if ($facture->getCommande() === $this) {
                $facture->setCommande(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Nouvelle commande';
    }

    public function getResteAPayer(): int
    {
        return max(
            0,
            (int) $this->getTotalTtc() - $this->getTotalPaye()
        );
    }

    public function setResteAPayer(int $resteApayer): static
    {
        $this->resteAPayer = $resteApayer;

        return $this;
    }

    public function getTotalPaye(): int
    {
        $total = 0;

        foreach ($this->paiements as $paiement) {
            $total += (int) $paiement->getMontant();
        }

        return $total;
    }

    public function setTotalPaye(int $totalPaye): static
    {
        $this->totalPaye = $totalPaye;

        return $this;
    }
    public function actualiserStatutPaiement(): static
    {
        $totalTtc = (int) $this->getTotalTtc();
        $totalPaye = $this->getTotalPaye();

        if ($totalPaye <= 0) {
            $this->statutPaiement = 'impayee';
        } elseif ($totalPaye < $totalTtc) {
            $this->statutPaiement = 'partielle';
        } else {
            $this->statutPaiement = 'payee';
        }

        return $this;
    }

    public function getStatutPaiement(): string
    {
        $totalTtc = (int) $this->getTotalTtc();
        $totalPaye = $this->getTotalPaye();

        if ($totalPaye <= 0) {
            return 'impayee';
        }

        if ($totalPaye < $totalTtc) {
            return 'partielle';
        }

        return 'payee';
    }


    public function setStatutPaiement(string $statutPaiement): static
    {
        $statutsAutorises = [
            self::PAIEMENT_IMPAYE,
            self::PAIEMENT_PARTIEL,
            self::PAIEMENT_PAYE,
        ];

        if (!in_array($statutPaiement, $statutsAutorises, true)) {
            throw new \InvalidArgumentException(
                'Statut de paiement invalide.'
            );
        }

        $this->statutPaiement = $statutPaiement;

        return $this;
    }

    public const PAIEMENT_IMPAYE = 'impayee';
    public const PAIEMENT_PARTIEL = 'partielle';
    public const PAIEMENT_PAYE = 'payee';

    public function estVerrouilleeParProduction(): bool
    {
        foreach ($this->getOrdresProduction() as $ordre) {
            if (
                in_array(
                    $ordre->getStatut(),
                    [
                        'en_cours',
                        'terminee',
                    ],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function isBonusPlafondApplique(): bool
    {
        return $this->bonusPlafondApplique;
    }

    public function setBonusPlafondApplique(
        bool $bonusPlafondApplique
    ): static {
        $this->bonusPlafondApplique =
            $bonusPlafondApplique;

        return $this;
    }


    public function getMontantBonusPlafond(): int
    {
        return $this->montantBonusPlafond;
    }

    public function setMontantBonusPlafond(
        int $montantBonusPlafond
    ): static {
        $this->montantBonusPlafond =
            max(
                0,
                $montantBonusPlafond
            );

        return $this;
    }
}
