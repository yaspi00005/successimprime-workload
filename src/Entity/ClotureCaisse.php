<?php

namespace App\Entity;

use App\Entity\Trait\SocieteTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Clôture d'une caisse (maquette 28) : l'argent compté en fin de journée
 * face au théorique calculé par le journal. L'écart est seulement noté ;
 * le responsable décide de l'ajustement.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_cloture_caisse_date', columns: ['cloture_le'])]
class ClotureCaisse implements AppartientASociete
{
    use SocieteTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?CompteTresorerie $compte = null;

    #[ORM\Column]
    private \DateTimeImmutable $clotureLe;

    #[ORM\Column]
    private int $montantTheorique = 0;

    #[ORM\Column]
    private int $montantCompte = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $cloturePar = null;

    public function __construct()
    {
        $this->clotureLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompte(): ?CompteTresorerie
    {
        return $this->compte;
    }

    public function setCompte(CompteTresorerie $compte): static
    {
        $this->compte = $compte;

        return $this;
    }

    public function getClotureLe(): \DateTimeImmutable
    {
        return $this->clotureLe;
    }

    public function getMontantTheorique(): int
    {
        return $this->montantTheorique;
    }

    public function setMontantTheorique(int $montant): static
    {
        $this->montantTheorique = $montant;

        return $this;
    }

    public function getMontantCompte(): int
    {
        return $this->montantCompte;
    }

    public function setMontantCompte(int $montant): static
    {
        $this->montantCompte = $montant;

        return $this;
    }

    /** Positif : plus d'argent que prévu ; négatif : il manque de l'argent. */
    public function getEcart(): int
    {
        return $this->montantCompte - $this->montantTheorique;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(?string $observation): static
    {
        $observation = null !== $observation ? trim($observation) : null;
        $this->observation = '' === $observation ? null : $observation;

        return $this;
    }

    public function getCloturePar(): ?User
    {
        return $this->cloturePar;
    }

    public function setCloturePar(?User $utilisateur): static
    {
        $this->cloturePar = $utilisateur;

        return $this;
    }
}
