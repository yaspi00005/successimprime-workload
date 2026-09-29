<?php

namespace App\Entity;

use App\Repository\EtiquetteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EtiquetteRepository::class)]
#[ORM\Table(name: 'etiquette')]
#[ORM\Index(
    name: 'idx_etiquette_statut',
    columns: ['statut']
)]
#[ORM\Index(
    name: 'idx_etiquette_commande',
    columns: ['commande_id']
)]
#[ORM\Index(
    name: 'idx_etiquette_commande_detail',
    columns: ['commande_detail_id']
)]
class Etiquette
{
    public const STATUT_DISPONIBLE = 'disponible';
    public const STATUT_ASSOCIEE = 'associee';
    public const STATUT_PRETE_LIVRAISON = 'prete_livraison';
    public const STATUT_EN_LIVRAISON = 'en_livraison';
    public const STATUT_LIVREE = 'livree';
    public const STATUT_ANNULEE = 'annulee';
    public const STATUT_PERDUE = 'perdue';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Numéro visible et imprimable.
     * Exemple : ETQ-000001
     */
    #[ORM\Column(length: 50, unique: true)]
    private ?string $numero = null;

    /**
     * Jeton aléatoire et sécurisé présent dans l’URL du QR.
     */
    #[ORM\Column(length: 128, unique: true)]
    private ?string $token = null;

    /**
     * Chemin relatif du fichier PNG.
     */
    #[ORM\Column(length: 255)]
    private ?string $fichierQr = null;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_DISPONIBLE;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imprimeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $associeLe = null;

