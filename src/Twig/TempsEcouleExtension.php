<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig générique "il y a X minutes/heures/jours", utilisé par
 * les cloches de notifications et de messagerie du gabarit de base.
 */
class TempsEcouleExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('temps_ecoule', [$this, 'tempsEcoule']),
        ];
    }

    public function tempsEcoule(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $secondes = (new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();

        if ($secondes < 60) {
            return 'à l\'instant';
        }

        if ($secondes < 3600) {
            $minutes = (int) floor($secondes / 60);

            return 'il y a ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }

        if ($secondes < 86400) {
            $heures = (int) floor($secondes / 3600);

            return 'il y a ' . $heures . ' heure' . ($heures > 1 ? 's' : '');
        }

        $jours = (int) floor($secondes / 86400);

        if ($jours < 30) {
            return 'il y a ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
        }

        return 'le ' . $date->format('d/m/Y');
    }
}
