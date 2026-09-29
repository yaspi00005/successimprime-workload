<?php

namespace App\Entity;

use App\Repository\PaiementsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PaiementsRepository::class)]
#[ORM\Table(name: 'paiements')]
#[ORM\Index(
    name: 'idx_paiement_commande',
    columns: ['commande_id']
)]
#[ORM\Index(
    name: 'idx_paiement_compte_statut',
    columns: ['compte_tresorerie_id', 'statut']
)]
#[ORM\HasLifecycleCallbacks]
class Paiements
{
    public const MODE_ESPECES = 'especes';
    public const MODE_ORANGE_MONEY = 'orange_money';
    public const MODE_WAVE = 'wave';
    public const MODE_VIREMENT = 'virement_bancaire';
    public const MODE_CHEQUE = 'cheque';
    public const MODE_CARTE = 'carte_bancaire';

    /*
     * Déduction automatique du solde du client (monnaie non
     * rendue) : ne crédite aucun compte de trésorerie, l'argent
     * étant déjà entré en caisse lors d'un précédent paiement.
     * N'apparaît jamais dans le menu "Mode de paiement" du
     * formulaire (voir getModesPourFormulaire()) : ce mode est
     * uniquement déclenché par le bouton "Utiliser ce solde".
     */
    public const MODE_SOLDE_CLIENT = 'solde_client';

    public const MODES = [
        self::MODE_ESPECES,
        self::MODE_ORANGE_MONEY,
        self::MODE_WAVE,
        self::MODE_VIREMENT,
        self::MODE_CHEQUE,
        self::MODE_CARTE,
        self::MODE_SOLDE_CLIENT,
    ];

    public const MODES_LABELS = [
        self::MODE_ESPECES => 'Espèces',
        self::MODE_ORANGE_MONEY => 'Orange Money',
        self::MODE_WAVE => 'Wave',
        self::MODE_VIREMENT => 'Virement bancaire',
        self::MODE_CHEQUE => 'Chèque',
        self::MODE_CARTE => 'Carte bancaire',
        self::MODE_SOLDE_CLIENT => 'Solde client (monnaie)',
    ];

    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_VALIDE = 'valide';
    public const STATUT_REJETE = 'rejete';
    public const STATUT_ANNULE = 'annule';

    public const STATUTS = [
        self::STATUT_EN_ATTENTE,
        self::STATUT_VALIDE,
        self::STATUT_REJETE,
        self::STATUT_ANNULE,
    ];

