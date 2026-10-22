<?php

namespace App\Service\Production;

/**
 * Ce que la production sort du stock en plus de la recette du produit
 * (PD04 et PD06, docs/production-rouleau.md) : la chute du rouleau
 * partagée entre ses lignes, l'encre estimée partagée entre les couleurs,
 * les œillets et attaches des finitions. Quantités seulement, jamais de
 * prix. Pur, sans base.
 */
final class ConsommationProduction
{
    /**
     * Partage $total selon des poids, arrondi à $decimales ; la dernière part
     * prend le reste pour que la somme tombe juste.
     *
     * @param array<int|string, float> $poids
     *
     * @return array<int|string, float>
     */
    public static function repartir(float $total, array $poids, int $decimales = 3): array
    {
        $somme = array_sum(array_map(static fn (float $p): float => max(0.0, $p), $poids));
        if ([] === $poids) {
            return [];
        }
        $parts = [];
        $reste = round($total, $decimales);
        $cles = array_keys($poids);
        $derniere = end($cles);
        foreach ($poids as $cle => $p) {
            if ($cle === $derniere) {
                $parts[$cle] = round($reste, $decimales);
                break;
            }
            $part = $somme > 0 ? round($total * max(0.0, $p) / $somme, $decimales) : round($total / \count($poids), $decimales);
            $parts[$cle] = $part;
            $reste -= $part;
        }

        return $parts;
    }

    /**
     * Chute du rouleau à sortir par ligne : la part de la ligne dans le
     * rouleau consommé (au prorata des m² imprimés) moins ce qui est déjà
     * sorti pour cette ligne et cet article (la recette à « Terminé »).
     *
     * @param array<int, float> $imprimes ligne => m² imprimés
     * @param array<int, float> $dejaSortis ligne => quantité déjà sortie (même unité)
     *
     * @return array<int, float> ligne => complément (0 si la recette a déjà tout couvert)
     */
    public static function complements(float $consomme, array $imprimes, array $dejaSortis): array
    {
        $complements = [];
        foreach (self::repartir($consomme, $imprimes) as $ligne => $part) {
            $complements[$ligne] = max(0.0, round($part - ($dejaSortis[$ligne] ?? 0.0), 3));
        }

        return $complements;
    }

    /**
     * Encre en litres par article, partagée à parts égales entre les
     * cartouches CMJN trouvées (toutes les encres de la machine sinon).
     *
     * @param array<int, string> $encres article => désignation
     *
     * @return array<int, float> article => litres
     */
    public static function encre(float $ml, array $encres): array
    {
        if ($ml <= 0 || [] === $encres) {
            return [];
        }
        $cmjn = array_filter($encres, static fn (string $nom): bool => null !== self::couleurEncre($nom) && 'B' !== self::couleurEncre($nom));
        $choisies = [] !== $cmjn ? $cmjn : $encres;

        // Stock à 3 décimales (le millilitre) : la dernière cartouche prend le reste.
        return self::repartir($ml / 1000, array_fill_keys(array_keys($choisies), 1.0), 3);
    }

    /** C, M, J, N ou B d'après le nom de l'article d'encre ; null si on ne sait pas. */
    public static function couleurEncre(string $nom): ?string
    {
        $n = mb_strtolower($nom);

        return match (true) {
            1 === preg_match('/\b(light|clair)\b/u', $n) => null,
            1 === preg_match('/\bcyan\b/u', $n) => 'C',
            1 === preg_match('/\bmagenta\b/u', $n) => 'M',
            1 === preg_match('/\b(jaune|yellow)\b/u', $n) => 'J',
            1 === preg_match('/\b(noir|black|noire)\b/u', $n) => 'N',
            1 === preg_match('/\b(blanc|white|blanche)\b/u', $n) => 'B',
            default => null,
        };
    }

    /**
     * m² de rouleau exprimés dans l'unité de stock de l'article (m², mètre
     * linéaire, rouleau) ; null si on ne sait pas convertir.
     */
    public static function versUniteStock(string $unite, float $m2, ?float $laizeM, ?float $longueurRouleauM): ?float
    {
        return match (mb_strtolower(trim($unite))) {
            'm2', 'm²', 'metre_carre' => round($m2, 3),
            'metre', 'm', 'ml' => null !== $laizeM && $laizeM > 0 ? round($m2 / $laizeM, 3) : null,
            'rouleau' => null !== $laizeM && $laizeM > 0 && null !== $longueurRouleauM && $longueurRouleauM > 0 ? round($m2 / ($laizeM * $longueurRouleauM), 3) : null,
            default => null,
        };
    }

    /**
     * Pièces posées (32 œillets) dans l'unité de stock de l'article : à la
     * pièce telles quelles ; en boîte, paquet ou carton divisées par le
     * nombre de pièces qu'il contient (jamais 32 boîtes) ; null si
     * l'article ne dit pas combien il en contient.
     */
    public static function piecesEnStock(string $unite, float $pieces, float $parUnite): ?float
    {
        if ($pieces <= 0) {
            return 0.0;
        }

        return match (mb_strtolower(trim($unite))) {
            'unite', 'piece', 'pièce' => round($pieces, 3),
            default => $parUnite > 1 ? round($pieces / $parUnite, 3) : null,
        };
    }
}
