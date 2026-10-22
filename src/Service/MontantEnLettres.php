<?php

namespace App\Service;

/**
 * Montant en lettres pour les factures : « deux cent trente-trois
 * mille ». Orthographe traditionnelle (traits d'union sous cent
 * seulement), francs CFA sans décimales.
 */
final class MontantEnLettres
{
    private const UNITES = [
        'zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf',
        'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf',
    ];

    private const DIZAINES = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt'];

    public static function enLettres(int $nombre): string
    {
        if ($nombre < 0) {
            return 'moins '.self::enLettres(-$nombre);
        }

        if ($nombre === 0) {
            return 'zéro';
        }

        $parties = [];

        foreach ([1_000_000_000 => 'milliard', 1_000_000 => 'million'] as $valeur => $nom) {
            // « deux cents millions » : million et milliard sont des noms, cent s'accorde.
            if ($nombre >= $valeur) {
                $combien = intdiv($nombre, $valeur);
                $parties[] = self::moinsDeMille($combien, true).' '.$nom.($combien > 1 ? 's' : '');
                $nombre %= $valeur;
            }
        }

        if ($nombre >= 1000) {
            $milliers = intdiv($nombre, 1000);
            // « mille » est invariable, et on ne dit pas « un mille ».
            $parties[] = $milliers === 1 ? 'mille' : self::moinsDeMille($milliers, false).' mille';
            $nombre %= 1000;
        }

        if ($nombre > 0) {
            $parties[] = self::moinsDeMille($nombre, true);
        }

        return implode(' ', $parties);
    }

    /**
     * « Arrêtée la présente facture à la somme de … (233 000) francs CFA ».
     */
    public static function phraseFacture(int $montant, bool $ttc = true): string
    {
        return sprintf(
            'Arrêtée la présente facture à la somme de %s (%s) francs CFA%s.',
            self::enLettres($montant),
            number_format($montant, 0, ',', ' '),
            $ttc ? ' TTC' : ''
        );
    }

    /**
     * @param bool $final le nombre termine le montant : « deux cents », « quatre-vingts »
     */
    private static function moinsDeMille(int $nombre, bool $final): string
    {
        $centaines = intdiv($nombre, 100);
        $reste = $nombre % 100;
        $mots = [];

        if ($centaines > 0) {
            $cent = $centaines === 1 ? 'cent' : self::UNITES[$centaines].' cent';
            $mots[] = $cent.($centaines > 1 && $reste === 0 && $final ? 's' : '');
        }

        if ($reste > 0) {
            $mots[] = self::moinsDeCent($reste, $final);
        }

        return implode(' ', $mots);
    }

    private static function moinsDeCent(int $nombre, bool $final): string
    {
        if ($nombre < 20) {
            return self::UNITES[$nombre];
        }

        $dizaine = intdiv($nombre, 10);
        $unite = $nombre % 10;

        // 70 à 79 et 90 à 99 : soixante-dix…, quatre-vingt-dix…
        if ($dizaine === 7 || $dizaine === 9) {
            $base = self::DIZAINES[$dizaine - 1];
            $suite = self::UNITES[10 + $unite];

            return $dizaine === 7 && $unite === 1 ? $base.' et onze' : $base.'-'.$suite;
        }

        $base = self::DIZAINES[$dizaine];

        if ($unite === 0) {
            return $dizaine === 8 && $final ? 'quatre-vingts' : $base;
        }

        if ($unite === 1 && $dizaine !== 8) {
            return $base.' et un';
        }

        return $base.'-'.self::UNITES[$unite];
    }
}
