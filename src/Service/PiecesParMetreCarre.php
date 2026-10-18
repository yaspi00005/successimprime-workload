<?php

namespace App\Service;

use App\Service\Parametres\Parametres;

/**
 * Pièces au m² (demande de Yaya du 16/10/2026, docs/saisie-devis-commandes.md) :
 * combien de pièces d'une taille donnée tiennent sur 1 m × 1 m, avec un
 * espacement entre les pièces en X et en Y (1 mm par défaut, tuile
 * Réglages › Ventes › « Pièces au m² »).
 *
 * Sur une rangée de 1 000 mm, n pièces de L mm prennent n × L + (n − 1) × e :
 * n = plancher((1 000 + e) / (L + e)). Pareil en hauteur ; la pièce tournée
 * d'un quart de tour est essayée aussi et la meilleure est gardée. Une pièce
 * plus grande que 1 m d'un côté donne 0 : on reste au calcul au m² habituel.
 *
 * Quantité en pièces : surface facturée = pièces ÷ pièces au m², arrondie au
 * centième de m² supérieur. Quantité en m² : pièces = plancher(m² × pièces au m²).
 *
 * Les calculs sont statiques et purs (testés sans base), partagés par la
 * saisie du comptoir, le site et le script public/js/pieces-m2.js qui refait
 * le même calcul dans la page.
 */
final class PiecesParMetreCarre
{
    /** Tuile Réglages et clé de l'espacement. */
    public const TUILE = 'pieces_m2';
    public const CLE_ESPACEMENT = 'espacement_mm';

    public const ESPACEMENT_DEFAUT = 1.0;
    public const ESPACEMENT_MAX = 20.0;

    /** Côté de la plaque de référence : 1 m. */
    public const COTE_MM = 1000.0;

    /** Écart toléré pour les arrondis des nombres à virgule. */
    private const EPSILON = 1e-9;

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /** Espacement en vigueur (mm), lu dans les réglages. */
    public function espacement(): float
    {
        return self::espacementValide($this->parametres->valeur(self::TUILE, self::CLE_ESPACEMENT));
    }

    /**
     * Espacement lisible, borné de 0 à ESPACEMENT_MAX mm, au dixième ; un
     * réglage illisible redonne la valeur par défaut.
     */
    public static function espacementValide(mixed $valeur): float
    {
        if (\is_string($valeur)) {
            $valeur = str_replace(',', '.', trim($valeur));
        }
        if (!is_numeric($valeur) || (float) $valeur < 0) {
            return self::ESPACEMENT_DEFAUT;
        }

        return round(min(self::ESPACEMENT_MAX, (float) $valeur), 1);
    }

    /**
     * Pièces de largeur × hauteur (mm) qui tiennent sur une plaque (1 m × 1 m
     * par défaut), espacées de e mm en X et en Y.
     *
     * @return array{parM2: int, colonnes: int, rangees: int, tournee: bool, tropGrande: bool, espacement: float}
     *
     * @throws \InvalidArgumentException dimensions nulles ou espacement négatif
     */
    public static function calculer(float $largeurMm, float $hauteurMm, float $espacementMm = self::ESPACEMENT_DEFAUT, float $plaqueLargeurMm = self::COTE_MM, float $plaqueHauteurMm = self::COTE_MM): array
    {
        if ($largeurMm <= 0 || $hauteurMm <= 0 || $plaqueLargeurMm <= 0 || $plaqueHauteurMm <= 0) {
            throw new \InvalidArgumentException('La largeur et la hauteur doivent être positives.');
        }
        if ($espacementMm < 0) {
            throw new \InvalidArgumentException('L\'espacement ne peut pas être négatif.');
        }

        $droite = [self::parCote($plaqueLargeurMm, $largeurMm, $espacementMm), self::parCote($plaqueHauteurMm, $hauteurMm, $espacementMm)];
        $tournee = [self::parCote($plaqueLargeurMm, $hauteurMm, $espacementMm), self::parCote($plaqueHauteurMm, $largeurMm, $espacementMm)];
        // À égalité, la pièce reste dans le sens saisi.
        $tourner = $tournee[0] * $tournee[1] > $droite[0] * $droite[1];
        [$colonnes, $rangees] = $tourner ? $tournee : $droite;

        return [
            'parM2' => $colonnes * $rangees,
            'colonnes' => $colonnes,
            'rangees' => $rangees,
            'tournee' => $tourner,
            'tropGrande' => 0 === $colonnes * $rangees,
            'espacement' => $espacementMm,
        ];
    }

    /**
     * Surface facturée pour un nombre de pièces : pièces ÷ pièces au m²,
     * arrondie au centième de m² supérieur.
     */
    public static function surface(int $pieces, int $parM2): float
    {
        if ($parM2 < 1) {
            throw new \InvalidArgumentException('Aucune pièce ne tient sur 1 m².');
        }

        return ceil(max(0, $pieces) / $parM2 * 100 - self::EPSILON) / 100;
    }

    /**
     * Pièces pour une surface demandée : m² × pièces au m², arrondi au
     * nombre entier inférieur. 0 quand la surface ne contient pas une pièce
     * entière : à l'appelant de le dire.
     */
    public static function pieces(float $metresCarres, int $parM2): int
    {
        if ($parM2 < 1) {
            throw new \InvalidArgumentException('Aucune pièce ne tient sur 1 m².');
        }
        if ($metresCarres <= 0) {
            return 0;
        }

        return (int) floor($metresCarres * $parM2 + self::EPSILON);
    }

    /**
     * Calcul d'une ligne dont les dimensions sont en mètres (comme en base) :
     * null si une dimension manque.
     *
     * @return array{parM2: int, colonnes: int, rangees: int, tournee: bool, tropGrande: bool, espacement: float}|null
     */
    public static function pourDimensionsEnMetres(?float $largeurM, ?float $hauteurM, float $espacementMm): ?array
    {
        if (null === $largeurM || null === $hauteurM || $largeurM <= 0 || $hauteurM <= 0) {
            return null;
        }

        return self::calculer(round($largeurM * 1000, 1), round($hauteurM * 1000, 1), max(0.0, $espacementMm));
    }

    /**
     * Phrase courte : « 361 pièces au m² (19 × 19, espacement 1 mm) » ou
     * « pièce plus grande que 1 m ».
     *
     * @param array{parM2: int, colonnes: int, rangees: int, tropGrande: bool, espacement: float} $calcul
     */
    public static function libelle(array $calcul): string
    {
        if ($calcul['tropGrande']) {
            return 'pièce plus grande que 1 m';
        }

        return \sprintf(
            '%s pièce%s au m² (%d × %d, espacement %s mm)',
            number_format($calcul['parM2'], 0, ',', "\u{a0}"),
            $calcul['parM2'] > 1 ? 's' : '',
            $calcul['colonnes'],
            $calcul['rangees'],
            self::nombre($calcul['espacement']),
        );
    }

    /** « 1 », « 1,5 » */
    public static function nombre(float $valeur): string
    {
        return str_replace('.', ',', (string) round($valeur, 2));
    }

    /** Pièces de côté c sur un côté de plaque p, espacées de e : plancher((p + e) / (c + e)). */
    private static function parCote(float $plaque, float $cote, float $espacement): int
    {
        return (int) floor(($plaque + $espacement) / ($cote + $espacement) + self::EPSILON);
    }
}