    /**
     * Lot auquel appartient l’étiquette.
     */
    #[ORM\ManyToOne(
        targetEntity: LotEtiquette::class,
        inversedBy: 'etiquettes'
    )]
    #[ORM\JoinColumn(
        name: 'lot_id',
        referencedColumnName: 'id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?LotEtiquette $lot = null;

    /**
     * Commande associée à l’étiquette.
     *
     * Ce champ doit rester vide lorsque l’étiquette est associée
     * à un détail de commande.
     */
    #[ORM\ManyToOne(targetEntity: Commandes::class)]
    #[ORM\JoinColumn(
        name: 'commande_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Commandes $commande = null;

    /**
     * Détail de commande associé à l’étiquette.
     *
     * Ce champ doit rester vide lorsque l’étiquette est associée
     * directement à une commande.
     */
    #[ORM\ManyToOne(
    targetEntity: CommandesDetails::class,
    inversedBy: 'etiquettes'
)]
#[ORM\JoinColumn(
    name: 'commande_detail_id',
    referencedColumnName: 'id',
    nullable: true,
    onDelete: 'SET NULL'
)]
private ?CommandesDetails $commandeDetail = null;

    /**
     * Utilisateur ayant réalisé l’association.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        name: 'associe_par_id',
        referencedColumnName: 'id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $associePar = null;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
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
        $numero = strtoupper(trim($numero));

        if ($numero === '') {
            throw new \InvalidArgumentException(
                'Le numéro de l’étiquette ne peut pas être vide.'
            );
        }

        $this->numero = $numero;

        return $this;
    }

    public function getToken(): ?string
    {
        return $this->token;
    }

    public function setToken(string $token): static
    {
        $token = trim($token);

        if (strlen($token) < 32) {
            throw new \InvalidArgumentException(
                'Le jeton de sécurité doit contenir au moins 32 caractères.'
            );
        }

        $this->token = $token;

        return $this;
    }

    public function getFichierQr(): ?string
    {
        return $this->fichierQr;
    }

    public function setFichierQr(string $fichierQr): static
    {
        $fichierQr = trim($fichierQr);

        if ($fichierQr === '') {
            throw new \InvalidArgumentException(
                'Le chemin du fichier QR ne peut pas être vide.'
            );
        }

        $this->fichierQr = $fichierQr;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        if (!in_array($statut, self::getStatutsAutorises(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Statut d’étiquette invalide : %s', $statut)
            );
        }

        $this->statut = $statut;

        return $this;
    }

    /**
     * @return list<string>
     */
    public static function getStatutsAutorises(): array
    {
        return [
            self::STATUT_DISPONIBLE,
            self::STATUT_ASSOCIEE,
            self::STATUT_PRETE_LIVRAISON,
            self::STATUT_EN_LIVRAISON,
            self::STATUT_LIVREE,
            self::STATUT_ANNULEE,
            self::STATUT_PERDUE,
        ];
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $creeLe): static
    {
        $this->creeLe = $creeLe;

        return $this;
    }

    public function getImprimeLe(): ?\DateTimeImmutable
    {
        return $this->imprimeLe;
    }

    public function setImprimeLe(
        ?\DateTimeImmutable $imprimeLe
    ): static {
        $this->imprimeLe = $imprimeLe;

        return $this;
    }

    public function getAssocieLe(): ?\DateTimeImmutable
    {
        return $this->associeLe;
    }

    public function setAssocieLe(
        ?\DateTimeImmutable $associeLe
    ): static {
        $this->associeLe = $associeLe;

        return $this;
    }

    public function getLot(): ?LotEtiquette
    {
        return $this->lot;
    }

    public function setLot(?LotEtiquette $lot): static
    {
        $this->lot = $lot;

        return $this;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        if ($commande !== null && $this->commandeDetail !== null) {
            throw new \LogicException(
                'L’étiquette est déjà associée à un détail de commande.'
            );
        }

        $this->commande = $commande;

        return $this;
    }

    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }

    public function setCommandeDetail(
        ?CommandesDetails $commandeDetail
    ): static {
        if ($commandeDetail !== null && $this->commande !== null) {
            throw new \LogicException(
                'L’étiquette est déjà associée directement à une commande.'
            );
        }

        $this->commandeDetail = $commandeDetail;

        return $this;
    }

    public function getAssociePar(): ?User
    {
        return $this->associePar;
    }

    public function setAssociePar(?User $associePar): static
    {
        $this->associePar = $associePar;

        return $this;
    }

    public function estDisponible(): bool
    {
        return $this->statut === self::STATUT_DISPONIBLE
            && $this->commande === null
            && $this->commandeDetail === null;
    }

    public function estAssocieeACommande(): bool
    {
        return $this->commande !== null
            && $this->commandeDetail === null;
    }

    public function estAssocieeADetailCommande(): bool
    {
        return $this->commande === null
            && $this->commandeDetail !== null;
    }

    public function estAssociee(): bool
    {
        return $this->estAssocieeACommande()
            || $this->estAssocieeADetailCommande();
    }

    public function getCibleAssociation(): Commandes|CommandesDetails|null
    {
        return $this->commande ?? $this->commandeDetail;
    }

    public function marquerCommeImprimee(): static
    {
        $this->imprimeLe = new \DateTimeImmutable();

        return $this;
    }

    /**
     * Associe l’étiquette à une commande entière.
     */
    public function associerACommande(
        Commandes $commande,
        User $utilisateur
    ): static {
        $this->verifierDisponibilite();

        $this->commandeDetail = null;
        $this->commande = $commande;
        $this->associePar = $utilisateur;
        $this->associeLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_ASSOCIEE;

        return $this;
    }

    /**
     * Associe l’étiquette à un détail précis de commande.
     */
    public function associerADetailCommande(
        CommandesDetails $commandeDetail,
        User $utilisateur
    ): static {
        $this->verifierDisponibilite();

        $this->commande = null;
        $this->commandeDetail = $commandeDetail;
        $this->associePar = $utilisateur;
        $this->associeLe = new \DateTimeImmutable();
        $this->statut = self::STATUT_ASSOCIEE;

        return $this;
    }

    private function verifierDisponibilite(): void
    {
        if (!$this->estDisponible()) {
            throw new \LogicException(
                sprintf(
                    'L’étiquette %s n’est plus disponible.',
                    $this->numero ?? ''
                )
            );
        }
    }

    public function dissocier(): static
    {
        if ($this->statut !== self::STATUT_ASSOCIEE) {
            throw new \LogicException(
                'Seule une étiquette associée peut être dissociée.'
            );
        }

        $this->statut = self::STATUT_DISPONIBLE;
        $this->commande = null;
        $this->commandeDetail = null;
        $this->associePar = null;
        $this->associeLe = null;

        return $this;
    }

    public function marquerPreteLivraison(): static
    {
        if ($this->statut !== self::STATUT_ASSOCIEE) {
            throw new \LogicException(
                'L’étiquette doit être associée avant la livraison.'
            );
        }

        if (!$this->estAssociee()) {
            throw new \LogicException(
                'Aucune commande ou aucun détail de commande n’est associé.'
            );
        }

        $this->statut = self::STATUT_PRETE_LIVRAISON;

        return $this;
    }

    public function marquerEnLivraison(): static
    {
        if ($this->statut !== self::STATUT_PRETE_LIVRAISON) {
            throw new \LogicException(
                'L’étiquette n’est pas prête pour la livraison.'
            );
        }

        $this->statut = self::STATUT_EN_LIVRAISON;

        return $this;
    }

    public function marquerLivree(): static
    {
        if ($this->statut !== self::STATUT_EN_LIVRAISON) {
            throw new \LogicException(
                'L’étiquette doit être en livraison.'
            );
        }

        $this->statut = self::STATUT_LIVREE;

        return $this;
    }

    public function annuler(): static
    {
        if ($this->statut === self::STATUT_LIVREE) {
            throw new \LogicException(
                'Une étiquette déjà livrée ne peut pas être annulée.'
            );
        }

        $this->statut = self::STATUT_ANNULEE;

        return $this;
    }

    public function marquerPerdue(): static
    {
        if ($this->statut === self::STATUT_LIVREE) {
            throw new \LogicException(
                'Une étiquette déjà livrée ne peut pas être déclarée perdue.'
            );
        }

        $this->statut = self::STATUT_PERDUE;

        return $this;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Nouvelle étiquette';
    }
}