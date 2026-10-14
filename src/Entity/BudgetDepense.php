<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Budget mensuel d'une catégorie de dépenses (Finances › Dépenses ›
 * Budgets). Sans ligne en base, le montant par défaut de
 * App\Service\Finances\Budgets s'applique. Table locale, non synchronisée.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_budget_depense_categorie', columns: ['categorie'])]
class BudgetDepense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $categorie;

    #[ORM\Column]
    private int $montantMensuel = 0;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $modifiePar = null;

    public function __construct(string $categorie)
    {
        $this->categorie = $categorie;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCategorie(): string { return $this->categorie; }
    public function getMontantMensuel(): int { return $this->montantMensuel; }
    public function getModifieLe(): \DateTimeImmutable { return $this->modifieLe; }
    public function getModifiePar(): ?User { return $this->modifiePar; }

    public function modifier(int $montant, ?User $par, \DateTimeImmutable $le): static
    {
        $this->montantMensuel = max(0, $montant);
        $this->modifiePar = $par;
        $this->modifieLe = $le;

        return $this;
    }
}
