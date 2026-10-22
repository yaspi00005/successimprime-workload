<?php

namespace App\Service\Catalogue;

use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Entity\ProduitConfiguration;

/**
 * Paliers de prix d'une configuration de produit (docs/produits.md,
 * « Paliers de prix et grilles ») : le calcul, en un seul endroit.
 *
 * - Paliers par quantité : « à partir de N pièces », un prix unitaire
 *   (ex. 120 F au lieu de 150 F) ou une remise en %. En dessous du
 *   premier palier, le prix de la configuration.
 * - Paliers par surface (produits au m² seulement) : « à partir de N m²
 *   de la ligne » (surface × quantité, ou surface des pièces au m²),
 *   un prix au m² (ex. 3 200 F au lieu de 3 500 F) ou une remise en %.
 *   Le prix de base ne change pas : le palier devient une remise.
 *
 * Règle de cumul : le meilleur des deux paliers (quantité ou surface),
 * jamais les deux (une seule remise « par volume ») ; puis la remise de
 * la grille du client (Partenaire, Association) sur le prix déjà
 * remisé ; puis le prix négocié s'il est plus bas (TarifClient). Un
 * revendeur (B2B) garde son prix revendeur, sans palier ni grille.
 *
 * Sur la ligne, le résultat est posé en remise par unité facturable
 * (prix de la ligne − prix net), comme le prix négocié et la remise B2B :
 * la facture garde le prix normal et montre la remise.
 *
 * Tout est statique et pur ; public/js/paliers-prix.js refait le même
 * calcul pour les aperçus (le serveur fait foi).
 */
final class PaliersPrix
{
    /** Paliers au plus, par sorte et par configuration. */
    public const MAX = 8;

    /** Remise la plus forte acceptée pour un palier, en %. */
    public const TAUX_MAX = 90.0;

    public const SOURCE_QUANTITE = 'quantite';
    public const SOURCE_SURFACE = 'surface';

    /**
     * Paliers par quantité propres : « de » ≥ 2, un prix entier ≥ 0 ou une
     * remise entre 0 et 90 %, triés, un seul palier par seuil (le dernier
     * saisi), au plus MAX.
     *
     * @return list<array{de: int, prix: ?int, remise: ?float}>
     */
    public static function normaliserQuantite(mixed $brut): array
    {
        $propres = [];
        foreach (\is_array($brut) ? $brut : [] as $p) {
            if (!\is_array($p)) {
                continue;
            }
            $de = self::entier($p['de'] ?? null);
            if (null === $de || $de < 2) {
                continue;
            }
            $prix = self::entier($p['prix'] ?? null);
            $remise = self::decimal($p['remise'] ?? null);
            if (null !== $prix && $prix >= 0) {
                $propres[$de] = ['de' => $de, 'prix' => $prix, 'remise' => null];
            } elseif (null !== $remise && $remise > 0) {
                $propres[$de] = ['de' => $de, 'prix' => null, 'remise' => min(self::TAUX_MAX, round($remise, 1))];
            }
        }
        ksort($propres);

        return array_values(\array_slice($propres, 0, self::MAX, true));
    }

    /**
     * Paliers par surface propres : surface > 0 (en m², deux décimales),
     * un prix au m² entier ≥ 0 ou une remise entre 0 et 90 %, triés, au
     * plus MAX.
     *
     * @return list<array{m2: float, prix: ?int, remise: ?float}>
     */
    public static function normaliserSurface(mixed $brut): array
    {
        $propres = [];
        foreach (\is_array($brut) ? $brut : [] as $p) {
            if (!\is_array($p)) {
                continue;
            }
            $m2 = self::decimal($p['m2'] ?? null);
            if (null === $m2 || $m2 <= 0) {
                continue;
            }
            $m2 = round($m2, 2);
            $prix = self::entier($p['prix'] ?? null);
            $remise = self::decimal($p['remise'] ?? null);
            if (null !== $prix && $prix >= 0) {
                $propres[(string) $m2] = ['m2' => $m2, 'prix' => $prix, 'remise' => null];
            } elseif (null !== $remise && $remise > 0) {
                $propres[(string) $m2] = ['m2' => $m2, 'prix' => null, 'remise' => min(self::TAUX_MAX, round($remise, 1))];
            }
        }
        uasort($propres, static fn (array $a, array $b): int => $a['m2'] <=> $b['m2']);

        return array_values(\array_slice($propres, 0, self::MAX));
    }

    /**
     * Remise d'un palier (quantité ou surface) en % du prix de la
     * configuration : la remise saisie, ou l'écart entre le prix du palier
     * et ce prix.
     */
    public static function tauxPalier(array $palier, int $prixBase): float
    {
        if (null !== ($palier['remise'] ?? null)) {
            return min(self::TAUX_MAX, max(0.0, (float) $palier['remise']));
        }
        if ($prixBase <= 0 || null === ($palier['prix'] ?? null)) {
            return 0.0;
        }

        return min(self::TAUX_MAX, max(0.0, ($prixBase - (int) $palier['prix']) / $prixBase * 100));
    }