    public const STATUTS_LABELS = [
        self::STATUT_EN_ATTENTE => 'En attente',
        self::STATUT_VALIDE => 'Validé',
        self::STATUT_REJETE => 'Rejeté',
        self::STATUT_ANNULE => 'Annulé',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    #[ORM\JoinColumn(
        name: 'commande_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    #[Assert\NotNull]
    private ?Commandes $commande = null;

    /*
     * Compte recevant réellement le paiement :
     * caisse, Orange Money, Wave ou compte bancaire.
     *
     * Nullable uniquement pour MODE_SOLDE_CLIENT (voir
     * verifierCompatibiliteCompte()) : aucun compte n'est crédité
     * puisque l'argent est déjà en caisse depuis un paiement
     * antérieur.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'compte_tresorerie_id',
        nullable: true,
        onDelete: 'RESTRICT'
    )]
    private ?CompteTresorerie $compteTresorerie = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\Positive]
    private int $montant = 0;

    #[ORM\Column(length: 30)]
    #[Assert\Choice(choices: self::MODES)]
    private string $mode = self::MODE_ESPECES;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::STATUTS)]
    private string $statut = self::STATUT_EN_ATTENTE;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $date = null;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateValidation = null;

    #[ORM\Column(
        type: Types::DATETIME_IMMUTABLE,
        nullable: true
    )]
    private ?\DateTimeImmutable $dateAnnulation = null;

    /*
     * Référence de transaction, numéro de chèque,
     * numéro de virement ou référence TPE.
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $reference = null;

    /*
     * Informations spécifiques au chèque.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $numeroCheque = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $banqueEmettrice = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $titulaireCheque = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEmissionCheque = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEncaissementPrevue = null;

    /*
     * Chemin relatif du justificatif :
     * reçu, bordereau, photo ou scan du chèque.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificatif = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifRejet = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $motifAnnulation = null;

    #[ORM\ManyToOne(inversedBy: 'paiements')]
    #[ORM\JoinColumn(
        name: 'encaisse_par_id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    #[Assert\NotNull]
    private ?User $encaissePar = null;

    /*
     * Administrateur ou comptable ayant confirmé la réception.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'valide_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $validePar = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'annule_par_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $annulePar = null;

    #[ORM\OneToOne(
        mappedBy: 'paiement',
        targetEntity: MouvementTresorerie::class
    )]
    private ?MouvementTresorerie $mouvementTresorerie = null;

    #[ORM\ManyToOne(
        inversedBy: 'paiements'
    )]
    #[ORM\JoinColumn(
        name: 'facture_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Factures $facture = null;

    /*
     * Frais de retrait / fonds de soutien mobile money (Orange Money,
     * Wave) : le client paie ces frais en plus du prix de la
     * commande. Ils ne comptent JAMAIS dans $montant (qui reste
     * plafonné au reste à payer de la commande) -- voir
     * getMontantTotalEncaisse().
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $fraisRetraitInclus = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $fondsSoutienInclus = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $montantFraisRetrait = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $montantFondsSoutien = 0;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCompteTresorerie(): ?CompteTresorerie
    {
        return $this->compteTresorerie;
    }

    public function setCompteTresorerie(
        ?CompteTresorerie $compteTresorerie
    ): static {
        $this->compteTresorerie = $compteTresorerie;

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(?int $montant): static
    {
        $montant ??= 0;

        if ($montant <= 0) {
            throw new \InvalidArgumentException(
                'Le montant du paiement doit être supérieur à zéro.'
            );
        }

        $this->montant = $montant;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $mode = strtolower(trim($mode));

        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Le mode de paiement "%s" est invalide.', $mode)
            );
        }

        $this->mode = $mode;

        return $this;
    }

    public function getModeLabel(): string
    {
        return self::MODES_LABELS[$this->mode] ?? $this->mode;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getDateValidation(): ?\DateTimeImmutable
    {
        return $this->dateValidation;
    }

    public function getDateAnnulation(): ?\DateTimeImmutable
    {
        return $this->dateAnnulation;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $reference = $reference !== null
            ? trim($reference)
            : null;

        $this->reference = $reference !== ''
            ? $reference
            : null;

        return $this;
    }

    public function getNumeroCheque(): ?string
    {
        return $this->numeroCheque;
    }

    public function setNumeroCheque(?string $numeroCheque): static
    {
        $numeroCheque = $numeroCheque !== null
            ? trim($numeroCheque)
            : null;

        $this->numeroCheque = $numeroCheque !== ''
            ? $numeroCheque
            : null;

        return $this;
    }

    public function getBanqueEmettrice(): ?string
    {
        return $this->banqueEmettrice;
    }

    public function setBanqueEmettrice(
        ?string $banqueEmettrice
    ): static {
        $banqueEmettrice = $banqueEmettrice !== null
            ? trim($banqueEmettrice)
            : null;

        $this->banqueEmettrice = $banqueEmettrice !== ''
            ? $banqueEmettrice
            : null;

        return $this;
    }

    public function getTitulaireCheque(): ?string
    {
        return $this->titulaireCheque;
    }

    public function setTitulaireCheque(
        ?string $titulaireCheque
    ): static {
        $titulaireCheque = $titulaireCheque !== null
            ? trim($titulaireCheque)
            : null;

        $this->titulaireCheque = $titulaireCheque !== ''
            ? $titulaireCheque
            : null;

        return $this;
    }

    public function getDateEmissionCheque(): ?\DateTimeImmutable
    {
        return $this->dateEmissionCheque;
    }

    public function setDateEmissionCheque(
        ?\DateTimeImmutable $dateEmissionCheque
    ): static {
        $this->dateEmissionCheque = $dateEmissionCheque;

        return $this;
    }

    public function getDateEncaissementPrevue(): ?\DateTimeImmutable
    {
        return $this->dateEncaissementPrevue;
    }

    public function setDateEncaissementPrevue(
        ?\DateTimeImmutable $dateEncaissementPrevue
    ): static {
        $this->dateEncaissementPrevue = $dateEncaissementPrevue;

        return $this;
    }

    public function getJustificatif(): ?string
    {
        return $this->justificatif;
    }

    public function setJustificatif(?string $justificatif): static
    {
        $justificatif = $justificatif !== null
            ? trim($justificatif)
            : null;

        $this->justificatif = $justificatif !== ''
            ? $justificatif
            : null;

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $observation = $observation !== null
            ? trim($observation)
            : null;

        $this->observation = $observation !== ''
            ? $observation
            : null;

        return $this;
    }

    public function getMotifRejet(): ?string
    {
        return $this->motifRejet;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    public function getEncaissePar(): ?User
    {
        return $this->encaissePar;
    }

    public function setEncaissePar(?User $encaissePar): static
    {
        $this->encaissePar = $encaissePar;

        return $this;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function getAnnulePar(): ?User
    {
        return $this->annulePar;
    }

    public function estEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    public function estValide(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function estRejete(): bool
    {
        return $this->statut === self::STATUT_REJETE;
    }

    public function estAnnule(): bool
    {
        return $this->statut === self::STATUT_ANNULE;
    }

    /*
     * Les espèces et les paiements mobiles peuvent être validés
     * immédiatement. Les opérations bancaires doivent être contrôlées.
     */
    public function necessiteValidationManuelle(): bool
    {
        return in_array(
            $this->mode,
            [
                self::MODE_VIREMENT,
                self::MODE_CHEQUE,
                self::MODE_CARTE,
            ],
            true
        );
    }

    public function estCheque(): bool
    {
        return $this->mode === self::MODE_CHEQUE;
    }

    /*
     * Vérifie que le type du compte correspond au mode choisi.
     */
    public function verifierCompatibiliteCompte(): void
    {
        /*
         * Solde client : aucun compte de trésorerie n'est
         * concerné, l'argent étant déjà en caisse depuis un
         * paiement antérieur.
         */
        if ($this->mode === self::MODE_SOLDE_CLIENT) {
            if ($this->compteTresorerie !== null) {
                throw new \LogicException(
                    'Un paiement par solde client ne doit pas être associé à un compte de trésorerie.'
                );
            }

            return;
        }

        if ($this->compteTresorerie === null) {
            throw new \LogicException(
                'Le compte de trésorerie est obligatoire.'
            );
        }

        $typeCompte = $this->compteTresorerie->getType();

        $typesAutorises = match ($this->mode) {
            self::MODE_ESPECES => [
                CompteTresorerie::TYPE_CAISSE,
            ],
            self::MODE_ORANGE_MONEY => [
                CompteTresorerie::TYPE_ORANGE_MONEY,
            ],
            self::MODE_WAVE => [
                CompteTresorerie::TYPE_WAVE,
            ],
            self::MODE_VIREMENT,
            self::MODE_CHEQUE,
            self::MODE_CARTE => [
                CompteTresorerie::TYPE_BANQUE,
            ],
            default => [],
        };

        if (!in_array($typeCompte, $typesAutorises, true)) {
            throw new \LogicException(
                sprintf(
                    'Le compte "%s" n’est pas compatible avec le mode "%s".',
                    $this->compteTresorerie->getNom(),
                    $this->getModeLabel()
                )
            );
        }
    }

    /*
     * Cette méthode change uniquement le statut du paiement.
     * Le service de paiement créera ensuite le mouvement créditeur.
     */
    public function validerPar(User $utilisateur): static
    {
        if (!$this->estEnAttente()) {
            throw new \LogicException(
                'Seul un paiement en attente peut être validé.'
            );
        }

        if ($this->montant <= 0) {
            throw new \LogicException(
                'Le montant du paiement doit être supérieur à zéro.'
            );
        }

        $this->verifierCompatibiliteCompte();

        $this->statut = self::STATUT_VALIDE;
        $this->validePar = $utilisateur;
        $this->dateValidation = new \DateTimeImmutable();
        $this->motifRejet = null;

        return $this;
    }

    public function rejeter(
        string $motif,
        User $utilisateur
    ): static {
        if (!$this->estEnAttente()) {
            throw new \LogicException(
                'Seul un paiement en attente peut être rejeté.'
            );
        }

        $motif = trim($motif);

        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif du rejet est obligatoire.'
            );
        }

