<?php

namespace App\Twig;

use App\Service\ParcMachines;
use Twig\Attribute\AsTwigFunction;

/** Libellés de durée et d'échéance de l'écran Machines et maintenance (maquette 27). */
final class ParcMachinesExtension
{
    #[AsTwigFunction('duree_arret')]
    public function dureeArret(int $minutes): string
    {
        return ParcMachines::duree($minutes);
    }

    #[AsTwigFunction('quand_maintenance')]
    public function quandMaintenance(\DateTimeInterface $date): string
    {
        return ParcMachines::quand(\DateTimeImmutable::createFromInterface($date), new \DateTimeImmutable());
    }
}
