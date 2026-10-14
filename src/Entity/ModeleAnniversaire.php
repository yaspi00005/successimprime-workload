<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Promotion modèle pour l'anniversaire d'un client (docs/promotions.md) :
 * à partir de N années de fidélité (jusqu'au modèle suivant), les articles
 * choisis, l'avantage, la durée de validité depuis le jour J et le texte du
 * SMS. Le jour J, la tâche app:promotions en tire une Promotion pour le
 * client seul. Reste au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'promotion_anniversaire_modele')]
#[ORM\UniqueConstraint(name: 'uniq_modele_anniversaire_annees', columns: ['annees'])]
class ModeleAnniversaire
{
    /** Texte proposé : sans montant ni pourcentage (règle des messages aux clients). */
    public const TEXTE_PAR_DEFAUT = 'Succès Imprim : joyeux anniversaire {prénom} ! Cela fait {années} que vous nous faites confiance. Un cadeau vous attend en boutique jusqu\'au {fin}.';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** S'applique à partir de ce nombre d'années, jusqu'au modèle suivant. */
    #[ORM\Column]
    private int $annees = 1;

    /** Nom de la promotion créée (vu par le client). */
    #[ORM\Column(length: 120)]
    private string $nom = '';

    /** @var Collection<int, Produits> */
    #[ORM\ManyToMany(targetEntity: Produits::class)]
    #[ORM\JoinTable(name: 'promotion_anniversaire_modele_produit')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $produits;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CategorieProduit $categorie = null;

    #[ORM\Column(length: 12)]
    private string $avantage = Promotion::AVANTAGE_POURCENTAGE;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $valeur = '10';

    /** Jours de validité, jour J compris. */
    #[ORM\Column]
    private int $validiteJours = 30;

    /** Variables : {prénom}, {années}, {fin}. */
    #[ORM\Column(type: Types::TEXT)]
    private string $texteSms = self::TEXTE_PAR_DEFAUT;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $modifieLe;

    public function __construct()
    {
        $this->produits = new ArrayCollection();
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getAnnees(): int { return $this->annees; }
    public function setAnnees(int $annees): static { $this->annees = max(1, min(99, $annees)); return $this; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = mb_substr(trim($nom), 0, 120); return $this; }

    /** @return Collection<int, Produits> */
    public function getProduits(): Collection { return $this->produits; }

    /** @param iterable<Produits> $produits */
    public function remplacerProduits(iterable $produits): static
    {
        $this->produits->clear();
        foreach ($produits as $p) {
            if (!$this->produits->contains($p)) {
                $this->produits->add($p);
            }
        }

        return $this;
    }

    public function getCategorie(): ?CategorieProduit { return $this->categorie; }
    public function setCategorie(?CategorieProduit $categorie): static { $this->categorie = $categorie; return $this; }
    public function getAvantage(): string { return $this->avantage; }
    public function setAvantage(string $avantage): static { $this->avantage = isset(Promotion::AVANTAGES[$avantage]) ? $avantage : Promotion::AVANTAGE_POURCENTAGE; return $this; }
    public function getValeur(): float { return (float) $this->valeur; }
    public function setValeur(float $valeur): static { $this->valeur = number_format(max(0, $valeur), 2, '.', ''); return $this; }
    public function getValiditeJours(): int { return $this->validiteJours; }
    public function setValiditeJours(int $jours): static { $this->validiteJours = max(1, min(365, $jours)); return $this; }
    public function getTexteSms(): string { return $this->texteSms; }
    public function setTexteSms(string $texte): static { $this->texteSms = trim($texte); return $this; }
    public function isActif(): bool { return $this->actif; }
    public function setActif(bool $actif): static { $this->actif = $actif; return $this; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function marquerModifie(\DateTimeImmutable $le): static { $this->modifieLe = $le; return $this; }

    public function getLibelleAvantage(): string
    {
        return Promotion::formaterAvantage($this->avantage, $this->getValeur());
    }

    public function getLibelleArticles(): string
    {
        $noms = array_map(static fn (Produits $p): string => (string) $p->getNom(), $this->produits->toArray());
        if (null !== $this->categorie) {
            array_unshift($noms, 'toute la catégorie '.$this->categorie->getNom());
        }

        return [] === $noms ? '—' : implode(', ', $noms);
    }

    /** Promotion du client pour son anniversaire, du jour $debut au dernier jour de validité. */
    public function creerPromotion(Clients $client, int $annees, \DateTimeImmutable $jourJ, \DateTimeImmutable $debut, ?User $par): Promotion
    {
        $promotion = (new Promotion())
            ->setNom('' !== $this->nom ? $this->nom : 'Anniversaire : '.$annees.' an'.($annees > 1 ? 's' : ''))
            ->setOrigine(Promotion::ORIGINE_ANNIVERSAIRE)
            ->remplacerProduits($this->produits)
            ->setCategorie($this->categorie)
            ->setAvantage($this->avantage)
            ->setValeur($this->getValeur())
            ->setDebut($debut)
            ->setFin($jourJ->modify('+'.($this->validiteJours - 1).' days'))
            ->setSegment(Promotion::SEGMENT_LISTE)
            ->addClient($client)
            ->setUtilisationsParClient(1)
            ->setNote(\sprintf('Anniversaire de %s : %d an%s', $client->getCode(), $annees, $annees > 1 ? 's' : ''))
            ->setCreePar($par);
        $promotion->marquerModifiee($par, new \DateTimeImmutable());

        return $promotion;
    }
}
