<?php

namespace App\Entity;

use App\Repository\ProduitConfigurationFinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(
    repositoryClass: ProduitConfigurationFinitionRepository::class
)]
#[ORM\Table(name: 'produit_configuration_finition')]
#[ORM\UniqueConstraint(
    name: 'uniq_configuration_finition',
    columns: [
        'produit_configuration_id',
        'finition_id',
    ]
)]
#[UniqueEntity(
    fields: ['produitConfiguration', 'finition'],
    message: 'Cette finition existe déjà pour cette configuration.'
)]
class ProduitConfigurationFinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;



    /*
     * La finition doit obligatoirement être appliquée.
     * Exemple : montage dans une structure Roll-up.
     */
    #[ORM\Column]
    private bool $obligatoire = false;

    /*
     * La finition est cochée automatiquement,
     * mais l’utilisateur peut éventuellement la retirer.
     */
    #[ORM\Column]
    private bool $selectionneeParDefaut = false;

    /*
     * Certaines finitions sont incluses gratuitement.
     */
    #[ORM\Column]
    private bool $payante = false;



    #[ORM\Column]
    private bool $active = true;



    #[ORM\ManyToOne(inversedBy: 'configurationFinitions')]
    #[ORM\JoinColumn(
        name: 'produit_configuration_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    #[Assert\NotNull(message: 'La configuration du produit est obligatoire.')]
    private ?ProduitConfiguration $produitConfiguration = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'finition_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    #[Assert\NotNull(message: 'La finition est obligatoire.')]
    private ?Finition $finition = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(
        message: 'Le prix ne peut pas être négatif.'
    )]
    private int $prix = 0;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Le mode de calcul est obligatoire.')]
    #[Assert\Choice(
        choices: [
            'forfait',
            'unite',
            'feuille',
            'exemplaire',
            'metre',
            'metre_carre',
            'point',
            'face',
        ],
        message: 'Le mode de calcul sélectionné est invalide.'
    )]
    private string $modeCalcul = 'forfait';

    #[ORM\Column]
    #[Assert\Positive(
        message: 'La quantité minimale doit être supérieure à zéro.'
    )]
    private int $quantiteMinimale = 1;

    #[ORM\Column(nullable: true)]
    #[Assert\Positive(
        message: 'La quantité maximale doit être supérieure à zéro.'
    )]
    private ?int $quantiteMaximale = null;

    #[ORM\Column]
    #[Assert\PositiveOrZero(
        message: 'L’ordre ne peut pas être négatif.'
    )]
    private int $ordre = 10;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(
        max: 500,
        maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères.'
    )]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduitConfiguration(): ?ProduitConfiguration
    {
        return $this->produitConfiguration;
    }

    public function setProduitConfiguration(
        ?ProduitConfiguration $produitConfiguration
    ): static {
        $this->produitConfiguration = $produitConfiguration;

        return $this;
    }

    public function getFinition(): ?Finition
    {
        return $this->finition;
    }

    public function setFinition(?Finition $finition): static
    {
        $this->finition = $finition;

        return $this;
    }

    public function isObligatoire(): bool
    {
        return $this->obligatoire;
    }

    public function setObligatoire(bool $obligatoire): static
    {
        $this->obligatoire = $obligatoire;

        if ($obligatoire) {
            $this->selectionneeParDefaut = true;
        }

        return $this;
    }

    public function isSelectionneeParDefaut(): bool
    {
        return $this->selectionneeParDefaut;
    }

   public function setSelectionneeParDefaut(
    bool $selectionneeParDefaut
): static {
    $this->selectionneeParDefaut = $this->obligatoire
        ? true
        : $selectionneeParDefaut;

    return $this;
}

    public function isPayante(): bool
    {
        return $this->payante;
    }

    public function setPayante(bool $payante): static
    {
        $this->payante = $payante;

        if (!$payante) {
            $this->prix = 0;
        }

        return $this;
    }

    public function getPrix(): int
    {
        return $this->prix;
    }

    public function setPrix(int $prix): static
    {
        $this->prix = max(0, $prix);
        $this->payante = $this->prix > 0;

        return $this;
    }

    public function getModeCalcul(): string
    {
        return $this->modeCalcul;
    }

    public function setModeCalcul(string $modeCalcul): static
    {
        $modesAutorises = [
            'forfait',
            'unite',
            'feuille',
            'exemplaire',
            'metre',
            'metre_carre',
            'point',
            'face',
        ];

        $modeCalcul = strtolower(trim($modeCalcul));

        $this->modeCalcul = in_array(
            $modeCalcul,
            $modesAutorises,
            true
        )
            ? $modeCalcul
            : 'forfait';

        return $this;
    }

    public function getQuantiteMinimale(): int
    {
        return $this->quantiteMinimale;
    }

    public function setQuantiteMinimale(
        int $quantiteMinimale
    ): static {
        $this->quantiteMinimale = max(1, $quantiteMinimale);

        return $this;
    }

    public function getQuantiteMaximale(): ?int
    {
        return $this->quantiteMaximale;
    }

    public function setQuantiteMaximale(
        ?int $quantiteMaximale
    ): static {
        $this->quantiteMaximale = $quantiteMaximale !== null
            ? max(1, $quantiteMaximale)
            : null;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = max(0, $ordre);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description !== null
            ? trim($description)
            : null;

        return $this;
    }


    public function __toString(): string
    {
        return sprintf(
            '%s — %s',
            $this->produitConfiguration?->__toString()
                ?? 'Configuration',
            $this->finition?->getNom()
                ?? 'Finition'
        );
    }

 

