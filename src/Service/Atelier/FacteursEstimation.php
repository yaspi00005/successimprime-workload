<?php

namespace App\Service\Atelier;

/**
 * Les temps réels améliorent les prochaines estimations (PD06,
 * docs/production-colis.md) : par machine et par étape, le facteur est la
 * médiane de réel ÷ proposé sur les 20 derniers travaux terminés qui ont
 * les deux, avec au moins 5 travaux, borné entre 0,5 et 2. Il corrige les
 * temps PROPOSÉS (Ma file, rouleaux) ; l'estimation de l'opérateur et les
 * points restent comparés à sa propre saisie. Pur, sans base.
 */
final class FacteursEstimation
{
    public const ECHANTILLONS_MAX = 20;
    public const ECHANTILLONS_MIN = 5;
    public const FACTEUR_MIN = 0.5;
    public const FACTEUR_MAX = 2.0;

    /**
     * @param list<array{reel: ?int, prevu: ?int}> $echantillons du plus récent au plus ancien
     *
     * @return array{facteur: float, nombre: int}|null null : pas assez de travaux
     */
    public static function facteur(array $echantillons): ?array
    {
        $rapports = [];
        foreach ($echantillons as $e) {
            $reel = $e['reel'] ?? null;
            $prevu = $e['prevu'] ?? null;
            if (null === $reel || null === $prevu || $prevu <= 0 || $reel < 0) {
                continue;
            }
            $rapports[] = $reel / $prevu;
            if (\count($rapports) >= self::ECHANTILLONS_MAX) {
                break;
            }
        }
        if (\count($rapports) < self::ECHANTILLONS_MIN) {
            return null;
        }
        sort($rapports);
        $n = \count($rapports);
        $mediane = 0 === $n % 2 ? ($rapports[$n / 2 - 1] + $rapports[$n / 2]) / 2 : $rapports[intdiv($n, 2)];

        return ['facteur' => round(max(self::FACTEUR_MIN, min(self::FACTEUR_MAX, $mediane)), 2), 'nombre' => $n];
    }

    /**
     * Applique les facteurs aux temps proposés ; garde le temps brut (pour
     * apprendre sans boucler sur sa propre correction) et la raison.
     *
     * @param list<array<string, mixed>>                            $etapes   [cle, libelle, propose, …]
     * @param array<string, array{facteur: float, nombre: int}|null> $facteurs par clé d'étape
     *
     * @return list<array<string, mixed>>
     */
    public static function appliquer(array $etapes, array $facteurs): array
    {
        foreach ($etapes as $i => $e) {
            $f = $facteurs[$e['cle'] ?? ''] ?? null;
            $propose = $e['propose'] ?? null;
            if (null === $f || null === $propose || $propose <= 0 || abs($f['facteur'] - 1.0) < 0.01) {
                continue;
            }
            $etapes[$i]['brut'] = (int) $propose;
            $etapes[$i]['propose'] = TempsEstime::arrondir($propose * $f['facteur']);
            $etapes[$i]['correction'] = self::texte($f);
        }

        return $etapes;
    }

    /** Minutes corrigées (arrondies à 5 min), ou les minutes telles quelles sans facteur. */
    public static function corriger(?int $minutes, ?array $facteur): ?int
    {
        if (null === $minutes || null === $facteur || $minutes <= 0) {
            return $minutes;
        }

        return TempsEstime::arrondir($minutes * $facteur['facteur']);
    }

    /** « corrigé d'après les 12 derniers travaux (× 1,1) ». */
    public static function texte(array $facteur): string
    {
        $f = rtrim(rtrim(number_format($facteur['facteur'], 2, ',', ''), '0'), ',');

        return \sprintf('corrigé d\'après les %d derniers travaux (× %s)', $facteur['nombre'], $f);
    }
}