    /**
     * Remise « par volume » atteinte : la plus forte entre le palier de
     * quantité et la remise de surface (jamais les deux).
     *
     * @param list<array{de: int, prix: ?int, remise: ?float}> $quantite
     * @param list<array{m2: float, prix: ?int, remise: ?float}> $surface
     *
     * @return array{taux: float, source: ?string, seuil: int|float|null, prochain: ?array{source: string, seuil: int|float, taux: float}}
     */
    public static function volume(array $quantite, array $surface, int $prixBase, int $pieces, float $surfaceTotale, string $modeCalcul): array
    {
        $tauxQ = 0.0;
        $seuilQ = null;
        $prochain = null;
        foreach ($quantite as $p) {
            if ($pieces >= $p['de']) {
                $tauxQ = self::tauxPalier($p, $prixBase);
                $seuilQ = $p['de'];
            } elseif (null === $prochain) {
                $prochain = ['source' => self::SOURCE_QUANTITE, 'seuil' => $p['de'], 'taux' => round(self::tauxPalier($p, $prixBase), 1)];
            }
        }
        $tauxS = 0.0;
        $seuilS = null;
        if ('metre_carre' === $modeCalcul) {
            foreach ($surface as $r) {
                // Petite tolérance : 9,999 m² comptent pour 10 m².
                if ($surfaceTotale + 0.0005 >= $r['m2']) {
                    $tauxS = self::tauxPalier($r, $prixBase);
                    $seuilS = $r['m2'];
                } else {
                    // Au m², la prochaine remise de surface parle mieux que le prochain palier de pièces.
                    $prochain = ['source' => self::SOURCE_SURFACE, 'seuil' => $r['m2'], 'taux' => round(self::tauxPalier($r, $prixBase), 1)];
                    break;
                }
            }
        }

        if ($tauxQ <= 0 && $tauxS <= 0) {
            return ['taux' => 0.0, 'source' => null, 'seuil' => null, 'prochain' => $prochain];
        }

        return $tauxS > $tauxQ
            ? ['taux' => $tauxS, 'source' => self::SOURCE_SURFACE, 'seuil' => $seuilS, 'prochain' => $prochain]
            : ['taux' => $tauxQ, 'source' => self::SOURCE_QUANTITE, 'seuil' => $seuilQ, 'prochain' => $prochain];
    }

    /** Volume atteint par une ligne pour sa configuration. */
    public static function volumeConfiguration(ProduitConfiguration $c, int $pieces, float $surfaceTotale): array
    {
        return self::volume($c->getPaliersQuantite(), $c->getRemisesSurface(), (int) $c->getPrixBase(), $pieces, $surfaceTotale, $c->getModeCalcul());
    }

    /**
     * Prix net d'une unité : palier de volume, puis grille du client.
     * Arrondi au franc.
     */
    public static function net(float $prix, float $tauxVolume, float $tauxGrille): int
    {
        $v = min(self::TAUX_MAX, max(0.0, $tauxVolume));
        $g = min(self::TAUX_MAX, max(0.0, $tauxGrille));

        return max(0, (int) round(max(0.0, $prix) * (1 - $v / 100) * (1 - $g / 100)));
    }

    /** Remise par unité qui ramène le prix de la ligne au prix net. */
    public static function remiseParUnite(float $prix, float $tauxVolume, float $tauxGrille): int
    {
        return max(0, (int) round($prix) - self::net($prix, $tauxVolume, $tauxGrille));
    }

    /**
     * Pièces et surface facturée d'une ligne, pour trouver son palier.
     *
     * @return array{0: int, 1: float}
     */
    public static function mesures(CommandesDetails|DevisDetails $ligne): array
    {
        return [max(1, (int) $ligne->getQuantite()), 'metre_carre' === $ligne->getModeCalcul() ? $ligne->getSurfaceTotale() : 0.0];
    }

    /**
     * Pose la remise de volume sur une ligne neuve construite au prix du
     * catalogue (commande en ligne, calculateur du site) et refait ses
     * totaux. Remplace la remise de la ligne quand la configuration a des
     * paliers : à n'appeler que sur une ligne qui n'a pas encore d'autre
     * remise (NouvelleCommande).
     */
    public static function appliquerVolume(CommandesDetails $ligne, ProduitConfiguration $c, bool $b2b): CommandesDetails
    {
        if (!$b2b && $c->aDesPaliers()) {
            [$pieces, $surface] = self::mesures($ligne);
            $ligne->setRemise(self::remiseParUnite($ligne->getPrixUnitaire(), self::volumeConfiguration($c, $pieces, $surface)['taux'], 0.0));
        }

        return $ligne->calculerTotaux($b2b);
    }

