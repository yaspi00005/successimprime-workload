<?php

namespace App\Service;

/**
 * Règles du montage d'impression (docs/montage-impression.md) : feuilles
 * reconnues et données gardées avec le PDF rangé dans la commande.
 */
final class Montage
{
    public const FORMATS = ['A4', 'A3', 'SRA3'];

    /**
     * Feuilles reconnues dans un texte libre, ex. « A4 · A3 · SRA3 ».
     *
     * @return list<string>
     */
    public static function formats(string $texte): array
    {
        preg_match_all('/\b(SRA3|A3|A4)\b/i', $texte, $m);
        $vus = array_unique(array_map('strtoupper', $m[1]));

        return array_values(array_filter(self::FORMATS, static fn (string $f): bool => \in_array($f, $vus, true)));
    }

    /**
     * Données envoyées par l'outil, nettoyées. Null si le montage n'a
     * aucune pose.
     *
     * @param array<string, mixed> $brut
     *
     * @return array{poses: int, feuille: string, quantite: int, feuilles: int, gache: float, verso: bool, machine: ?int, machineNom: ?string}|null
     */
    public static function donnees(array $brut, ?int $machine = null, ?string $machineNom = null): ?array
    {
        $poses = (int) ($brut['poses'] ?? 0);
        if ($poses <= 0) {
            return null;
        }
        $feuille = (string) ($brut['feuille'] ?? '');

        return [
            'poses' => min($poses, 10000),
            'feuille' => \in_array($feuille, self::FORMATS, true) ? $feuille : 'perso',
            'quantite' => max(0, (int) ($brut['quantite'] ?? 0)),
            'feuilles' => max(0, (int) ($brut['feuilles'] ?? 0)),
            'gache' => max(0.0, min(50.0, (float) ($brut['gache'] ?? 0))),
            'verso' => filter_var($brut['verso'] ?? false, \FILTER_VALIDATE_BOOL),
            'machine' => $machine,
            'machineNom' => $machineNom,
        ];
    }

    /** Ligne lisible, ex. « 4 poses par feuille SRA3 · 258 feuilles à sortir (gâche 3 %) · Konica ». */
    public static function resume(array $d): string
    {
        $morceaux = [\sprintf('%d %s par feuille %s', $d['poses'], $d['poses'] > 1 ? 'poses' : 'pose', $d['feuille'])];
        if ($d['feuilles'] > 0) {
            $morceaux[] = \sprintf('%d %s à sortir%s (gâche %s %%)', $d['feuilles'], $d['feuilles'] > 1 ? 'feuilles' : 'feuille', $d['verso'] ? ' en recto-verso' : '', str_replace('.', ',', (string) round($d['gache'], 1)));
        }
        if ($d['machineNom'] !== null) {
            $morceaux[] = $d['machineNom'];
        }

        return implode(' · ', $morceaux);
    }
}