        $this->statut = self::STATUT_REJETE;
        $this->motifRejet = $motif;
        $this->validePar = $utilisateur;
        $this->dateValidation = new \DateTimeImmutable();

        return $this;
    }

    /*
     * Le service créera un mouvement inverse avant d’appeler
     * cette méthode pour un paiement déjà validé.
     */
    public function annuler(
        string $motif,
        User $utilisateur
    ): static {
        if (!$this->estValide()) {
            throw new \LogicException(
                'Seul un paiement validé peut être annulé.'
            );
        }

        $motif = trim($motif);

        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif de l’annulation est obligatoire.'
            );
        }

        $this->statut = self::STATUT_ANNULE;
        $this->motifAnnulation = $motif;
        $this->annulePar = $utilisateur;
        $this->dateAnnulation = new \DateTimeImmutable();

        return $this;
    }

    public static function getModesPourFormulaire(): array
    {
        /*
         * MODE_SOLDE_CLIENT n'est jamais un choix manuel : il est
         * uniquement déclenché par le bouton "Utiliser ce solde".
         */
        $labels = self::MODES_LABELS;

        unset($labels[self::MODE_SOLDE_CLIENT]);

        return array_flip($labels);
    }

    public static function getStatutsPourFormulaire(): array
    {
        return array_flip(self::STATUTS_LABELS);
    }

    #[ORM\PrePersist]
    public function verifierAvantEnregistrement(): void
    {
        $this->date ??= new \DateTimeImmutable();
        $this->verifierCompatibiliteCompte();

        if (
            $this->mode === self::MODE_CHEQUE
            && empty($this->numeroCheque)
        ) {
            throw new \LogicException(
                'Le numéro du chèque est obligatoire.'
            );
        }
    }

    public function __toString(): string
    {
        return sprintf(
            '%s — %s FCFA — %s',
            $this->getModeLabel(),
            number_format($this->montant, 0, ',', ' '),
            $this->getStatutLabel()
        );
    }
    public function getMouvementTresorerie(): ?MouvementTresorerie
    {
        return $this->mouvementTresorerie;
    }

    public function setMouvementTresorerie(
        ?MouvementTresorerie $mouvementTresorerie
    ): static {
        $this->mouvementTresorerie = $mouvementTresorerie;

        if (
            $mouvementTresorerie !== null
            && $mouvementTresorerie->getPaiement() !== $this
        ) {
            $mouvementTresorerie->setPaiement($this);
        }

        return $this;
    }
    public function getFacture(): ?Factures
    {
        return $this->facture;
    }

    public function setFacture(
        ?Factures $facture
    ): static {
        $this->facture = $facture;

        return $this;
    }

    public function isFraisRetraitInclus(): bool
    {
        return $this->fraisRetraitInclus;
    }

    public function setFraisRetraitInclus(bool $fraisRetraitInclus): static
    {
        $this->fraisRetraitInclus = $fraisRetraitInclus;

        return $this;
    }

    public function isFondsSoutienInclus(): bool
    {
        return $this->fondsSoutienInclus;
    }

    public function setFondsSoutienInclus(bool $fondsSoutienInclus): static
    {
        $this->fondsSoutienInclus = $fondsSoutienInclus;

        return $this;
    }

    public function getMontantFraisRetrait(): int
    {
        return $this->montantFraisRetrait;
    }

    public function setMontantFraisRetrait(int $montantFraisRetrait): static
    {
        $this->montantFraisRetrait = max(0, $montantFraisRetrait);

        return $this;
    }

    public function getMontantFondsSoutien(): int
    {
        return $this->montantFondsSoutien;
    }

    public function setMontantFondsSoutien(int $montantFondsSoutien): static
    {
        $this->montantFondsSoutien = max(0, $montantFondsSoutien);

        return $this;
    }

    /*
     * Montant réellement encaissé sur le compte de trésorerie :
     * la part appliquée à la commande, plus les frais mobile money
     * éventuels. C'est ce montant qui crédite le compte -- $montant
     * seul reste la référence pour le solde de la commande.
     */
    public function getMontantTotalEncaisse(): int
    {
        return $this->montant
            + $this->montantFraisRetrait
            + $this->montantFondsSoutien;
    }
}