    /**
     * Tranches à montrer : « 1 à 100 pièces : 150 F », « 101 à 200 :
     * 120 F (−20 %) », « 201 et plus : 100 F (−33 %) ».
     *
     * @param list<array{de: int, prix: ?int, remise: ?float}> $paliers
     *
     * @return list<array{de: int, a: ?int, prix: int, taux: float, remise: int}>
     */
    public static function tranches(array $paliers, int $prixBase, int $minimum = 1): array
    {
        $tranches = [];
        $debut = max(1, $minimum);
        $taux = 0.0;
        foreach ($paliers as $p) {
            if ($p['de'] <= $debut) {
                $taux = self::tauxPalier($p, $prixBase);
                continue;
            }
            $tranches[] = ['de' => $debut, 'a' => $p['de'] - 1] + self::prixTranche($prixBase, $taux);
            $debut = $p['de'];
            $taux = self::tauxPalier($p, $prixBase);
        }
        $tranches[] = ['de' => $debut, 'a' => null] + self::prixTranche($prixBase, $taux);

        return $tranches;
    }

    /**
     * Tranches de surface à montrer, comme sur la fiche : « 1 à 9 m² :
     * 3 500 F », « 10 à 49 m² : 3 200 F (−8,6 %) », « 50 m² et plus :
     * 2 900 F (−17,1 %) ». Seuils entiers : la tranche s'arrête au m²
     * d'avant ; sinon « moins de N m² ». Vide sans palier.
     *
     * @param list<array{m2: float, prix: ?int, remise: ?float}> $paliers
     *
     * @return list<array{de: ?float, a: ?float, avant: ?float, prix: int, taux: float, remise: int}>
     */
    public static function tranchesSurface(array $paliers, int $prixBase): array
    {
        if ([] === $paliers) {
            return [];
        }
        $tranches = [];
        $debut = null;
        $taux = 0.0;
        foreach ($paliers as $p) {
            $entier = floor($p['m2']) === $p['m2'] && $p['m2'] > 1 && (null === $debut || floor($debut) === $debut);
            $tranches[] = ['de' => $debut, 'a' => $entier ? $p['m2'] - 1 : null, 'avant' => $entier ? null : $p['m2']] + self::prixTranche($prixBase, $taux);
            $debut = $p['m2'];
            $taux = self::tauxPalier($p, $prixBase);
        }
        $tranches[] = ['de' => $debut, 'a' => null, 'avant' => null] + self::prixTranche($prixBase, $taux);

        return $tranches;
    }

    /** @return array{prix: int, taux: float, remise: int} */
    private static function prixTranche(int $prixBase, float $taux): array
    {
        $net = self::net($prixBase, $taux, 0);

        return ['prix' => $net, 'taux' => round($taux, 1), 'remise' => max(0, $prixBase - $net)];
    }

    /**
     * Ce que les pages reçoivent d'une configuration pour refaire le
     * calcul en JavaScript (aucun coût).
     *
     * @return array{prixBase: int, mode: string, quantite: list<array{de: int, prix: ?int, remise: ?float}>, surface: list<array{m2: float, prix: ?int, remise: ?float}>}
     */
    public static function pourLaPage(ProduitConfiguration $c): array
    {
        return [
            'prixBase' => (int) $c->getPrixBase(),
            'mode' => $c->getModeCalcul(),
            'quantite' => $c->getPaliersQuantite(),
            'surface' => $c->getRemisesSurface(),
        ];
    }

    /** Phrase courte du palier atteint, pour la ligne : « Palier dès 101 pièces : −20 % ». */
    public static function libelle(array $volume): ?string
    {
        if (null === $volume['source']) {
            return null;
        }
        $taux = rtrim(rtrim(number_format($volume['taux'], 1, ',', ''), '0'), ',');

        return self::SOURCE_SURFACE === $volume['source']
            ? \sprintf('Remise dès %s m² : −%s %%', rtrim(rtrim(number_format((float) $volume['seuil'], 2, ',', ''), '0'), ','), $taux)
            : \sprintf('Palier dès %d pièces : −%s %%', (int) $volume['seuil'], $taux);
    }

    private static function entier(mixed $v): ?int
    {
        if (\is_int($v)) {
            return $v;
        }
        $t = str_replace([' ', "\u{A0}", "\u{202F}"], '', trim((string) (\is_scalar($v) ? $v : '')));

        return '' !== $t && ctype_digit($t) ? (int) $t : null;
    }

    private static function decimal(mixed $v): ?float
    {
        if (\is_int($v) || \is_float($v)) {
            return (float) $v;
        }
        $t = str_replace([',', ' ', "\u{A0}", "\u{202F}", '%'], ['.', '', '', '', ''], trim((string) (\is_scalar($v) ? $v : '')));

        return '' !== $t && is_numeric($t) ? (float) $t : null;
    }
}
