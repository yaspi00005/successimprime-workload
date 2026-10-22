<?php

namespace App\Service\Atelier;

/**
 * Calculs du poste machiniste (maquette OP03), sans base de données :
 * temps proposé par étape, lecture d'une durée saisie, machines capables
 * d'imprimer la pièce, consommation de rouleau, début et fin prévus,
 * écart entre l'estimation et le temps réel.
 *
 * Les valeurs proposées ne sont qu'un point de départ : le machiniste
 * estime chaque étape et l'outil compare ensuite à ce qui s'est passé.
 */
final class TempsEstime
{
    /** Les temps proposés sont arrondis aux 5 minutes supérieures. */
    public const ARRONDI = 5;
    /** Séchage avant finition (encres solvant), en minutes. */
    public const SECHAGE = 30;
    /** Une finition sur une pièce de grand format, par exemplaire (ourlet, œillets…). */
    public const FINITION_PAR_PIECE = 10;
    /** Une finition sur des petites pièces, par lot de 100. */
    public const FINITION_PAR_LOT = 15;
    /** Pièce « grand format » à partir de cette surface (m²). */
    public const SURFACE_GRAND_FORMAT = 0.25;
    /** Contrôle et emballage, en minutes. */
    public const CONTROLE = 10;
    /** Marge de rouleau par exemplaire (amorce, coupe), en mètres. */
    public const MARGE_ROULEAU = 0.1;

    /**
     * Étapes proposées pour un travail.
     *
     * @param list<string> $finitions noms des finitions de la ligne
     *
     * @return list<array{cle: string, libelle: string, propose: ?int}>
     */
    public static function proposer(?float $m2, ?float $vitesseM2Heure, int $exemplaires, array $finitions, bool $sechage, ?float $surfacePiece = null): array
    {
        $exemplaires = max(1, $exemplaires);
        $etapes = [];

        $impression = null;
        if ($m2 !== null && $m2 > 0 && $vitesseM2Heure !== null && $vitesseM2Heure > 0) {
            $impression = self::arrondir($m2 / $vitesseM2Heure * 60);
        }
        $etapes[] = [
            'cle' => 'impression',
            'libelle' => $m2 !== null && $m2 > 0
                ? 'Impression · '.self::nombre($m2).' m²'.($vitesseM2Heure > 0 ? ' à '.self::nombre((float) $vitesseM2Heure).' m²/h' : '')
                : 'Impression',
            'propose' => $impression,
        ];

        $finitions = array_values(array_filter(array_map('trim', $finitions), static fn (string $f): bool => $f !== ''));
        if ($sechage && $finitions !== []) {
            $etapes[] = ['cle' => 'sechage', 'libelle' => 'Séchage avant finition', 'propose' => self::SECHAGE];
        }
        if ($finitions !== []) {
            $grand = $surfacePiece === null || $surfacePiece >= self::SURFACE_GRAND_FORMAT;
            $parFinition = $grand ? self::FINITION_PAR_PIECE * $exemplaires : self::FINITION_PAR_LOT * (int) ceil($exemplaires / 100);
            $etapes[] = [
                'cle' => 'finitions',
                'libelle' => implode(' + ', $finitions).($grand ? ' ('.$exemplaires.' ex.)' : ''),
                'propose' => self::arrondir($parFinition * \count($finitions)),
            ];
        }
        $etapes[] = ['cle' => 'controle', 'libelle' => 'Contrôle et emballage', 'propose' => self::CONTROLE];

        return $etapes;
    }

    /** Total des minutes d'une liste d'étapes, pour la clé donnée (propose ou estime). */
    public static function total(array $etapes, string $cle = 'propose'): int
    {
        return array_sum(array_map(static fn (array $e): int => max(0, (int) ($e[$cle] ?? 0)), $etapes));
    }

    public static function arrondir(float $minutes): int
    {
        return $minutes <= 0 ? 0 : (int) (ceil($minutes / self::ARRONDI) * self::ARRONDI);
    }

