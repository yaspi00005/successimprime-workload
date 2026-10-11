<?php

namespace App\Twig;

use App\Service\Parametres\Parametres;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * reglage('paiements', 'acompte') : valeur d'un réglage de l'écran
 * Paramètres, pour les gabarits qui en dépendent.
 */
final class ReglagesExtension extends AbstractExtension
{
    public function __construct(private readonly Parametres $parametres)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('reglage', $this->parametres->valeur(...)),
        ];
    }
}
