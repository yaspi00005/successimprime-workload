<?php

namespace App\Entity;

use App\Entity\Trait\CodePromoDocumentTrait;
use App\Entity\Trait\HorsImprimerieTrait;
use App\Entity\Trait\LivraisonObjetDocumentTrait;
use App\Entity\Trait\RemiseGlobaleDocumentTrait;
use App\Entity\Trait\SocieteTrait;
use App\Repository\CommandesRepository;
use App\Service\TotauxDocument;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommandesRepository::class)]
#[ORM\Table(name: 'commandes')]
#[ORM\Index(name: 'idx_commandes_canal_date', columns: ['canal', 'date_commande'])]
class Commandes implements AppartientASociete
{
    use SocieteTrait;
    use CodePromoDocumentTrait;
    use LivraisonObjetDocumentTrait;
    use HorsImprimerieTrait;
    use RemiseGlobaleDocumentTrait;

    /*
     * Canal de la commande : saisie au comptoir par un agent,
     * ou passée par le client en ligne (espace client, J5).
     */
    public const LIVRAISON_RETRAIT = 'retrait';
    public const LIVRAISON_DOMICILE = 'livraison';

    public const CANAL_COMPTOIR = 'comptoir';
    public const CANAL_EN_LIGNE = 'en_ligne';

    public const VALIDATION_A_FAIRE = 'a_valider';
    public const VALIDATION_ACCEPTEE = 'validee';
    public const VALIDATION_REFUSEE = 'refusee';

    public const DELAI_STANDARD = 'standard';
    public const DELAI_EXPRESS = 'express';

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

    /** Vide pour une commande passée en ligne par le client (espace client, synchronisation). */
    #[ORM\ManyToOne(inversedBy: 'commandes')]
    #[ORM\JoinColumn(nullable: true)]
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

    #[ORM\Column(length: 20, options: ['default' => self::CANAL_COMPTOIR])]
    private string $canal = self::CANAL_COMPTOIR;

    /*
     * Choisi à la saisie (maquette C01) : retrait à l'imprimerie ou
     * livraison chez le client. À ne pas confondre avec
     * $recupereParClient, qui note le retrait effectif.
     */
    #[ORM\Column(length: 20, options: ['default' => self::LIVRAISON_RETRAIT])]
    private string $modeLivraison = self::LIVRAISON_RETRAIT;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseLivraison = null;

    /*
     * Délai demandé par le client en ligne (docs/espace-client.md,
     * « Nouvelle commande ») : standard ou express. null pour une commande
     * saisie au comptoir. La majoration express est une ligne libre de la
     * commande, jamais un prix de ligne gonflé.
     */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $delaiProduction = null;

    /** Créneau de livraison choisi en ligne, en clair (« Lun. 19/10, matin »). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $creneauLivraison = null;

    /**
     * Livraison programmée par le client dans son espace (maquette DB09) :
     * adresse, repère, position, jour, créneau, qui reçoit (et si absent),
     * paiement du reste. Appliquée au bon de livraison au local
     * (ProgrammationLivraison::appliquer).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $programmationLivraison = null;

    /** Numéro du bon de commande du client (entreprise). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $bonCommande = null;

    /**
     * Commande en ligne d'un demandeur d'une entreprise (maquette 19) :
     * a_valider, puis validee ou refusee par un valideur de l'entreprise.
     * null : pas de validation interne demandée.
     */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $validationEntreprise = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $demandeePar = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $valideePar = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifRefusEntreprise = null;

