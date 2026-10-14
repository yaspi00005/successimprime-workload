<?php

namespace App\Service\Conception;

/**
 * Prix d'une demande de conception (docs/demande-conception.md) : acompte
 * du graphiste, solde payé à la validation du BAT, packs de crédit IA.
 * Les prix viennent des réglages (Paramètres › Conception) ; rien ici ne
 * dit le coût ni la marge.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class TarifConception
{
    /** Packs proposés si le réglage est vide ou illisible. */
    public const PACKS_DEFAUT = [1, 3, 5, 10];

    /** Un pack ne dépasse pas ce nombre d'images. */
    public const PACK_MAX = 100;

    /**
     * Acompte payé maintenant : le pourcentage du forfait, arrondi à la
     * dizaine de francs supérieure.
     */
    public static function acompte(int $forfait, int $pourcent): int
    {
        $pourcent = max(0, min(100, $pourcent));
        if ($forfait <= 0 || 0 === $pourcent) {
            return 0;
        }

        return min($forfait, (int) (ceil($forfait * $pourcent / 1000) * 10));
    }

    /** Solde de la conception, payé à la validation du BAT avec l'impression. */
    public static function solde(int $forfait, int $pourcent): int
    {
        return max(0, $forfait - self::acompte($forfait, $pourcent));
    }

    /**
     * Packs du réglage « 1, 3, 5, 10 » : nombres d'images, uniques, triés.
     *
     * @return list<int>
     */
    public static function packs(?string $reglage): array
    {
        $packs = [];
        foreach (preg_split('/[^0-9]+/', (string) $reglage) ?: [] as $morceau) {
            $n = (int) $morceau;
            if ($n >= 1 && $n <= self::PACK_MAX) {
                $packs[$n] = $n;
            }
        }
        sort($packs);

        return [] === $packs ? self::PACKS_DEFAUT : array_values($packs);
    }

    public static function prixPack(int $images, int $prixImage): int
    {
        return max(0, $images) * max(0, $prixImage);
    }

    /** Images que le crédit permet encore de générer. */
    public static function imagesPossibles(int $solde, int $prixImage): int
    {
        return $prixImage <= 0 ? ($solde >= 0 ? \PHP_INT_MAX : 0) : intdiv(max(0, $solde), $prixImage);
    }

    /** « 5 000 F » */
    public static function francs(int $montant): string
    {
        return number_format($montant, 0, ',', "\u{202f}").' F';
    }
}