    /** « 1 h 20 », « 30 min », « 3 h 00 ». */
    public static function duree(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $minutes = max(0, $minutes);

        return $minutes < 60 ? $minutes.' min' : \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Lit une durée saisie : « 1 h 30 », « 1h30 », « 1:30 », « 90 »,
     * « 45 min », « 2 h ». Rend null si rien n'est lisible.
     */
    public static function lire(?string $saisie): ?int
    {
        $s = mb_strtolower(trim((string) $saisie));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2})\s*(?:h|:)\s*(\d{1,2})?\s*(?:min|mn)?$/u', $s, $m)) {
            $minutes = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        } elseif (preg_match('/^(\d{1,4})\s*(?:min|mn|m)?$/u', $s, $m)) {
            $minutes = (int) $m[1];
        } else {
            return null;
        }

        return $minutes > 0 && $minutes <= 24 * 60 * 3 ? $minutes : null;
    }

    /**
     * Ce que chaque machine peut faire de la pièce (largeur × hauteur en
     * mètres) : la pièce passe si son plus petit côté tient dans la laize.
     *
     * @param list<array{id: int, nom: string, largeur: ?float}> $machines
     *
     * @return list<array{id: int, nom: string, largeur: ?float, possible: bool, raison: ?string}>
     */
    public static function machinesPossibles(?float $largeur, ?float $hauteur, array $machines): array
    {
        $petit = $largeur !== null && $hauteur !== null && $largeur > 0 && $hauteur > 0 ? min($largeur, $hauteur) : null;
        $resultat = [];
        foreach ($machines as $m) {
            $laize = $m['largeur'] !== null && $m['largeur'] > 0 ? (float) $m['largeur'] : null;
            $possible = $petit === null || $laize === null || $petit <= $laize + 0.0001;
            $resultat[] = $m + ['possible' => $possible, 'raison' => $possible ? null : 'trop étroite pour '.self::nombre($petit).' m'];
        }

        return $resultat;
    }

    /**
     * Sens de la pièce sur le rouleau et longueur consommée.
     *
     * @return array{sens: string, parPiece: float, total: float, texte: string}|null
     */
    public static function rouleau(?float $largeur, ?float $hauteur, int $exemplaires, ?float $laize): ?array
    {
        if ($largeur === null || $hauteur === null || $largeur <= 0 || $hauteur <= 0) {
            return null;
        }
        $exemplaires = max(1, $exemplaires);
        // Le plus grand côté dans la longueur du rouleau, sauf s'il tient dans la laize et l'autre non.
        [$travers, $long] = $largeur <= $hauteur ? [$largeur, $hauteur] : [$hauteur, $largeur];
        if ($laize !== null && $laize > 0 && $long <= $laize && $travers > $laize) {
            [$travers, $long] = [$long, $travers];
        }
        $parPiece = round($long + self::MARGE_ROULEAU, 2);
        $total = round($parPiece * $exemplaires, 2);

        return [
            'sens' => self::nombre($travers).' m dans la largeur, '.self::nombre($long).' m dans la longueur',
            'parPiece' => $parPiece,
            'total' => $total,
            'texte' => $exemplaires.' × '.self::nombre($parPiece).' m = '.self::nombre($total).' m de rouleau',
        ];
    }

    /** Heure de début : maintenant arrondi aux 5 minutes, ou quand la machine se libère. */
    public static function debut(\DateTimeImmutable $maintenant, ?\DateTimeImmutable $machineLibre): \DateTimeImmutable
    {
        $minutes = (int) $maintenant->format('i');
        $arrondi = $maintenant->setTime((int) $maintenant->format('H'), 0)->modify('+'.(int) (ceil(($minutes + ((int) $maintenant->format('s') > 0 ? 1 : 0)) / self::ARRONDI) * self::ARRONDI).' minutes');
        if ($machineLibre !== null && $machineLibre > $arrondi) {
            $m = (int) $machineLibre->format('i');
            $arrondi = $machineLibre->setTime((int) $machineLibre->format('H'), 0)->modify('+'.(int) (ceil($m / self::ARRONDI) * self::ARRONDI).' minutes');
        }

        return $arrondi;
    }

    /** Écart de l'estimation au réel, en % du réel (positif : estimation trop longue). */
    public static function ecart(?int $estime, ?int $reel): ?float
    {
        if ($estime === null || $reel === null || $reel <= 0) {
            return null;
        }

        return round(($estime - $reel) * 100 / $reel, 1);
    }

    /**
     * Écart moyen, en valeur absolue, d'une série d'estimations.
     *
     * @param list<array{estime: ?int, reel: ?int}> $series
     */
    public static function ecartMoyen(array $series): ?float
    {
        $ecarts = [];
        foreach ($series as $s) {
            $e = self::ecart($s['estime'] ?? null, $s['reel'] ?? null);
            if ($e !== null) {
                $ecarts[] = abs($e);
            }
        }

        return $ecarts === [] ? null : round(array_sum($ecarts) / \count($ecarts), 0);
    }

    /** Fin prévue comparée à la date promise : vrai si dans les temps. */
    public static function dansLesTemps(\DateTimeImmutable $fin, ?\DateTimeInterface $promis): ?bool
    {
        if ($promis === null) {
            return null;
        }
        // Une date promise sans heure vaut pour toute la journée.
        $limite = $promis->format('H:i') === '00:00' ? \DateTimeImmutable::createFromInterface($promis)->setTime(23, 59) : \DateTimeImmutable::createFromInterface($promis);

        return $fin <= $limite;
    }

    private static function nombre(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
