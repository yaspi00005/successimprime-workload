<?php

namespace App\Service\Production;

/**
 * Placement de plusieurs travaux sur un rouleau (maquette PD02,
 * docs/production-rouleau.md « Placement sur le rouleau »).
 *
 * Placement par étagères, vu de dessus, le rouleau avançant vers le bas :
 * largeur utile = laize − 2 × marge. Les pièces arrivent dans l'ordre
 * d'urgence. Chacune garde le sens commandé (largeur en travers) sauf si
 * elle ne tient pas en travers (elle est tournée) ou si, tournée, elle
 * entre dans une étagère sans la rehausser. Une pièce va dans la première
 * étagère où elle tient (sa largeur plus l'espace entre pièces), sinon elle
 * ouvre une nouvelle étagère en dessous (avec le même espace). La hauteur
 * d'une étagère est celle de sa plus haute pièce ; seule la dernière peut
 * encore monter. Une pièce qui ne tient dans aucun sens est refusée, avec
 * la raison.
 *
 * Bilan : longueur consommée = hauteurs des étagères + espaces entre
 * elles ; consommé = longueur × laize ; imprimé = somme des pièces ;
 * chute = consommé − imprimé ; pourcentage = imprimé ÷ consommé.
 *
 * Toutes les mesures sont en centimètres ; classe pure.
 */
final class ImpositionRouleau
{
    /** Tolérance des comparaisons (cm). */
    private const EPSILON = 0.001;

    /**
     * @param list<array{cle: string, largeur: float, hauteur: float}> $pieces largeur = côté en travers dans le sens commandé
     *
     * @return array{pieces: list<array{cle: string, x: float, y: float, largeur: float, hauteur: float, tourne: bool, etagere: int}>, refusees: list<array{cle: string, raison: string}>, etageres: list<array{y: float, hauteur: float}>, bilan: array{laize: float, longueur: float, consomme: float, imprime: float, chute: float, pourcent: int}}
     */
    public static function placer(float $laize, float $marge, float $espace, array $pieces, ?float $longueurMax = null): array
    {
        $utile = $laize - 2 * $marge;
        /** @var list<array{y: float, hauteur: float, libre: float}> $etageres x libre depuis le bord utile */
        $etageres = [];
        $placees = [];
        $refusees = [];

        foreach ($pieces as $p) {
            $l = (float) $p['largeur'];
            $h = (float) $p['hauteur'];
            if ($l <= 0 || $h <= 0) {
                $refusees[] = ['cle' => $p['cle'], 'raison' => 'dimensions inconnues'];
                continue;
            }
            $droitPossible = $l <= $utile + self::EPSILON;
            $tournePossible = $h <= $utile + self::EPSILON;
            if (!$droitPossible && !$tournePossible) {
                $refusees[] = ['cle' => $p['cle'], 'raison' => \sprintf('trop grande : %s × %s cm ne tient pas dans les %s cm utiles de la laize', self::nombre($l), self::nombre($h), self::nombre($utile))];
                continue;
            }
            // Sens de base : le sens commandé, sauf s'il ne tient pas en travers.
            [$bl, $bh, $bTourne] = $droitPossible ? [$l, $h, false] : [$h, $l, true];

            $avant = $etageres;
            $choix = null;
            foreach ($etageres as $i => $e) {
                $depart = $e['libre'] > self::EPSILON ? $e['libre'] + $espace : 0.0;
                $derniere = $i === \count($etageres) - 1;
                $options = [];
                // a. Sens commandé sans rehausser ; b. tournée sans rehausser ; c. dernière étagère, sens de base, rehaussée.
                if ($droitPossible) {
                    $options[] = [$l, $h, false, false];
                }
                if ($tournePossible) {
                    $options[] = [$h, $l, true, false];
                }
                if ($derniere) {
                    $options[] = [$bl, $bh, $bTourne, true];
                }
                foreach ($options as [$ol, $oh, $oTourne, $rehausse]) {
                    if ($depart + $ol > $utile + self::EPSILON) {
                        continue;
                    }
                    if (!$rehausse && $oh > $e['hauteur'] + self::EPSILON) {
                        continue;
                    }
                    $choix = [$i, $depart, $ol, $oh, $oTourne];
                    break 2;
                }
            }

            if (null === $choix) {
                $y = [] === $etageres ? 0.0 : end($etageres)['y'] + end($etageres)['hauteur'] + $espace;
                $etageres[] = ['y' => $y, 'hauteur' => $bh, 'libre' => 0.0];
                $choix = [\count($etageres) - 1, 0.0, $bl, $bh, $bTourne];
            }
            [$i, $x, $ol, $oh, $oTourne] = $choix;
            $etageres[$i]['hauteur'] = max($etageres[$i]['hauteur'], $oh);
            $etageres[$i]['libre'] = $x + $ol;

            $longueur = end($etageres)['y'] + end($etageres)['hauteur'];
            if (null !== $longueurMax && $longueur > $longueurMax + self::EPSILON) {
                // La pièce ferait dépasser la longueur maximale du tirage : elle reste pour un autre rouleau.
                $etageres = $avant;
                $refusees[] = ['cle' => $p['cle'], 'raison' => \sprintf('le rouleau dépasserait %s m', self::nombre($longueurMax / 100))];
                continue;
            }
            $placees[] = ['cle' => $p['cle'], 'x' => round($marge + $x, 2), 'y' => round($etageres[$i]['y'], 2), 'largeur' => $ol, 'hauteur' => $oh, 'tourne' => $oTourne, 'etagere' => $i];
        }

        return [
            'pieces' => $placees,
            'refusees' => $refusees,
            'etageres' => array_map(static fn (array $e): array => ['y' => $e['y'], 'hauteur' => $e['hauteur']], $etageres),
            'bilan' => self::bilan($laize, $placees),
        ];
    }

