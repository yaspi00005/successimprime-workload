<?php

namespace App\Entity;

use App\Repository\BonLivraisonLigneRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BonLivraisonLigneRepository::class)]
#[ORM\Table(name: 'bon_livraison_ligne')]
class BonLivraisonLigne
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(
        inversedBy: 'lignes'
    )]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?BonLivraison $bonLivraison = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'RESTRICT'
    )]
    private ?CommandesDetails $commandeDetail = null;

    #[ORM\Column(length: 255)]
    private ?string $designation = null;

    #[ORM\Column]
    private int $quantiteCommandee = 0;

    #[ORM\Column]
    private int $quantiteLivree = 0;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $unite = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $observation = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBonLivraison(): ?BonLivraison
    {
        return $this->bonLivraison;
    }

    public function setBonLivraison(
        ?BonLivraison $bonLivraison
    ): static {
        $this->bonLivraison = $bonLivraison;

        return $this;
    }

    public function getCommandeDetail(): ?CommandesDetails
    {
        return $this->commandeDetail;
    }

  public function setCommandeDetail(
    ?CommandesDetails $commandeDetail
): static {
    $this->commandeDetail =
        $commandeDetail;

    if ($commandeDetail !== null) {
        $this->designation =
            $commandeDetail->getDesignation();

        $this->quantiteCommandee =
            $commandeDetail->getQuantite();

        /*
         * Le nouveau BL propose seulement
         * la quantité restant à livrer.
         */
        $this->quantiteLivree =
            $commandeDetail
                ->getQuantiteRestanteLivraison();

        $this->unite =
            $commandeDetail->getModeCalcul();
    }

    return $this;
}

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(
        ?string $designation
    ): static {
        $this->designation =
            $designation !== null
                ? trim($designation)
                : null;

        return $this;
    }

    public function getQuantiteCommandee(): int
    {
        return $this->quantiteCommandee;
    }

    public function setQuantiteCommandee(
        int $quantiteCommandee
    ): static {
        $this->quantiteCommandee =
            max(0, $quantiteCommandee);

        return $this;
    }

    public function getQuantiteLivree(): int
    {
        return $this->quantiteLivree;
    }

    public function setQuantiteLivree(
    int $quantiteLivree
): static {
    if ($quantiteLivree < 1) {
        throw new \InvalidArgumentException(
            'La quantité livrée doit être supérieure à zéro.'
        );
    }

    if ($this->commandeDetail !== null) {
        $maximum =
            $this->commandeDetail
                ->getQuantiteRestanteLivraison();

        if ($quantiteLivree > $maximum) {
            throw new \InvalidArgumentException(
                sprintf(
                    'La quantité livrée ne peut pas dépasser la quantité restante (%d).',
                    $maximum
                )
            );
        }
    } elseif (
        $this->quantiteCommandee > 0
        && $quantiteLivree > $this->quantiteCommandee
    ) {
        throw new \InvalidArgumentException(
            'La quantité livrée ne peut pas dépasser la quantité commandée.'
        );
    }

    $this->quantiteLivree =
        $quantiteLivree;

    return $this;
}

    public function getUnite(): ?string
    {
        return $this->unite;
    }

    public function setUnite(?string $unite): static
    {
        $this->unite =
            $unite !== null
                ? trim($unite)
                : null;

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
            $observation !== null
                ? trim($observation)
                : null;

        return $this;
    }
}