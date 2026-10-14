<?php

namespace App\Service\Primes;

use App\Service\SuiviMetiers\Bareme;

/**
 * Score et prime d'un agent (maquettes N02 et N03), sans base de données.
 *
 * Chaque critère du barème du rôle a un poids. Score = somme des notes ×
 * poids ÷ somme des poids des critères qui ont des données : un critère
 * sans données (ponctualité avant le pointage, avis clients absents…)
 * n'est pas noté 0, son poids est réparti sur les autres, et la fiche le
 * dit. La prime est un pourcentage de la prime cible selon les paliers
 * (Bareme::PALIERS : 90 → 100 %, 80 → 75 %, 70 → 50 %, 60 → 25 %).
 */
final class CalculPrime
{
    /** Rôles de prime (onglets N03). */
    public const ROLES = [
        'caisse' => 'Caisse et commercial',
        'graphiste' => 'Graphiste PAO',
        'production' => 'Opérateur production',
        'livreur' => 'Livreur',
        'comptable' => 'Comptable',
        'entretien' => 'Agent d\'entretien',
    ];

    /**
     * Critères : libellé (N02), libellé court (N04), source des données
     * (N03), unité de l'objectif et objectif par défaut. « manuel » : saisi
     * par le responsable.
     */
    public const CRITERES = [
        'ventes' => ['libelle' => 'Ventes par rapport à l\'objectif', 'court' => 'Ventes vs objectif', 'source' => 'Factures de l\'agent', 'unite' => 'F / mois', 'objectif' => 3_000_000],
        'transformation' => ['libelle' => 'Taux de transformation des devis', 'court' => 'Transformation des devis', 'source' => 'Devis acceptés', 'unite' => '%', 'objectif' => 60],
        'satisfaction' => ['libelle' => 'Satisfaction de ses clients', 'court' => 'Satisfaction clients', 'source' => 'Avis après livraison', 'unite' => '/ 5', 'objectif' => 4.5],
        'encaissement' => ['libelle' => 'Encaissement et relances', 'court' => 'Encaissement', 'source' => 'Factures payées à temps', 'unite' => '%', 'objectif' => 90],
        'erreurs' => ['libelle' => 'Erreurs (écarts de caisse, réimpressions dues)', 'court' => 'Erreurs', 'source' => 'Écarts de caisse, réimpressions', 'unite' => 'erreur(s)', 'objectif' => 0],
        'ponctualite' => ['libelle' => 'Ponctualité et présence', 'court' => 'Ponctualité', 'source' => 'Pointage', 'unite' => '%', 'objectif' => 100],
        'productivite' => ['libelle' => 'Points du métier', 'court' => 'Points du métier', 'source' => 'Barème des points (suivi des métiers)', 'unite' => '%', 'objectif' => 100],
        'actions' => ['libelle' => 'Actions commerciales à l\'heure', 'court' => 'Actions à l\'heure', 'source' => 'Actions de caisse', 'unite' => '%', 'objectif' => 100],
        'appreciation' => ['libelle' => 'Appréciation du responsable', 'court' => 'Appréciation', 'source' => 'Saisie manuelle', 'unite' => null, 'objectif' => null],
    ];

    /** Points retirés par erreur au-delà de l'objectif (écart de caisse, réimpression due). */
    public const POINTS_PAR_ERREUR = 15;

    /**
     * Barèmes par défaut, tant que la direction ne les a pas modifiés (N03).
     *
     * @var array<string, array{prime: int, criteres: list<array{cle: string, poids: int, objectif: int|float|null}>}>
     */
    public const BAREMES = [
        'caisse' => ['prime' => 50_000, 'criteres' => [
            ['cle' => 'ventes', 'poids' => 30, 'objectif' => 3_000_000],
            ['cle' => 'transformation', 'poids' => 15, 'objectif' => 60],
            ['cle' => 'satisfaction', 'poids' => 15, 'objectif' => 4.5],
            ['cle' => 'encaissement', 'poids' => 10, 'objectif' => 90],
            ['cle' => 'erreurs', 'poids' => 10, 'objectif' => 0],
            ['cle' => 'ponctualite', 'poids' => 10, 'objectif' => 100],
            ['cle' => 'appreciation', 'poids' => 10, 'objectif' => null],
        ]],
        'graphiste' => ['prime' => 40_000, 'criteres' => [
            ['cle' => 'productivite', 'poids' => 45, 'objectif' => 100],
            ['cle' => 'satisfaction', 'poids' => 15, 'objectif' => 4.5],
            ['cle' => 'ponctualite', 'poids' => 20, 'objectif' => 100],
            ['cle' => 'appreciation', 'poids' => 20, 'objectif' => null],
        ]],
        'production' => ['prime' => 40_000, 'criteres' => [
            ['cle' => 'productivite', 'poids' => 40, 'objectif' => 100],
            ['cle' => 'erreurs', 'poids' => 20, 'objectif' => 0],
            ['cle' => 'ponctualite', 'poids' => 20, 'objectif' => 100],
            ['cle' => 'appreciation', 'poids' => 20, 'objectif' => null],
        ]],
        'livreur' => ['prime' => 30_000, 'criteres' => [
            ['cle' => 'productivite', 'poids' => 45, 'objectif' => 100],
            ['cle' => 'satisfaction', 'poids' => 15, 'objectif' => 4.5],
            ['cle' => 'ponctualite', 'poids' => 20, 'objectif' => 100],
            ['cle' => 'appreciation', 'poids' => 20, 'objectif' => null],
        ]],
        'comptable' => ['prime' => 50_000, 'criteres' => [
            ['cle' => 'encaissement', 'poids' => 30, 'objectif' => 90],
            ['cle' => 'erreurs', 'poids' => 20, 'objectif' => 0],
            ['cle' => 'ponctualite', 'poids' => 20, 'objectif' => 100],
            ['cle' => 'appreciation', 'poids' => 30, 'objectif' => null],
        ]],
        'entretien' => ['prime' => 20_000, 'criteres' => [
            ['cle' => 'productivite', 'poids' => 50, 'objectif' => 100],
            ['cle' => 'ponctualite', 'poids' => 25, 'objectif' => 100],
            ['cle' => 'appreciation', 'poids' => 25, 'objectif' => null],
        ]],
    ];

