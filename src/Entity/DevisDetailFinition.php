<?php

namespace App\Entity;

use App\Repository\DevisDetailsFinitionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use App\Repository\DevisDetailFinitionRepository;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: DevisDetailsFinitionRepository::class)]
#[ORM\Table(name: 'devis_detail_finition')]
#[ORM\UniqueConstraint(
    name: 'uniq_devis_detail_configuration_finition',
    columns: [
        'devis_detail_id',
        'configuration_finition_id',
    ]
)]
class DevisDetailFinition
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Ligne de devis concernée.
     */
    #[ORM\ManyToOne(inversedBy: 'finitions')]
    #[ORM\JoinColumn(
        name: 'devis_detail_id',
        nullable: false,
        onDelete: 'CASCADE'
    )]
    #[Assert\NotNull(
        message: 'La ligne de devis est obligatoire.'
    )]
    private ?DevisDetails $devisDetail = null;

    /**
     * Configuration de finition utilisée en mode automatique.
     *
     * Cette relation reste vide pour une ligne manuelle.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'configuration_finition_id',
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?ProduitConfigurationFinition $configurationFinition = null;

    /**
     * Finition réellement appliquée à la ligne de devis.
     *
     * Elle est obligatoire en mode manuel comme en mode automatique.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        name: 'finition_id',
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    #[Assert\NotNull(
        message: 'La finition est obligatoire.'
    )]
    private ?Finition $finition = null;

    /**
     * Nom de la finition au moment de la   devis.
     *
     * Cet instantané protège les anciennes devis si le nom
     * de la finition est modifié ultérieurement dans le catalogue.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(
        message: 'Le nom de la finition est obligatoire.'
    )]
    private ?string $nomFinition = null;

    /**
     * Indique si la finition était obligatoire lors de la devis.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $obligatoire = false;

    /**
     * Prix unitaire appliqué à la finition au moment de la devis.
     */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero(
        message: 'Le prix appliqué ne peut pas être négatif.'
    )]
    private int $prixApplique = 0;

    /**
     * Mode de calcul enregistré au moment de la devis.
     */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(
        message: 'Le mode de calcul est obligatoire.'
    )]
    #[Assert\Choice(
        choices: [
            'forfait',
            'unite',
            'heure',
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

    /**
     * Quantité utilisée pour calculer la finition.
     */
    #[ORM\Column]
    #[Assert\Positive(
        message: 'La quantité doit être supérieure à zéro.'
    )]
    private int $quantite = 1;

    /**
     * Montant total calculé pour cette finition.
     */
    #[ORM\Column]
    #[Assert\PositiveOrZero(
        message: 'Le montant ne peut pas être négatif.'
    )]
    private int $montant = 0;

    
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevisDetail(): ?DevisDetails
    {
        return $this->devisDetail;
    }

    public function setDevisDetail(
        ?DevisDetails $devisDetail
    ): static {
        $this->devisDetail = $devisDetail;

        return $this;
    }

    public function getConfigurationFinition(): ?ProduitConfigurationFinition
    {
        return $this->configurationFinition;
    }

    public function setConfigurationFinition(
        ?ProduitConfigurationFinition $configurationFinition
    ): static {
        $this->configurationFinition = $configurationFinition;

        /*
         * En mode automatique, nous récupérons automatiquement
         * la finition et les informations tarifaires du catalogue.
         */
        if ($configurationFinition !== null) {
            $this->finition = $configurationFinition->getFinition();

            $this->nomFinition = $configurationFinition
                ->getFinition()
                ?->getNom();

            $this->obligatoire = $configurationFinition
                ->isObligatoire();

            $this->prixApplique = $configurationFinition
                ->getPrix();

            $this->modeCalcul = $configurationFinition
                ->getModeCalcul();
        }

        return $this;
    }

    public function getFinition(): ?Finition
    {
        return $this->finition;
    }

    public function setFinition(?Finition $finition): static
    {
        $this->finition = $finition;

        /*
         * Le nom est copié uniquement lorsqu’une finition
         * est effectivement sélectionnée.
         */
        if ($finition !== null) {
            $this->nomFinition = $finition->getNom();
        }

        return $this;
    }

    public function getNomFinition(): ?string
    {
        return $this->nomFinition;
    }

    public function setNomFinition(string $nomFinition): static
    {
        $this->nomFinition = trim($nomFinition);

        return $this;
    }

    public function isObligatoire(): bool
    {
        return $this->obligatoire;
    }

    public function setObligatoire(bool $obligatoire): static
    {
        $this->obligatoire = $obligatoire;

        return $this;
    }

    public function getPrixApplique(): int
    {
        return $this->prixApplique;
    }

    public function setPrixApplique(int $prixApplique): static
    {
        $this->prixApplique =  $prixApplique ;

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
            'heure',
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
        ) ? $modeCalcul : 'forfait';

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(1, $quantite);

        return $this;
    }

    public function getMontant(): int
    {
        return $this->montant;
    }

    public function setMontant(int $montant): static
    {
        $this->montant = max(0, $montant);

        return $this;
    }

    /**
     * Calcule et enregistre le montant de la finition.
     */
    public function recalculerMontant(
        ?float $surface = null,
        ?float $longueur = null,
        int $nombreFaces = 1,
        int $nombrePoints = 1,
        ?float $nombreHeures = null
    ): int {
        $facteur = match ($this->modeCalcul) {
            'unite',
            'feuille',
            'exemplaire' => $this->quantite,

            'heure' => max(0.0, $nombreHeures ?? 0.0),

            'metre' => max(0.0, $longueur ?? 0.0),

            'metre_carre' => max(0.0, $surface ?? 0.0),

            'face' => max(1, $nombreFaces),

            'point' => max(1, $nombrePoints),

            default => 1,
        };

        $this->montant = (int) round(
            $this->prixApplique * $facteur
        );

        return $this->montant;
    }

    /**
     * Vérifie la cohérence entre la configuration automatique
     * et la finition réellement enregistrée.
     */
    #[Assert\Callback]
    public function validerCoherence(
        ExecutionContextInterface $context
    ): void {
        if (
            $this->configurationFinition !== null
            && $this->configurationFinition->getFinition()
                !== $this->finition
        ) {
            $context
                ->buildViolation(
                    'La finition sélectionnée ne correspond pas à la configuration de finition.'
                )
                ->atPath('finition')
                ->addViolation();
        }

        if (
            $this->configurationFinition !== null
            && !$this->configurationFinition->isActive()
        ) {
            $context
                ->buildViolation(
                    'Cette finition de configuration n’est plus active.'
                )
                ->atPath('configurationFinition')
                ->addViolation();
        }

        if (
            $this->obligatoire
            && $this->finition === null
        ) {
            $context
                ->buildViolation(
                    'Une finition obligatoire ne peut pas être supprimée.'
                )
                ->atPath('finition')
                ->addViolation();
        }
    }

    public function __toString(): string
    {
        return sprintf(
            '%s : %s FCFA',
            $this->nomFinition ?? 'Finition',
            number_format(
                $this->montant,
                0,
                ',',
                ' '
            )
        );
    }
}