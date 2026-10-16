<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Part d'un règlement en ligne : ce qui va à une commande (sa facture, son
 * acompte, son reste), par Orange Money et par l'avoir, avec sa part des
 * frais Orange Money. Elle garde les paiements « en attente » créés à la
 * réception de l'argent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reglement_en_ligne_part')]
class ReglementEnLignePart
{
    public const FACTURE = 'facture';
    public const ACOMPTE = 'acompte';
    public const COMMANDE = 'commande';
    public const SOLDE = 'solde';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'parts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ReglementEnLigne $reglement;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Commandes $commande;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Factures $facture;

    #[ORM\Column(length: 10)]
    private string $nature;

    #[ORM\Column]
    private int $montantOm;

    #[ORM\Column]
    private int $avoir;

    #[ORM\Column]
    private int $fondsSoutien = 0;

    #[ORM\Column]
    private int $fraisRetrait = 0;

    /** Ordre d'affectation : la plus ancienne échéance d'abord. */
    #[ORM\Column]
    private int $rang;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiementOm = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiementAvoir = null;

    public function __construct(ReglementEnLigne $reglement, Commandes $commande, ?Factures $facture, string $nature, int $montantOm, int $avoir, int $fondsSoutien, int $fraisRetrait, int $rang)
    {
        $this->reglement = $reglement;
        $this->commande = $commande;
        $this->facture = $facture;
        $this->nature = \in_array($nature, [self::FACTURE, self::ACOMPTE, self::COMMANDE, self::SOLDE], true) ? $nature : self::COMMANDE;
        $this->montantOm = max(0, $montantOm);
        $this->avoir = max(0, $avoir);
        $this->fondsSoutien = max(0, $fondsSoutien);
        $this->fraisRetrait = max(0, $fraisRetrait);
        $this->rang = $rang;
    }

    public function rattacherPaiements(?Paiements $om, ?Paiements $avoir): void
    {
        $this->paiementOm ??= $om;
        $this->paiementAvoir ??= $avoir;
    }

    public function getMontant(): int
    {
        return $this->montantOm + $this->avoir;
    }

    public function getId(): ?int { return $this->id; }
    public function getReglement(): ReglementEnLigne { return $this->reglement; }
    public function getCommande(): Commandes { return $this->commande; }
    public function getFacture(): ?Factures { return $this->facture; }
    public function getNature(): string { return $this->nature; }
    public function getMontantOm(): int { return $this->montantOm; }
    public function getAvoir(): int { return $this->avoir; }
    public function getFondsSoutien(): int { return $this->fondsSoutien; }
    public function getFraisRetrait(): int { return $this->fraisRetrait; }
    public function getRang(): int { return $this->rang; }
    public function getPaiementOm(): ?Paiements { return $this->paiementOm; }
    public function getPaiementAvoir(): ?Paiements { return $this->paiementAvoir; }
}
