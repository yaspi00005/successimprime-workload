<?php

namespace App\Service\Sauvegarde;

/**
 * Quelles sauvegardes garder : la plus récente de chacun des N derniers
 * jours, des N dernières semaines et des N derniers mois. La toute
 * dernière est toujours gardée, ainsi que toutes celles des dernières
 * heures (une base toutes les 6 h). Pur, pour être testé sans disque.
 */
final class Rotation
{
    /**
     * @param list<string> $noms           noms de dossier « AAAAMMJJ-HHMMSS »
     * @param int          $heuresRecentes tout ce qui a moins de N heures est gardé (sauvegardes de la journée)
     *
     * @return list<string> les noms à garder
     */
    public static function aGarder(array $noms, \DateTimeImmutable $maintenant, int $jours, int $semaines, int $mois, int $heuresRecentes = 48): array
    {
        $dates = [];
        foreach ($noms as $nom) {
            $date = self::date($nom);
            if ($date !== null) {
                $dates[$nom] = $date;
            }
        }
        if ($dates === []) {
            return [];
        }
        arsort($dates); // plus récente d'abord

        $garder = [array_key_first($dates) => true];
        $vus = [];
        $debutJour = $maintenant->setTime(0, 0);
        $limites = [
            'j' => $debutJour->modify('-'.max(0, $jours - 1).' days'),
            's' => $debutJour->modify('monday this week')->modify('-'.max(0, $semaines - 1).' weeks'),
            'm' => $debutJour->modify('first day of this month')->modify('-'.max(0, $mois - 1).' months'),
        ];
        $quotas = ['j' => $jours, 's' => $semaines, 'm' => $mois];
        $formats = ['j' => 'Y-m-d', 's' => 'o-W', 'm' => 'Y-m'];

        $recent = $maintenant->modify('-'.max(0, $heuresRecentes).' hours');
        foreach ($dates as $nom => $date) {
            if ($date >= $recent) {
                $garder[$nom] = true;
            }
            foreach ($formats as $type => $format) {
                if ($quotas[$type] <= 0 || $date < $limites[$type]) {
                    continue;
                }
                $seau = $type.$date->format($format);
                if (!isset($vus[$seau])) {
                    $vus[$seau] = true;
                    $garder[$nom] = true;
                }
            }
        }

        return array_values(array_filter($noms, static fn (string $n): bool => isset($garder[$n])));
    }

    public static function date(string $nom): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{8}-\d{6}$/', $nom)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Ymd-His', $nom);

        return $date ?: null;
    }
}
