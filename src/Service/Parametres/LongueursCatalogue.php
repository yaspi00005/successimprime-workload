<?php

namespace App\Service\Parametres;

/**
 * Longueur des textes saisis dans Réglages › Finitions, Formats, Supports et
 * Types d'impression. Au-delà de sa colonne, la base refusait le texte
 * (erreur 500) : les routes JSON le vérifient avant, avec un message clair,
 * et les champs des fenêtres portent la même limite (maxlength).
 *
 * Les nombres suivent les colonnes (LongueursCatalogueTest le vérifie). Les
 * descriptions des finitions, formats et supports sont des textes longs,
 * sans limite.
 */
final class LongueursCatalogue
{
    public const FINITION_NOM = 255;
    public const FORMAT_NOM = 150;
    public const SUPPORT_NOM = 255;
    public const TYPE_IMPRESSION_NOM = 255;
    public const TYPE_IMPRESSION_DESCRIPTION = 255;

    /**
     * Premier texte trop long, ou null si tout tient. Compte les caractères
     * (« é » vaut 1), comme la base en utf8mb4.
     *
     * @param array<string, array{0: string, 1: int}> $champs libellé => [texte, longueur maximale]
     */
    public static function erreur(array $champs): ?string
    {
        foreach ($champs as $libelle => [$texte, $maximum]) {
            if (mb_strlen($texte) > $maximum) {
                return \sprintf('%s : %d caractères au plus.', $libelle, $maximum);
            }
        }

        return null;
    }
}
