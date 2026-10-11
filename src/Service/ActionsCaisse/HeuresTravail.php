<?php

namespace App\Service\ActionsCaisse;

use App\Service\Pointage\CalculPointage;

/**
 * Temps de travail de l'atelier (docs/actions-caisse.md) : jours ouvrés,
 * ouverture → fermeture moins la pause, sans les jours fériés. Le compteur
 * des actions de caisse ne tourne que pendant ces heures. Sans base de
 * données.
 */
final class HeuresTravail
{
    /** Fêtes à date fixe du Mali, proposées par défaut (JJ/MM). */
    public const FERIES_MALI = '01/01, 20/01, 26/03, 01/05, 25/05, 22/09, 25/12';

    /** @var list<int> */
    private array $jours;
    /** @var array<string, true> */
    private array $feries = [];

    public function __construct(
        string $plageJours = 'lun-sam',
        private readonly string $ouverture = '08:00',
        private readonly string $fermeture = '18:00',
        private readonly string $pauseDebut = '13:00',
        private readonly string $pauseFin = '14:00',
        string $feries = self::FERIES_MALI,
    ) {
        $this->jours = CalculPointage::joursOuvres($plageJours);
        foreach (self::lireDates($feries) as $date) {
            $this->feries[$date] = true;
        }
    }

    /**
     * Liste « 01/01, 27/05/2026 » → ['01-01', '2026-05-27'] ; null si une
     * date est mal écrite.
     *
     * @return list<string>|null
     */
    public static function lireDates(string $texte): ?array
    {
        $dates = [];
        foreach (preg_split('/[\s,;]+/', trim($texte), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $morceau) {
            if (!preg_match('#^(\d{1,2})/(\d{1,2})(?:/(\d{4}))?$#', $morceau, $m) || !checkdate((int) $m[2], (int) $m[1], (int) ($m[3] ?? 2024))) {
                return null;
            }
            $jourMois = \sprintf('%02d-%02d', $m[2], $m[1]);
            $dates[] = isset($m[3]) ? $m[3].'-'.$jourMois : $jourMois;
        }

        return array_values(array_unique($dates));
    }

    public function estOuvre(\DateTimeImmutable $jour): bool
    {
        return \in_array((int) $jour->format('N'), $this->jours, true)
            && !isset($this->feries[$jour->format('m-d')])
            && !isset($this->feries[$jour->format('Y-m-d')]);
    }

    /**
     * Plages de travail d'un jour, pause retirée.
     *
     * @return list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}>
     */
    public function plages(\DateTimeImmutable $jour): array
    {
        if (!$this->estOuvre($jour)) {
            return [];
        }
        $a = fn (string $heure): \DateTimeImmutable => $jour->setTime(...array_map('intval', explode(':', $heure)));
        [$ouverture, $fermeture, $pauseDebut, $pauseFin] = [$a($this->ouverture), $a($this->fermeture), $a($this->pauseDebut), $a($this->pauseFin)];

        if ($pauseFin <= $pauseDebut || $pauseFin <= $ouverture || $pauseDebut >= $fermeture) {
            return [[$ouverture, $fermeture]];
        }

        return array_values(array_filter([
            [$ouverture, max($ouverture, $pauseDebut)],
            [min($fermeture, $pauseFin), $fermeture],
        ], static fn (array $p): bool => $p[1] > $p[0]));
    }

    /**
     * Date atteinte après $minutes de travail à partir de $debut.
     */
    public function ajouter(\DateTimeImmutable $debut, int $minutes): \DateTimeImmutable
    {
        $reste = max(0, $minutes) * 60;
        $jour = $debut->setTime(0, 0);
        for ($i = 0; $i < 400; ++$i, $jour = $jour->modify('+1 day')) {
            foreach ($this->plages($jour) as [$de, $a]) {
                $de = max($de, $debut);
                if ($a <= $de) {
                    continue;
                }
                $dispo = $a->getTimestamp() - $de->getTimestamp();
                if ($reste <= $dispo) {
                    return $de->modify('+'.$reste.' seconds');
                }
                $reste -= $dispo;
            }
        }

        return $debut->modify('+'.$minutes.' minutes');
    }

    /**
     * Minutes de travail entre deux dates (0 si $fin <= $debut).
     */
    public function ecoule(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        $secondes = 0;
        for ($jour = $debut->setTime(0, 0); $jour < $fin; $jour = $jour->modify('+1 day')) {
            foreach ($this->plages($jour) as [$de, $a]) {
                $secondes += max(0, min($a, $fin)->getTimestamp() - max($de, $debut)->getTimestamp());
            }
        }

        return intdiv($secondes, 60);
    }
}
