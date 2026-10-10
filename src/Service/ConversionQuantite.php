<?php

namespace App\Service;

/**
 * Conversions de quantite de « Mes produits » (docs/mes-produits.md) :
 * le client saisit en pieces, en m2 ou par lot, l'atelier recoit ce
 * qu'il faut produire. Calculs purs, partages par l'admin et le site.
 */
final class ConversionQuantite
{
    /**
     * Surface totale de n pieces d'un format fixe (dimensions en metres).
     */
    public static function surfacePieces(int $pieces, float $largeur, float $hauteur): float
    {
        return round(max(0, $pieces) * $largeur * $hauteur, 2);
    }

    /**
     * m2 demandes sur un format fixe : nombre de pieces arrondi au-dessus
     * (propose par defaut), et l'option au-dessous quand elle existe.
     *
     * @return array{superieur: array{pieces: int, surface: float}, inferieur: ?array{pieces: int, surface: float}}
     */
    public static function piecesDepuisSurface(float $surface, float $largeur, float $hauteur): array
    {
        $unitaire = $largeur * $hauteur;

        if ($surface <= 0 || $unitaire <= 0) {
            throw new \InvalidArgumentException('Surface et format doivent être positifs.');
        }

        $exact = $surface / $unitaire;
        $dessus = max(1, (int) ceil($exact - 1e-9));
        $dessous = (int) floor($exact + 1e-9);

        return [
            'superieur' => ['pieces' => $dessus, 'surface' => round($dessus * $unitaire, 2)],
            'inferieur' => $dessous >= 1 && $dessous !== $dessus
                ? ['pieces' => $dessous, 'surface' => round($dessous * $unitaire, 2)]
                : null,
        ];
    }

    /**
     * m2 demandes sur un rouleau de largeur fixe : longueur a imprimer,
     * arrondie aux 10 cm superieurs.
     *
     * @return array{longueur: float, surface: float}
     */
    public static function longueurDepuisSurface(float $surface, float $laize): array
    {
        if ($surface <= 0 || $laize <= 0) {
            throw new \InvalidArgumentException('Surface et largeur doivent être positives.');
        }

        $longueur = ceil(round($surface / $laize, 6) * 10) / 10;

        return ['longueur' => $longueur, 'surface' => round($longueur * $laize, 2)];
    }

    /**
     * Quantite par lot (etiquettes, cartes) : arrondie au lot superieur,
     * avec le nombre de feuilles a imprimer.
     *
     * @return array{quantite: int, lots: int, feuilles: int}
     */
    public static function parLots(int $quantite, int $tailleLot, int $posesParFeuille): array
    {
        if ($tailleLot <= 0 || $posesParFeuille <= 0) {
            throw new \InvalidArgumentException('Lot et poses par feuille doivent être positifs.');
        }

        $lots = max(1, (int) ceil(max(0, $quantite) / $tailleLot));
        $total = $lots * $tailleLot;

        return ['quantite' => $total, 'lots' => $lots, 'feuilles' => (int) ceil($total / $posesParFeuille)];
    }

    /**
     * Textile : total des pieces d'une repartition par taille, sans les
     * tailles a zero.
     *
     * @param array<string, int> $tailles
     *
     * @return array{total: int, tailles: array<string, int>}
     */
    public static function repartitionTailles(array $tailles): array
    {
        $tailles = array_filter(array_map(static fn (int $n): int => max(0, $n), $tailles));

        return ['total' => array_sum($tailles), 'tailles' => $tailles];
    }
}