    /** Compte de l'espace client qui a passé la commande (reste sur le site). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteClient $compteDemandeur = null;

    /*
     * Solde d'ouverture (docs/import-donnees.md) : ce que le client devait
     * au démarrage, repris par l'import. Sans ligne ni facture, ce n'est
     * pas une vente : il compte dans son solde, les créances et le plafond,
     * et se règle comme une commande, mais il est écarté du chiffre
     * d'affaires, des statistiques, de la fidélité, des promotions, des
     * primes et de l'activité (voir estSoldeOuverture()).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $soldeOuverture = false;

    public function __construct()
    {
        $this->dateCommande = new \DateTimeImmutable();
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

    /** Seuls les paiements validés comptent : un chèque en attente ou un paiement rejeté n'est pas encaissé. */
    public function getTotalPaye(): int
    {
        $total = 0;

        foreach ($this->paiements as $paiement) {
            if ($paiement->estValide()) {
                $total += (int) $paiement->getMontant();
            }
        }

        return $total;
    }

    public function setTotalPaye(int $totalPaye): static
    {
        $this->totalPaye = $totalPaye;

        return $this;
    }
    /**
     * Totaux de la commande depuis ses lignes non annulees, comme le
     * formulaire les calcule (TotauxDocument) : sous-total HT (lignes et
     * frais de livraison, sans TVA), remise globale deduite du HT, TVA des
     * lignes au prorata, total TTC = ce que le client doit. Pour les
     * commandes creees hors formulaire (« Mes produits ») et, a chaque
     * enregistrement du formulaire, pour ne jamais garder les totaux
     * envoyes par le navigateur.
     */
    public function recalculerTotaux(): static
    {
        $ht = $this->getFraisLivraison();
        $tva = 0;
        $code = 0;

        foreach ($this->commandesDetails as $ligne) {
            if (CommandesDetails::PRODUCTION_ANNULEE === $ligne->getStatutProduction()) {
                continue;
            }

            $ht += $ligne->getTotalHt();
            $tva += $ligne->getTotalTtc() - $ligne->getTotalHt();
            $code += $ligne->getRemiseCodePromo();
        }

        $totaux = TotauxDocument::calculer($ht, $tva, $this->getRemise());

        $this->setTotalHt($totaux['sousTotalHt']);
        $this->setRemise($totaux['remise']);
        $this->setRemiseCodePromo($code);
        $this->setTotalTtc($totaux['totalTtc']);
        // Montant a payer : le total TTC, remise deja deduite (ancienne copie gardee pour la synchronisation).
        $this->setMontantApayer($totaux['totalTtc']);
        $this->setResteAPayer(max(0, $totaux['totalTtc'] - $this->getTotalPaye()));

        return $this->actualiserStatutPaiement();
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

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = $canal;

        return $this;
    }

