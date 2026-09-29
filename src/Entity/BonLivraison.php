<?php

namespace App\Entity;

use App\Repository\BonLivraisonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BonLivraisonRepository::class)]
#[ORM\Table(name: 'bon_livraison')]
class BonLivraison
{
    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_VALIDE = 'valide';
    public const STATUT_LIVRE = 'livre';
    public const STATUT_ANNULE = 'annule';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Commandes $commande = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $creeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $livreLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $creePar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $validePar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $livrePar = null;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomReceptionnaire = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telephoneReceptionnaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseLivraison = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Articles $articleStock = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $gestionStock = false;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 10,
        scale: 3,
        options: ['default' => '1.000']
    )]
    private string $coefficientStock = '1.000';

    /**
     * @var Collection<int, BonLivraisonLigne>
     */
    #[ORM\OneToMany(
        targetEntity: BonLivraisonLigne::class,
        mappedBy: 'bonLivraison',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $lignes;

    public function __construct()
    {
        $this->creeLe = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
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
        $this->numero = trim($numero);

        return $this;
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

    public function getCreeLe(): ?\DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function getLivreLe(): ?\DateTimeImmutable
    {
        return $this->livreLe;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function getLivrePar(): ?User
    {
        return $this->livrePar;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getNomReceptionnaire(): ?string
    {
        return $this->nomReceptionnaire;
    }

    public function setNomReceptionnaire(
        ?string $nomReceptionnaire
    ): static {
        $this->nomReceptionnaire =
            $this->nettoyer($nomReceptionnaire);

        return $this;
    }

    public function getTelephoneReceptionnaire(): ?string
    {
        return $this->telephoneReceptionnaire;
    }

    public function setTelephoneReceptionnaire(
        ?string $telephoneReceptionnaire
    ): static {
        $this->telephoneReceptionnaire =
            $this->nettoyer($telephoneReceptionnaire);

        return $this;
    }

    public function getAdresseLivraison(): ?string
    {
        return $this->adresseLivraison;
    }

    public function setAdresseLivraison(
        ?string $adresseLivraison
    ): static {
        $this->adresseLivraison =
            $this->nettoyer($adresseLivraison);

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(
        ?string $observation
    ): static {
        $this->observation =
            $this->nettoyer($observation);

        return $this;
    }

    /**
     * @return Collection<int, BonLivraisonLigne>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(
        BonLivraisonLigne $ligne
    ): static {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setBonLivraison($this);
        }

        return $this;
    }

    public function removeLigne(
        BonLivraisonLigne $ligne
    ): static {
        if ($this->lignes->removeElement($ligne)) {
            if ($ligne->getBonLivraison() === $this) {
                $ligne->setBonLivraison(null);
            }
        }

        return $this;
    }

    public function valider(
        User $utilisateur
    ): static {
        if ($this->statut !== self::STATUT_BROUILLON) {
            throw new \LogicException(
                'Seul un bon de livraison en brouillon peut être validé.'
            );
        }

        if ($this->lignes->isEmpty()) {
            throw new \LogicException(
                'Le bon de livraison doit contenir au moins une ligne.'
            );
        }

        $this->statut = self::STATUT_VALIDE;
        $this->valideLe = new \DateTimeImmutable();
        $this->validePar = $utilisateur;

        return $this;
    }

    public function marquerLivre(
        User $utilisateur
    ): static {
        if ($this->statut !== self::STATUT_VALIDE) {
            throw new \LogicException(
                'Le bon de livraison doit être validé avant d’être livré.'
            );
        }

        if ($this->lignes->isEmpty()) {
            throw new \LogicException(
                'Le bon de livraison ne contient aucun article.'
            );
        }

        /*
     * On enregistre les quantités réellement livrées.
     */
        foreach ($this->lignes as $ligne) {
            $detail = $ligne->getCommandeDetail();

            if ($detail === null) {
                throw new \LogicException(
                    'Une ligne du bon n’est plus liée à son détail de commande.'
                );
            }

            $quantiteLivree =
                $ligne->getQuantiteLivree();

            $detail->enregistrerQuantiteLivree(
                $quantiteLivree
            );
        }

        $this->statut =
            self::STATUT_LIVRE;

        $this->livreLe =
            new \DateTimeImmutable();

        $this->livrePar =
            $utilisateur;

        return $this;
    }

    public function annuler(): static
    {
        if ($this->statut === self::STATUT_LIVRE) {
            throw new \LogicException(
                'Un bon de livraison déjà livré ne peut pas être annulé.'
            );
        }

        $this->statut = self::STATUT_ANNULE;

        return $this;
    }

    public function estBrouillon(): bool
    {
        return $this->statut === self::STATUT_BROUILLON;
    }

    public function estValide(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function estLivre(): bool
    {
        return $this->statut === self::STATUT_LIVRE;
    }

    private function nettoyer(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur !== '' ? $valeur : null;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Bon de livraison';
    }


    public function getCoefficientStock(): string
    {
        return $this->coefficientStock;
    }

    public function setCoefficientStock(
        string|int|float $coefficientStock
    ): static {
        $coefficient = (float) $coefficientStock;

        if ($coefficient <= 0) {
            throw new \InvalidArgumentException(
                'Le coefficient de consommation du stock doit être supérieur à zéro.'
            );
        }

        $this->coefficientStock = number_format(
            $coefficient,
            3,
            '.',
            ''
        );

        return $this;
    }

    
}
