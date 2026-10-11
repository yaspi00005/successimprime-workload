<?php

namespace App\Twig;

use App\Entity\Machines;
use App\Repository\MachinesRepository;
use Twig\Attribute\AsTwigFunction;

/**
 * Machines proposées dans le panneau « Coût de revient et marge » de la
 * saisie (maquette 22) : celles dont la laize est connue d'abord.
 */
final class CoutLigneExtension
{
    public function __construct(private readonly MachinesRepository $machines)
    {
    }

    /** @return list<Machines> */
    #[AsTwigFunction('machines_pour_cout')]
    public function machinesPourCout(): array
    {
        $liste = $this->machines->findBy([], ['nom' => 'ASC']);
        usort($liste, static fn (Machines $a, Machines $b): int => (null === $a->getLaizeMetres()) <=> (null === $b->getLaizeMetres()));

        return $liste;
    }
}
