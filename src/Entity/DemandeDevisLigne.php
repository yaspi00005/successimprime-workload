<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une ligne du panier de devis du site (docs/site-vitrine.md) : un
 * produit du catalogue publié avec sa taille, sa quantité, ses finitions et
 * son propre fichier, ou un « Autre projet » décrit en quelques mots.
 *
 * Une demande à un seul produit garde ses champs sur DemandeDevis (comme
 * avant le panier) ; les lignes ne servent qu'à partir de deux produits.
 * Elles montent au local avec leur demande (enfants de « demande_devis »
 * dans CatalogueSynchro) ; la ligne du devis brouillon préparée au local
 * reste au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demande_devis_ligne')]
class DemandeDevisLigne
{
    public const DESCRIPTION_MAX = 500;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lignes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?DemandeDevis $demande = null;

    /** 1, 2, 3… dans l'ordre du visiteur. */
    #[ORM\Column]
    private int $position = 1;

    /** Produit choisi ; null pour un « Autre projet » (ou un produit retiré depuis). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProduitConfiguration $configuration = null;

    /** Nom du produit au moment de la demande, ou « Autre projet ». */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $designation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 1, nullable: true)]
    private ?string $largeurCm = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 1, nullable: true)]
    private ?string $hauteurCm = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantite = null;

    /** Surface donnée par le visiteur qui a compté en m² (pièces au m²). */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 2, nullable: true)]
    private ?string $quantiteM2 = null;

    /**
     * Finitions choisies, par l'identifiant public de leur finition du
     * catalogue (le même des deux côtés de la synchronisation).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $finitions = null;

    /** Noms des finitions au moment de la demande (lus par l'équipe même si le catalogue change). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomsFinitions = null;

    /** Ce que le visiteur décrit pour un « Autre projet ». */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Prix indicatif montré au visiteur (tarif public, FCFA) ; null s'il n'a pas pu être calculé. */
    #[ORM\Column(nullable: true)]
    private ?int $prixIndicatif = null;

    /** Fichier de cette ligne, rangé hors de public/ comme ceux de « Mes fichiers ». */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $fichier = null;

    /** Au local seulement : la ligne du devis brouillon qui en est née. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DevisDetails $devisDetail = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDemande(): ?DemandeDevis
    {
        return $this->demande;
    }

    public function setDemande(?DemandeDevis $demande): static
    {
        $this->demande = $demande;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = max(1, $position);

        return $this;
    }

    public function getConfiguration(): ?ProduitConfiguration
    {
        return $this->configuration;
    }

    public function setConfiguration(?ProduitConfiguration $configuration): static
    {
        $this->configuration = $configuration;

        return $this;
    }

    public function estAutreProjet(): bool
    {
        return null === $this->configuration && null !== $this->description;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $designation = trim((string) $designation);
        $this->designation = '' === $designation ? null : mb_substr($designation, 0, 255);

        return $this;
    }

    public function getLargeurCm(): ?float
    {
        return null === $this->largeurCm ? null : (float) $this->largeurCm;
    }

    public function getHauteurCm(): ?float
    {
        return null === $this->hauteurCm ? null : (float) $this->hauteurCm;
    }

    public function setTaille(?float $largeurCm, ?float $hauteurCm): static
    {
        $this->largeurCm = null === $largeurCm ? null : number_format($largeurCm, 1, '.', '');
        $this->hauteurCm = null === $hauteurCm ? null : number_format($hauteurCm, 1, '.', '');

        return $this;
    }

    public function getQuantite(): ?int
    {
        return $this->quantite;
    }

    public function setQuantite(?int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getQuantiteM2(): ?float
    {
        return null === $this->quantiteM2 ? null : (float) $this->quantiteM2;
    }

    public function setQuantiteM2(?float $metresCarres): static
    {
        $this->quantiteM2 = null === $metresCarres ? null : number_format($metresCarres, 2, '.', '');

        return $this;
    }

    /** @return list<string> */
    public function getFinitions(): array
    {
        return array_values(array_filter($this->finitions ?? [], 'is_string'));
    }

    /** Finitions du catalogue choisies : identifiant public et nom. */
    public function setFinitionsChoisies(Finition ...$finitions): static
    {
        $ids = [];
        $noms = [];
        foreach ($finitions as $f) {
            $ids[] = $f->getPublicId()->toRfc4122();
            $noms[] = trim((string) $f->getNom());
        }
        $this->finitions = [] === $ids ? null : $ids;
        $this->nomsFinitions = [] === $noms ? null : mb_substr(implode(', ', $noms), 0, 255);

        return $this;
    }

    public function getNomsFinitions(): ?string
    {
        return $this->nomsFinitions;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = trim((string) $description);
        $this->description = '' === $description ? null : mb_substr($description, 0, self::DESCRIPTION_MAX);

        return $this;
    }

    public function getPrixIndicatif(): ?int
    {
        return $this->prixIndicatif;
    }

    public function setPrixIndicatif(?int $prix): static
    {
        $this->prixIndicatif = null === $prix || $prix <= 0 ? null : $prix;

        return $this;
    }

    public function getFichier(): ?CommandeDetailFichier
    {
        return $this->fichier;
    }

    public function setFichier(?CommandeDetailFichier $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getDevisDetail(): ?DevisDetails
    {
        return $this->devisDetail;
    }

    public function setDevisDetail(?DevisDetails $ligne): static
    {
        $this->devisDetail = $ligne;

        return $this;
    }

    /** « Kakémono 85 × 200 cm × 1 (3,00 m² demandés) » */
    public function getResume(): string
    {
        $morceaux = [];
        $nombre = static fn (float $n): string => number_format($n, floor($n) == $n ? 0 : 1, ',', ' ');
        if (null !== $this->largeurCm && null !== $this->hauteurCm) {
            $morceaux[] = $nombre((float) $this->largeurCm).' × '.$nombre((float) $this->hauteurCm).' cm';
        }
        if (null !== $this->quantite) {
            $morceaux[] = '× '.$this->quantite;
        }
        if (null !== $this->quantiteM2) {
            $morceaux[] = '('.number_format((float) $this->quantiteM2, 2, ',', ' ').' m² demandés)';
        }
        $nom = $this->designation ?? ($this->estAutreProjet() ? 'Autre projet' : '');

        return trim($nom.' '.implode(' ', $morceaux));
    }
}
