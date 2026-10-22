<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Vente « hors imprimerie » d'un devis ou d'une commande
 * (docs/hors-imprimerie.md) : matériel, informatique… vendus aux
 * entreprises. Elle est comptée à part de l'imprimerie dans les
 * statistiques, et la commande ne passe ni par la prépresse ni par
 * l'atelier (HorsImprimerieListener).
 *
 * Il suit le devis à la commande ; la facture le lit par sa commande.
 */
trait HorsImprimerieTrait
{
    #[ORM\Column(options: ['default' => false])]
    private bool $horsImprimerie = false;

    public function isHorsImprimerie(): bool
    {
        return $this->horsImprimerie;
    }

    public function setHorsImprimerie(?bool $horsImprimerie): static
    {
        $this->horsImprimerie = (bool) $horsImprimerie;

        return $this;
    }
}
