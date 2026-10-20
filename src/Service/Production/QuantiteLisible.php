<?php

namespace App\Service\Production;

/**
 * Une quantité sortie du stock, dite comme à l'atelier (bloc « Consommé »
 * du contrôle final, PD06) : « 6,6 m² », « 78 ml », « 1,2 L », « 32 »,
 * « 2 boîtes ». La quantité est dans l'unité de stock de l'article
 * (Articles::getUnite). Jamais de prix. Pur, sans base.
 */
final class QuantiteLisible
{
    public static function texte(float $quantite, string $unite): string
    {
        return match ($unite) {
            'm2' => self::nombre($quantite, 1).' m²',
            'metre' => self::nombre($quantite, 1).' m',
            'litre' => $quantite > 0 && $quantite < 1 ? self::nombre($quantite * 1000, 0).' ml' : self::nombre($quantite, 1).' L',
            'boite' => self::nombre($quantite, 0).' boîte'.(round($quantite) > 1 ? 's' : ''),
            'unite' => self::nombre($quantite, 0),
            default => trim(self::nombre($quantite, 1).' '.$unite),
        };
    }

    /** « 6,6 », « 1 000 », « 2 » : virgule décimale, zéros inutiles retirés. */
    public static function nombre(float $valeur, int $decimales): string
    {
        $texte = number_format($valeur, $decimales, ',', ' ');

        return str_contains($texte, ',') ? rtrim(rtrim($texte, '0'), ',') : $texte;
    }
}