    /**
     * Bilan d'un placement (automatique ou manuel) : la longueur va jusqu'au
     * bas de la pièce la plus basse.
     *
     * @param list<array{y: float, largeur: float, hauteur: float}> $pieces
     *
     * @return array{laize: float, longueur: float, consomme: float, imprime: float, chute: float, pourcent: int}
     */
    public static function bilan(float $laize, array $pieces): array
    {
        $longueur = 0.0;
        $imprime = 0.0;
        foreach ($pieces as $p) {
            $longueur = max($longueur, (float) $p['y'] + (float) $p['hauteur']);
            $imprime += (float) $p['largeur'] * (float) $p['hauteur'];
        }
        $consomme = $longueur * $laize / 10000;
        $imprime /= 10000;

        return [
            'laize' => $laize,
            'longueur' => round($longueur, 2),
            'consomme' => round($consomme, 3),
            'imprime' => round($imprime, 3),
            'chute' => round(max(0.0, $consomme - $imprime), 3),
            'pourcent' => $consomme > 0 ? (int) round(100 * $imprime / $consomme) : 0,
        ];
    }

    /**
     * Contrôle d'un placement manuel : chaque pièce dans la largeur utile
     * (marges comprises), sous le début du rouleau, sans chevaucher une
     * autre. Rend la liste des problèmes (vide si tout va bien).
     *
     * @param list<array{cle: string, x: float, y: float, largeur: float, hauteur: float}> $pieces
     *
     * @return list<string>
     */
    public static function problemes(float $laize, float $marge, array $pieces, ?float $longueurMax = null): array
    {
        $problemes = [];
        foreach ($pieces as $p) {
            if ($p['x'] < $marge - self::EPSILON || $p['x'] + $p['largeur'] > $laize - $marge + self::EPSILON) {
                $problemes[] = \sprintf('%s sort de la largeur utile (marge de %s cm de chaque côté).', $p['cle'], self::nombre($marge));
            }
            if ($p['y'] < -self::EPSILON) {
                $problemes[] = \sprintf('%s commence avant le début du rouleau.', $p['cle']);
            }
            if (null !== $longueurMax && $p['y'] + $p['hauteur'] > $longueurMax + self::EPSILON) {
                $problemes[] = \sprintf('%s dépasse la longueur maximale du tirage (%s m).', $p['cle'], self::nombre($longueurMax / 100));
            }
        }
        $n = \count($pieces);
        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                $a = $pieces[$i];
                $b = $pieces[$j];
                $chevauche = $a['x'] < $b['x'] + $b['largeur'] - self::EPSILON && $b['x'] < $a['x'] + $a['largeur'] - self::EPSILON
                    && $a['y'] < $b['y'] + $b['hauteur'] - self::EPSILON && $b['y'] < $a['y'] + $a['hauteur'] - self::EPSILON;
                if ($chevauche) {
                    $problemes[] = \sprintf('%s et %s se chevauchent.', $a['cle'], $b['cle']);
                }
            }
        }

        return $problemes;
    }

    /**
     * Ordre d'impression d'un placement : de haut en bas, puis de gauche à
     * droite (le rouleau avance vers le bas). Rend les clés dans l'ordre.
     *
     * @param list<array{cle: string, x: float, y: float}> $pieces
     *
     * @return list<string>
     */
    public static function ordreImpression(array $pieces): array
    {
        usort($pieces, static fn (array $a, array $b): int => [round($a['y'], 2), round($a['x'], 2)] <=> [round($b['y'], 2), round($b['x'], 2)]);

        return array_map(static fn (array $p): string => (string) $p['cle'], $pieces);
    }

    /**
     * Temps et encre estimés : durée = m² imprimés ÷ vitesse, séchage du
     * profil, encre = m² × ml/m². Null quand la donnée manque.
     *
     * @return array{impressionMin: ?int, sechageMin: ?int, encreMl: ?int}
     */
    public static function estimation(float $imprimeM2, ?float $vitesseM2h, ?int $sechageMin, ?float $encreMlM2): array
    {
        return [
            'impressionMin' => null !== $vitesseM2h && $vitesseM2h > 0 ? max(1, (int) round(60 * $imprimeM2 / $vitesseM2h)) : null,
            'sechageMin' => $sechageMin,
            'encreMl' => null !== $encreMlM2 && $encreMlM2 > 0 ? (int) round($imprimeM2 * $encreMlM2) : null,
        ];
    }

    /**
     * Coût matière + encre (droit « Marges et coûts » seulement) : rouleau
     * consommé × coût du m² de l'article, plus m² imprimés × coût de
     * l'encre au m² de la machine. Null quand les deux coûts manquent.
     */
    public static function cout(float $consommeM2, float $imprimeM2, ?int $coutM2Matiere, ?int $coutEncreM2): ?int
    {
        if (null === $coutM2Matiere && null === $coutEncreM2) {
            return null;
        }

        return (int) round($consommeM2 * ($coutM2Matiere ?? 0) + $imprimeM2 * ($coutEncreM2 ?? 0));
    }

    public static function nombre(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 2, ',', ' '), '0'), ',');
    }
}
