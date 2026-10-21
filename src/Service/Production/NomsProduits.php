<?php

namespace App\Service\Production;

/**
 * Le nom courant d'un produit, au singulier et au pluriel, avec son genre
 * et sa famille d'emballage (docs/production-colis.md) : « 2 bâches
 * roulées », « 1 kakémono », « Vos 2 bâches sont prêtes ». Reconnu dans la
 * désignation de la ligne ou le nom du produit ; null si rien ne correspond
 * (le texte dit alors « votre commande »). Pur, sans base.
 */
final class NomsProduits
{
    public const ROULEAU = 'rouleau';
    public const HOUSSE = 'housse';
    public const CARTON = 'carton';
    public const AUTRE = 'autre';

    /** Motif => [singulier, pluriel, féminin, famille]. Le premier motif reconnu l'emporte. */
    private const LEXIQUE = [
        '/kak[ée]mono/iu' => ['kakémono', 'kakémonos', false, self::HOUSSE],
        '/roll[\s-]?up/iu' => ['roll-up', 'roll-up', false, self::HOUSSE],
        '/x[\s-]?banner/iu' => ['x-banner', 'x-banners', false, self::HOUSSE],
        '/banderole/iu' => ['banderole', 'banderoles', true, self::ROULEAU],
        '/b[âa]che/iu' => ['bâche', 'bâches', true, self::ROULEAU],
        '/toile/iu' => ['toile', 'toiles', true, self::ROULEAU],
        '/cartes? de visite/iu' => ['carte de visite', 'cartes de visite', true, self::CARTON],
        '/flyer/iu' => ['flyer', 'flyers', false, self::CARTON],
        '/d[ée]pliant/iu' => ['dépliant', 'dépliants', false, self::CARTON],
        '/brochure/iu' => ['brochure', 'brochures', true, self::CARTON],
        '/calendrier/iu' => ['calendrier', 'calendriers', false, self::CARTON],
        '/tract/iu' => ['tract', 'tracts', false, self::CARTON],
        '/affiche/iu' => ['affiche', 'affiches', true, self::CARTON],
        '/\bcartes?\b/iu' => ['carte', 'cartes', true, self::CARTON],
        '/autocollant|sticker/iu' => ['autocollant', 'autocollants', false, self::AUTRE],
        '/[ée]tiquette/iu' => ['étiquette', 'étiquettes', true, self::AUTRE],
        '/t[\s-]?shirt|tee[\s-]?shirt/iu' => ['t-shirt', 't-shirts', false, self::AUTRE],
        '/\bpolo/iu' => ['polo', 'polos', false, self::AUTRE],
        '/casquette/iu' => ['casquette', 'casquettes', true, self::AUTRE],
        '/panneau/iu' => ['panneau', 'panneaux', false, self::AUTRE],
        '/plaque/iu' => ['plaque', 'plaques', true, self::AUTRE],
        '/enseigne/iu' => ['enseigne', 'enseignes', true, self::AUTRE],
        '/badge/iu' => ['badge', 'badges', false, self::AUTRE],
        '/vinyle/iu' => ['vinyle', 'vinyles', false, self::ROULEAU],
    ];

    /**
     * @return array{singulier: string, pluriel: string, feminin: bool, famille: string}|null
     */
    public static function reconnaitre(?string ...$textes): ?array
    {
        foreach ($textes as $texte) {
            if ($texte === null || trim($texte) === '') {
                continue;
            }
            foreach (self::LEXIQUE as $motif => [$singulier, $pluriel, $feminin, $famille]) {
                if (preg_match($motif, $texte) === 1) {
                    return ['singulier' => $singulier, 'pluriel' => $pluriel, 'feminin' => $feminin, 'famille' => $famille];
                }
            }
        }

        return null;
    }

    /** « 2 bâches », « 1 kakémono », « 1 000 flyers ». */
    public static function compter(int $quantite, ?array $nom, string $sinon = 'pièce'): string
    {
        $mot = $nom === null ? ($quantite > 1 ? $sinon.'s' : $sinon) : ($quantite > 1 ? $nom['pluriel'] : $nom['singulier']);

        return number_format($quantite, 0, ',', ' ').' '.$mot;
    }

    /** « 2 bâches roulées » (une bâche, une banderole voyagent roulées). */
    public static function resume(int $quantite, ?array $nom): string
    {
        $texte = self::compter($quantite, $nom);
        if ($nom !== null && $nom['famille'] === self::ROULEAU) {
            $texte .= ' roulé'.($nom['feminin'] ? 'e' : '').($quantite > 1 ? 's' : '');
        }

        return $texte;
    }
}
