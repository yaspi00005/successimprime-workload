<?php

namespace App\Entity;

use App\Repository\AchatDetailRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AchatDetailRepository::class)]
class AchatDetail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Achats $achat = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Articles $article = null;

    #[ORM\Column]
    #[Assert\Positive(message: 'La quantité doit être supérieure à zéro.')]
    private int $quantite = 1;

    #[ORM\Column]
    #[Assert\PositiveOrZero(message: 'Le prix unitaire ne peut pas être négatif.')]
    private int $prixUnitaire = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalHt = 0;

    /*
     * La quantité en unité de stock (m², litres…) au moment de la
     * commande : rouleaux × m² par rouleau.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantiteStock = null;

    /*
     * Coût de revient d'une unité de stock rendue à Bamako : prix d'achat
     * ramené au m², plus sa part de transport et de transit.
     */
    #[ORM\Column(nullable: true)]
    private ?int $coutRevientUnitaire = null;

    /*
     * Réception ligne par ligne (maquette SU05) : vide tant que la
     * réception détaillée n'est pas faite, la quantité commandée compte.
     */
    #[ORM\Column(nullable: true)]
    private ?int $quantiteRecue = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $quantiteAbimee = 0;

    /*
     * Quantité comptée sur la facture du fournisseur (vide : la quantité
     * commandée). Le bon état n'est pas une colonne : reçue − abîmée.
     */
    #[ORM\Column(nullable: true)]
    private ?int $quantiteFacturee = null;

    /*
     * Nature de la casse en un mot (« écrasé », « mouillé »), pour le badge
     * et le message au fournisseur.
     */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $casseMotif = null;

    /*
     * Coût de revient prévu à la commande, gardé quand la réception le
     * remplace par le coût réel (frais réels, unités en bon état).
     */
    #[ORM\Column(nullable: true)]
    private ?int $coutRevientPrevu = null;

    /*
     * Entrée en stock de la réception : stock avant, quantité entrée (en
     * unité de stock) et heure. Une ligne déjà entrée ne rentre jamais une
     * seconde fois.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $stockAvant = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $quantiteEntree = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $stockEntreLe = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAchat(): ?Achats
    {
        return $this->achat;
    }

    public function setAchat(?Achats $achat): static
    {
        $this->achat = $achat;

        return $this;
    }

    public function getArticle(): ?Articles
    {
        return $this->article;
    }

    public function setArticle(?Articles $article): static
    {
        $this->article = $article;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(1, $quantite);
        $this->calculerTotalHt();

        return $this;
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(int $prixUnitaire): static
    {
        $this->prixUnitaire = max(0, $prixUnitaire);
        $this->calculerTotalHt();

        return $this;
    }

    public function getTotalHt(): int
    {
        return $this->totalHt;
    }

    public function getQuantiteStock(): ?float
    {
        return $this->quantiteStock !== null ? (float) $this->quantiteStock : null;
    }

    public function setQuantiteStock(?float $quantiteStock): static
    {
        $this->quantiteStock = $quantiteStock !== null ? number_format(max(0.0, $quantiteStock), 3, '.', '') : null;

        return $this;
    }

    public function getCoutRevientUnitaire(): ?int
    {
        return $this->coutRevientUnitaire;
    }

    public function setCoutRevientUnitaire(?int $coutRevientUnitaire): static
    {
        $this->coutRevientUnitaire = $coutRevientUnitaire !== null ? max(0, $coutRevientUnitaire) : null;

        return $this;
    }

    public function getQuantiteRecue(): ?int
    {
        return $this->quantiteRecue;
    }

    public function setQuantiteRecue(?int $quantiteRecue): static
    {
        $this->quantiteRecue = $quantiteRecue !== null ? max(0, $quantiteRecue) : null;

        return $this;
    }

    public function getQuantiteAbimee(): int
    {
        return $this->quantiteAbimee;
    }

    public function setQuantiteAbimee(int $quantiteAbimee): static
    {
        $this->quantiteAbimee = max(0, $quantiteAbimee);

        return $this;
    }

    /**
     * Arrivé en bon état : reçu (la quantité commandée tant que la
     * réception n'est pas faite) moins abîmé.
     */
    public function getQuantiteBonEtat(): int
    {
        return max(0, ($this->quantiteRecue ?? $this->quantite) - $this->quantiteAbimee);
    }

    public function getQuantiteFacturee(): ?int
    {
        return $this->quantiteFacturee;
    }

    public function setQuantiteFacturee(?int $quantiteFacturee): static
    {
        $this->quantiteFacturee = $quantiteFacturee !== null ? max(0, $quantiteFacturee) : null;

        return $this;
    }

    public function getCasseMotif(): ?string
    {
        return $this->casseMotif;
    }

    public function setCasseMotif(?string $casseMotif): static
    {
        $casseMotif = $casseMotif !== null ? trim($casseMotif) : null;
        $this->casseMotif = $casseMotif !== null && $casseMotif !== '' ? mb_substr($casseMotif, 0, 60) : null;

        return $this;
    }

    public function getCoutRevientPrevu(): ?int
    {
        return $this->coutRevientPrevu;
    }

    public function setCoutRevientPrevu(?int $coutRevientPrevu): static
    {
        $this->coutRevientPrevu = $coutRevientPrevu;

        return $this;
    }

    public function getStockAvant(): ?float
    {
        return $this->stockAvant !== null ? (float) $this->stockAvant : null;
    }

    public function getQuantiteEntree(): ?float
    {
        return $this->quantiteEntree !== null ? (float) $this->quantiteEntree : null;
    }

    public function getStockEntreLe(): ?\DateTimeImmutable
    {
        return $this->stockEntreLe;
    }

    /**
     * Note l'entrée en stock de la ligne (une seule fois).
     */
    public function marquerEntreeStock(float $stockAvant, float $quantite, \DateTimeImmutable $le): static
    {
        $this->stockAvant = number_format($stockAvant, 3, '.', '');
        $this->quantiteEntree = number_format(max(0.0, $quantite), 3, '.', '');
        $this->stockEntreLe = $le;

        return $this;
    }

    public function estEntreeEnStock(): bool
    {
        return $this->stockEntreLe !== null;
    }

    private function calculerTotalHt(): void
    {
        $this->totalHt = $this->quantite * $this->prixUnitaire;
    }
}
