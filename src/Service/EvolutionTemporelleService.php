<?php

namespace App\Service;

/**
 * Decoupe une fenetre de temps glissante (jour/semaine/mois/annee)
 * en paniers ordonnes, avec une cle stable (pour agreger) et un
 * libelle court (pour l'affichage). Utilise par tous les ecrans de
 * statistiques qui affichent une evolution dans le temps.
 */
final class EvolutionTemporelleService
{
    public const GRANULARITES_VALIDES = ['jour', 'semaine', 'mois', 'annee'];

    private const NOMBRE_POINTS_PAR_DEFAUT = [
        'jour' => 30,
        'semaine' => 12,
        'mois' => 12,
        'annee' => 5,
    ];

    public function normaliserGranularite(?string $granularite): string
    {
        return \in_array($granularite, self::GRANULARITES_VALIDES, true)
            ? $granularite
            : 'mois';
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: \DateTimeImmutable}
     */
    public function genererPaniers(string $granularite, ?int $nombrePoints = null): array
    {
        $nombrePoints ??= self::NOMBRE_POINTS_PAR_DEFAUT[$granularite] ?? 12;

        $maintenant = new \DateTimeImmutable('now');
        $cles = [];
        $labels = [];

        for ($i = $nombrePoints - 1; $i >= 0; --$i) {
            $date = match ($granularite) {
                'jour' => $maintenant->modify('-' . $i . ' days'),
                'semaine' => $maintenant->modify('-' . $i . ' weeks'),
                'annee' => $maintenant->modify('-' . $i . ' years'),
                default => $maintenant->modify('-' . $i . ' months'),
            };

            $cles[] = $this->clePourDate($date, $granularite);
            $labels[] = $this->libellePourDate($date, $granularite);
        }

        $debutFenetre = match ($granularite) {
            'jour' => $maintenant->modify('-' . ($nombrePoints - 1) . ' days')->setTime(0, 0),
            'semaine' => $maintenant->modify('-' . ($nombrePoints - 1) . ' weeks')->modify('monday this week')->setTime(0, 0),
            'annee' => $maintenant->modify('-' . ($nombrePoints - 1) . ' years')->modify('first day of january this year')->setTime(0, 0),
            default => $maintenant->modify('-' . ($nombrePoints - 1) . ' months')->modify('first day of this month')->setTime(0, 0),
        };

        return [$cles, $labels, $debutFenetre];
    }

    public function clePourDate(\DateTimeInterface $date, string $granularite): string
    {
        return match ($granularite) {
            'jour' => $date->format('Y-m-d'),
            'semaine' => $date->format('o-\WW'),
            'annee' => $date->format('Y'),
            default => $date->format('Y-m'),
        };
    }

    public function libellePourDate(\DateTimeInterface $date, string $granularite): string
    {
        static $moisCourts = [
            1 => 'Jan', 2 => 'Fév', 3 => 'Mar', 4 => 'Avr',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juil', 8 => 'Août',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Déc',
        ];

        return match ($granularite) {
            'jour' => $date->format('d/m'),
            'semaine' => 'S' . $date->format('W'),
            'annee' => $date->format('Y'),
            default => $moisCourts[(int) $date->format('n')] . ' ' . $date->format('y'),
        };
    }
}
