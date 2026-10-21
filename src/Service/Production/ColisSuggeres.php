<?php

namespace App\Service\Production;

/**
 * Combien de colis pour une commande, et quoi dans chaque colis (PD05,
 * docs/production-colis.md). Règle de la maquette :
 * - bâche, banderole (roulées) : un colis par pièce ;
 * - kakémono, roll-up : dans une housse, un colis pour deux ;
 * - papier (flyers, cartes, dépliants…) : cartons de 500 ;
 * - le reste : un colis.
 * Le contenu se répartit à parts égales entre les colis. Pur, sans base.
 */
final class ColisSuggeres
{
    public const MAX = 99;
    public const PAR_HOUSSE = 2;
    public const PAR_CARTON = 500;

    /** Colis conseillés pour une ligne : $famille vient de NomsProduits (rouleau, housse, carton, autre). */
    public static function pourLigne(string $famille, int $quantite): int
    {
        $quantite = max(1, $quantite);
        $colis = match ($famille) {
            NomsProduits::ROULEAU => $quantite,
            NomsProduits::HOUSSE => (int) ceil($quantite / self::PAR_HOUSSE),
            NomsProduits::CARTON => (int) ceil($quantite / self::PAR_CARTON),
            default => 1,
        };

        return max(1, min(self::MAX, $colis));
    }

    /**
     * @param list<array{famille: string, quantite: int}> $lignes
     */
    public static function pourCommande(array $lignes): int
    {
        $total = 0;
        foreach ($lignes as $l) {
            $total += self::pourLigne($l['famille'], (int) $l['quantite']);
        }

        return max(1, min(self::MAX, $total));
    }

    /** Au plus un colis par pièce (et 99). */
    public static function maximum(array $lignes): int
    {
        return max(1, min(self::MAX, array_sum(array_map(static fn (array $l): int => max(1, (int) $l['quantite']), $lignes))));
    }

    /**
     * Répartit les lignes dans $n colis : chaque ligne garde ses colis
     * conseillés quand $n est la suggestion ; sinon les colis vont aux
     * lignes au prorata (une ligne au moins par colis, jamais plus de colis
     * que de pièces) ; avec moins de colis que de lignes, des lignes
     * entières partagent un colis.
     *
     * @param list<array{ordre: int, ligne: int, nom: string, finitions: string, quantite: int, famille: string}> $lignes
     *
     * @return list<array{emballage: string, contenu: list<array{ordre: int, ligne: int, libelle: string, quantite: int}>}>
     */
    public static function repartir(array $lignes, int $n): array
    {
        $lignes = array_values($lignes);
        if ($lignes === []) {
            return [];
        }
        $n = max(1, min($n, self::maximum($lignes)));

        if ($n < \count($lignes)) {
            // Moins de colis que de lignes : les lignes entières, à la suite, réparties en $n paquets.
            $colis = array_fill(0, $n, ['emballage' => '', 'contenu' => []]);
            foreach ($lignes as $i => $l) {
                $k = intdiv($i * $n, \count($lignes));
                $colis[$k]['contenu'][] = self::part($l, max(1, (int) $l['quantite']));
                $colis[$k]['emballage'] = $colis[$k]['emballage'] === '' ? $l['famille'] : ($colis[$k]['emballage'] === $l['famille'] ? $l['famille'] : NomsProduits::AUTRE);
            }

            return $colis;
        }

        $parLigne = self::colisParLigne($lignes, $n);
        $colis = [];
        foreach ($lignes as $i => $l) {
            $q = max(1, (int) $l['quantite']);
            $k = $parLigne[$i];
            $base = intdiv($q, $k);
            $reste = $q % $k;
            for ($j = 0; $j < $k; ++$j) {
                $colis[] = ['emballage' => $l['famille'], 'contenu' => [self::part($l, $base + ($j < $reste ? 1 : 0))]];
            }
        }

        return $colis;
    }

    /**
     * Nombre de colis de chaque ligne quand il y en a au moins un par ligne.
     *
     * @param list<array{quantite: int, famille: string}> $lignes
     *
     * @return list<int>
     */
    private static function colisParLigne(array $lignes, int $n): array
    {
        $conseilles = array_map(static fn (array $l): int => self::pourLigne($l['famille'], (int) $l['quantite']), $lignes);
        $plafonds = array_map(static fn (array $l): int => max(1, (int) $l['quantite']), $lignes);
        if (array_sum($conseilles) === $n) {
            return $conseilles;
        }
        // Un colis par ligne, puis le reste au prorata des colis conseillés (plus forts restes), sans dépasser les pièces.
        $parts = array_fill(0, \count($lignes), 1);
        $reste = $n - \count($lignes);
        $poids = array_sum($conseilles);
        $voulus = array_map(static fn (int $c): float => $poids > 0 ? $c / $poids * $n : 0.0, $conseilles);
        while ($reste > 0) {
            $meilleur = null;
            foreach ($parts as $i => $p) {
                if ($p >= $plafonds[$i]) {
                    continue;
                }
                $manque = $voulus[$i] - $p;
                if ($meilleur === null || $manque > $voulus[$meilleur] - $parts[$meilleur]) {
                    $meilleur = $i;
                }
            }
            if ($meilleur === null) {
                break;
            }
            ++$parts[$meilleur];
            --$reste;
        }

        return $parts;
    }

    /**
     * @param array{ordre: int, ligne: int, nom: string, finitions: string} $l
     *
     * @return array{ordre: int, ligne: int, libelle: string, quantite: int}
     */
    private static function part(array $l, int $quantite): array
    {
        $libelle = trim($l['nom']).' × '.number_format($quantite, 0, ',', ' ').($l['finitions'] !== '' ? ' · '.$l['finitions'] : '');

        return ['ordre' => (int) $l['ordre'], 'ligne' => (int) $l['ligne'], 'libelle' => mb_substr($libelle, 0, 160), 'quantite' => $quantite];
    }
}