#[Assert\Callback]
public function validerCoherence(
    ExecutionContextInterface $context
): void {
    if (
        $this->quantiteMaximale !== null
        && $this->quantiteMaximale < $this->quantiteMinimale
    ) {
        $context
            ->buildViolation(
                'La quantité maximale doit être supérieure ou égale à la quantité minimale.'
            )
            ->atPath('quantiteMaximale')
            ->addViolation();
    }

    if ($this->obligatoire && !$this->selectionneeParDefaut) {
        $context
            ->buildViolation(
                'Une finition obligatoire doit être sélectionnée par défaut.'
            )
            ->atPath('selectionneeParDefaut')
            ->addViolation();
    }

    if ($this->payante && $this->prix <= 0) {
        $context
            ->buildViolation(
                'Une finition payante doit avoir un prix supérieur à zéro.'
            )
            ->atPath('prix')
            ->addViolation();
    }

    if (!$this->payante && $this->prix !== 0) {
        $context
            ->buildViolation(
                'Une finition gratuite doit avoir un prix égal à zéro.'
            )
            ->atPath('prix')
            ->addViolation();
    }
}
public function accepteQuantite(int $quantite): bool
{
    if ($quantite < $this->quantiteMinimale) {
        return false;
    }

    return $this->quantiteMaximale === null
        || $quantite <= $this->quantiteMaximale;
}
public function calculerMontant(
    int $quantite = 1,
    ?float $surface = null,
    ?float $longueur = null,
    int $nombreFaces = 1,
    int $nombrePoints = 1
): int {
    $quantite = max(1, $quantite);

    if (!$this->active || !$this->payante || $this->prix <= 0) {
        return 0;
    }

    if (!$this->accepteQuantite($quantite)) {
        throw new \DomainException(sprintf(
            'La quantité %d n’est pas autorisée pour cette finition.',
            $quantite
        ));
    }

    return match ($this->modeCalcul) {
        'unite',
        'feuille',
        'exemplaire' => $this->prix * $quantite,

        'metre' => (int) round(
            $this->prix * max(0.0, $longueur ?? 0.0)
        ),

        'metre_carre' => (int) round(
            $this->prix * max(0.0, $surface ?? 0.0)
        ),

        'face' => $this->prix * max(1, $nombreFaces),

        'point' => $this->prix * max(1, $nombrePoints),

        default => $this->prix,
    };
}
}
