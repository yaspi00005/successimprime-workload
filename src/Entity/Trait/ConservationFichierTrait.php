<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Durée de conservation d'un fichier de commande (ConservationFichiers) :
 * effacé du serveur quelques mois après l'impression, sauf « Garder ce
 * fichier 1 an » demandé par le client ou l'équipe. L'effacement est noté
 * (la fiche reste, les octets partent).
 */
trait ConservationFichierTrait
{
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $gardeDemandeeLe = null;

    /** « Awa Diarra (client) », « kadiatou » : qui a demandé de garder le fichier. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $gardePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $effaceLe = null;

    public function getGardeDemandeeLe(): ?\DateTimeImmutable
    {
        return $this->gardeDemandeeLe;
    }

    public function getGardePar(): ?string
    {
        return $this->gardePar;
    }

    public function garderPlusLongtemps(?string $par, \DateTimeImmutable $le): static
    {
        $this->gardeDemandeeLe = $le;
        $this->gardePar = null === $par ? null : mb_substr($par, 0, 120);

        return $this;
    }

    public function annulerGarde(): static
    {
        $this->gardeDemandeeLe = null;
        $this->gardePar = null;

        return $this;
    }

    public function getEffaceLe(): ?\DateTimeImmutable
    {
        return $this->effaceLe;
    }

    public function marquerEfface(\DateTimeImmutable $le): static
    {
        $this->effaceLe = $le;

        return $this;
    }
}
