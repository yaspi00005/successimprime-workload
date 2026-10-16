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

    /**
     * @param bool $court « il y a 16 min », « il y a 3 h » (cloche de l'en-tête) au lieu de « il y a 16 minutes »
     */
    public function tempsEcoule(?\DateTimeInterface $date, bool $court = false, ?\DateTimeInterface $maintenant = null): string
    {
        if ($date === null) {
            return '';
        }

        $secondes = ($maintenant ?? new \DateTimeImmutable())->getTimestamp() - $date->getTimestamp();

        if ($secondes < 60) {
            return 'à l\'instant';
        }

        if ($secondes < 3600) {
            $minutes = (int) floor($secondes / 60);

            return 'il y a ' . $minutes . ($court ? ' min' : ' minute' . ($minutes > 1 ? 's' : ''));
        }

        if ($secondes < 86400) {
            $heures = (int) floor($secondes / 3600);

            return 'il y a ' . $heures . ($court ? ' h' : ' heure' . ($heures > 1 ? 's' : ''));
        }

        $jours = (int) floor($secondes / 86400);

        if ($jours < 30) {
            return 'il y a ' . $jours . ' jour' . ($jours > 1 ? 's' : '');
        }

        return 'le ' . $date->format('d/m/Y');
    }
}