    public function isEnLigne(): bool
    {
        return $this->canal === self::CANAL_EN_LIGNE;
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

    public function getModeLivraison(): string
    {
        return $this->modeLivraison;
    }

    public function setModeLivraison(?string $mode): static
    {
        $this->modeLivraison = self::LIVRAISON_DOMICILE === $mode ? self::LIVRAISON_DOMICILE : self::LIVRAISON_RETRAIT;

        return $this;
    }

    public function estALivrer(): bool
    {
        return self::LIVRAISON_DOMICILE === $this->modeLivraison;
    }

    public function getAdresseLivraison(): ?string
    {
        return $this->adresseLivraison;
    }

    public function setAdresseLivraison(?string $adresse): static
    {
        $adresse = null === $adresse ? null : trim($adresse);
        $this->adresseLivraison = '' === $adresse ? null : $adresse;

        return $this;
    }

    public function getDelaiProduction(): ?string
    {
        return $this->delaiProduction;
    }

    public function setDelaiProduction(?string $delai): static
    {
        $this->delaiProduction = \in_array($delai, [self::DELAI_STANDARD, self::DELAI_EXPRESS], true) ? $delai : null;

        return $this;
    }

    public function estExpress(): bool
    {
        return self::DELAI_EXPRESS === $this->delaiProduction;
    }

    public function getCreneauLivraison(): ?string
    {
        return $this->creneauLivraison;
    }

    public function setCreneauLivraison(?string $creneau): static
    {
        $creneau = null !== $creneau ? mb_substr(trim($creneau), 0, 60) : null;
        $this->creneauLivraison = '' === $creneau ? null : $creneau;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getProgrammationLivraison(): ?array
    {
        return $this->programmationLivraison;
    }

    /** @param array<string, mixed>|null $programmation */
    public function setProgrammationLivraison(?array $programmation): static
    {
        $this->programmationLivraison = $programmation;

        return $this;
    }

    public function getBonCommande(): ?string
    {
        return $this->bonCommande;
    }

    public function setBonCommande(?string $numero): static
    {
        $numero = null !== $numero ? mb_substr(trim($numero), 0, 60) : null;
        $this->bonCommande = '' === $numero ? null : $numero;

        return $this;
    }

    public function getValidationEntreprise(): ?string
    {
        return $this->validationEntreprise;
    }

    public function setValidationEntreprise(?string $etat): static
    {
        $this->validationEntreprise = $etat;

        return $this;
    }

    public function attendValidationEntreprise(): bool
    {
        return self::VALIDATION_A_FAIRE === $this->validationEntreprise;
    }

    public function estRefuseeParEntreprise(): bool
    {
        return self::VALIDATION_REFUSEE === $this->validationEntreprise;
    }

    public function getDemandeePar(): ?string
    {
        return $this->demandeePar;
    }

    public function setDemandeePar(?string $nom): static
    {
        $this->demandeePar = null !== $nom ? mb_substr($nom, 0, 80) : null;

        return $this;
    }

    public function getValideePar(): ?string
    {
        return $this->valideePar;
    }

    public function setValideePar(?string $nom): static
    {
        $this->valideePar = null !== $nom ? mb_substr($nom, 0, 80) : null;

        return $this;
    }

    public function getMotifRefusEntreprise(): ?string
    {
        return $this->motifRefusEntreprise;
    }

    public function setMotifRefusEntreprise(?string $motif): static
    {
        $motif = null !== $motif ? mb_substr(trim($motif), 0, 255) : null;
        $this->motifRefusEntreprise = '' === $motif ? null : $motif;

        return $this;
    }

    public function getCompteDemandeur(): ?CompteClient
    {
        return $this->compteDemandeur;
    }

    public function setCompteDemandeur(?CompteClient $compte): static
    {
        $this->compteDemandeur = $compte;

        return $this;
    }

    public function isSoldeOuverture(): bool
    {
        return $this->soldeOuverture;
    }

    public function estSoldeOuverture(): bool
    {
        return $this->soldeOuverture;
    }

    /**
     * Commande « Solde d'ouverture » : le montant dû au démarrage, sans
     * ligne, sans agent, sans facture. Seul l'import la crée. Datée du jour
     * d'ouverture ; son échéance (date de livraison, comme pour toute
     * commande sans facture) est celle du fichier, sinon ce même jour.
     */
    public static function soldeOuverture(Clients $client, int $montant, \DateTimeImmutable $date, string $numero, ?string $note = null, ?\DateTimeImmutable $echeance = null): self
    {
        if ($montant <= 0) {
            throw new \InvalidArgumentException('Le solde d\'ouverture doit être positif.');
        }
        $c = new self();
        $c->soldeOuverture = true;
        $c->numero = $numero;
        $c->clients = $client;
        $c->dateCommande = $date;
        $c->dateLivraison = $echeance ?? $date;
        // Aucun travail à faire : rien n'apparaît à l'atelier.
        $c->etat = false;
        $c->totalHt = $montant;
        $c->totalTtc = $montant;
        $c->montantApayer = $montant;
        $c->resteAPayer = $montant;
        $c->observation = $note;

        return $c;
    }

    /** Libellé à l'écran : « Solde d'ouverture » plutôt qu'un numéro de commande. */
    public function getLibelle(): string
    {
        return $this->soldeOuverture ? 'Solde d\'ouverture' : ($this->numero ?? 'Nouvelle commande');
    }
}