    /** Note sur 100 d'un résultat rapporté à son objectif, plafonnée à 100. */
    public static function noteObjectif(float $realise, float $objectif): int
    {
        return $objectif > 0 ? (int) min(100, max(0, round($realise * 100 / $objectif))) : 0;
    }

    /** Satisfaction : moyenne sur 5 × 20 (4,8 / 5 → 96). */
    public static function noteSatisfaction(float $moyenne): int
    {
        return (int) min(100, max(0, round($moyenne * 20)));
    }

    /** Erreurs : 100, moins 15 points par erreur au-delà de l'objectif. */
    public static function noteErreurs(int $erreurs, int $tolerees = 0): int
    {
        return max(0, 100 - self::POINTS_PAR_ERREUR * max(0, $erreurs - $tolerees));
    }

    /** Part de la prime cible selon le score (paliers N01). */
    public static function taux(?int $score): int
    {
        return null !== $score ? Bareme::palier($score) : 0;
    }

    /**
     * Score et prime.
     *
     * @param list<array{cle: string, poids: int, objectif: int|float|null}>  $criteres du barème
     * @param array<string, array{note: ?int, mesure: string}>                $mesures  par clé ; note null = pas de données
     *
     * @return array{score: ?int, taux: int, primeCible: int, prime: int, criteres: list<array<string, mixed>>, manquants: list<string>}
     */
    public static function evaluer(array $criteres, array $mesures, int $primeCible, bool $suspendue = false): array
    {
        $poidsDisponibles = 0;
        foreach ($criteres as $c) {
            if (null !== ($mesures[$c['cle']]['note'] ?? null)) {
                $poidsDisponibles += $c['poids'];
            }
        }
        $poidsTotal = array_sum(array_column($criteres, 'poids'));
        $somme = 0.0;
        $lignes = [];
        $manquants = [];
        foreach ($criteres as $c) {
            $note = $mesures[$c['cle']]['note'] ?? null;
            $applique = null !== $note && $poidsDisponibles > 0 ? $c['poids'] * $poidsTotal / $poidsDisponibles : 0.0;
            if (null !== $note) {
                $somme += $note * $c['poids'];
            } else {
                $manquants[] = self::CRITERES[$c['cle']]['libelle'];
            }
            $lignes[] = [
                'cle' => $c['cle'],
                'libelle' => self::CRITERES[$c['cle']]['libelle'],
                'court' => self::CRITERES[$c['cle']]['court'],
                'poids' => $c['poids'],
                'poidsApplique' => round($applique, 1),
                'objectif' => $c['objectif'],
                'mesure' => $mesures[$c['cle']]['mesure'] ?? 'pas de données ce mois-ci',
                'note' => $note,
                'manuel' => 'appreciation' === $c['cle'],
            ];
        }
        $score = $poidsDisponibles > 0 ? (int) round($somme / $poidsDisponibles) : null;
        $taux = self::taux($score);

        return [
            'score' => $score,
            'taux' => $taux,
            'primeCible' => $primeCible,
            'prime' => $suspendue ? 0 : (int) round($primeCible * $taux / 100),
            'criteres' => $lignes,
            'manquants' => $manquants,
        ];
    }

    /**
     * Palier atteint : « 90 à 100 », « 80 à 89 »… ou « moins de 60 ».
     */
    public static function libellePalier(?int $score): string
    {
        $bornes = array_keys(Bareme::PALIERS);
        rsort($bornes);
        $haut = 100;
        foreach ($bornes as $borne) {
            if (null !== $score && $score >= $borne) {
                return $borne.' à '.$haut;
            }
            $haut = $borne - 1;
        }

        return 'moins de '.min($bornes);
    }

    /**
     * Paliers pour l'affichage (N01, N03, N04).
     *
     * @return list<array{de: ?int, a: int, taux: int, libelle: string}>
     */
    public static function paliers(): array
    {
        $bornes = Bareme::PALIERS;
        krsort($bornes);
        $paliers = [];
        $haut = 100;
        foreach ($bornes as $borne => $taux) {
            $paliers[] = ['de' => $borne, 'a' => $haut, 'taux' => $taux, 'libelle' => $borne.' à '.$haut];
            $haut = $borne - 1;
        }
        $paliers[] = ['de' => null, 'a' => $haut, 'taux' => 0, 'libelle' => 'moins de '.($haut + 1)];

        return $paliers;
    }
}
