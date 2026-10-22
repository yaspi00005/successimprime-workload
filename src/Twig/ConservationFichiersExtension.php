<?php

namespace App\Twig;

use App\Entity\CommandeDetailFichier;
use App\Service\Fichiers\ConservationFichiers;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Conservation d'un fichier de commande, pour les listes de fichiers
 * (espace client et commande) : fichier_conservation(f).
 */
final class ConservationFichiersExtension extends AbstractExtension
{
    public function __construct(private readonly ConservationFichiers $conservation)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('fichier_conservation', $this->etat(...))];
    }

    /**
     * @return array{efface: ?\DateTimeImmutable, effacement: ?\DateTimeImmutable, protege: bool, garde: bool, peutGarder: bool, moisGarde: int, mois: int}
     */
    public function etat(CommandeDetailFichier $f): array
    {
        $protege = $this->conservation->protege($f);
        $effacement = ConservationFichiers::effacementLe($f, $this->conservation->mois(), $this->conservation->moisGarde(), $protege);

        return [
            'efface' => $f->getEffaceLe(),
            'effacement' => $effacement,
            'protege' => $protege,
            'garde' => null !== $f->getGardeDemandeeLe(),
            // Avant comme après l'impression ; pas pour un fichier de Mes produits ni déjà effacé.
            'peutGarder' => !$protege && null === $f->getEffaceLe() && null === $f->getGardeDemandeeLe() && !$f->isLogo() && null !== $f->getCommandeDetail(),
            'moisGarde' => $this->conservation->moisGarde(),
            'mois' => $this->conservation->mois(),
        ];
    }
}
