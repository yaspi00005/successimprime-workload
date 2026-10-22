<?php

namespace App\Service\SuiviMetiers;

/**
 * Barème des points par métier (docs/suivi-metiers.md, maquette W07) et
 * chemin des points jusqu'à la prime : points du mois ÷ points possibles
 * = note « Productivité et qualité », critère du score sur 100, qui fixe
 * le palier de prime. Classe sans base de données : les valeurs viennent
 * de la tuile Barème des points.
 */
final class Bareme
{
    public const GRAPHISTE = 'graphiste';
    public const OPERATEUR = 'operateur';
    public const LIVREUR = 'livreur';
    public const ENTRETIEN = 'entretien';

    /**
     * Règles par défaut. La clé d'une règle est aussi celle du réglage.
     *
     * @var array<string, array{libelle: string, note: string, regles: array<string, array{0: string, 1: int}>}>
     */
    public const METIERS = [
        self::GRAPHISTE => [
            'libelle' => 'Graphiste PAO',
            'note' => 'Les points d\'un travail sont multipliés par sa difficulté. Un changement d\'avis du client ne retire rien.',
            'regles' => [
                'g_bat_a_temps' => ['BAT envoyé avant l\'échéance', 10],
                'g_sans_erreur' => ['Validé sans erreur du graphiste', 5],
                'g_retard_30' => ['Retard, par tranche de 30 min', -2],
                'g_erreur' => ['Erreur du graphiste (faute, format)', -10],
                'g_avis_client' => ['Changement d\'avis du client', 0],
            ],
        ],
        self::OPERATEUR => [
            'libelle' => 'Opérateur production',
            'note' => 'Une panne signalée ne retire pas de points.',
            'regles' => [
                'p_avant_heure' => ['Travail sorti avant l\'heure prévue', 5],
                'p_10m2' => ['Production, par 10 m²', 1],
                'p_tetes' => ['Entretien des têtes avant 08:30', 3],
                'p_gache' => ['Gâche du jour sous 3 %', 5],
                'p_reimpression' => ['Réimpression due à l\'opérateur', -10],
            ],
        ],
        self::LIVREUR => [
            'libelle' => 'Livreur',
            'note' => 'Retard justifié (panne, client absent) : neutralisé par le responsable.',
            'regles' => [
                'l_creneau' => ['Livré dans le créneau', 5],
                'l_preuve' => ['Preuve complète (photo, signature, GPS)', 2],
                'l_encaissement' => ['Encaissement remis le jour même', 3],
                'l_retard' => ['Retard de plus de 30 min', -5],
                'l_erreur' => ['Colis abîmé ou erreur de livraison', -10],
            ],
        ],
        self::ENTRETIEN => [
            'libelle' => 'Agent d\'entretien',
            'note' => 'Le responsable contrôle des tâches tirées au hasard chaque jour.',
            'regles' => [
                'e_creneau' => ['Tâche faite dans le créneau, avec photo', 3],
                'e_controle' => ['Contrôle du responsable conforme', 2],
                'e_fermeture' => ['Checklist de fermeture complète', 5],
                'e_retard' => ['Tâche en retard de plus de 30 min', -1],
                'e_non_conforme' => ['Contrôle non conforme (à refaire)', -3],
            ],
        ],
    ];

    public const DIFFICULTES = ['simple' => 'Simple', 'moyen' => 'Moyen', 'complexe' => 'Complexe'];

    /** Autres réglages de l'écran : coefficients du graphiste, poids du critère, contrôles. */
    public const AUTRES = [
        'coef_simple' => ['Coefficient simple', 1.0],
        'coef_moyen' => ['Coefficient moyen', 1.5],
        'coef_complexe' => ['Coefficient complexe', 2.0],
        'poids_productivite' => ['Poids du critère « Productivité et qualité »', 40],
        'controles_entretien' => ['Tâches d\'entretien contrôlées par jour', 2],
    ];

    /** Score minimum → part de la prime versée (%). */
    public const PALIERS = [90 => 100, 80 => 75, 70 => 50, 60 => 25];

    /** @param array<string, int|float> $valeurs valeurs de la tuile ; une valeur absente prend le défaut */
    public function __construct(private readonly array $valeurs = [])
    {
    }

    /**
     * Champs de la tuile, modifiés depuis l'écran du barème.
     *
     * @return list<array<string, mixed>>
     */
    public static function champs(): array
    {
        $champs = [];
        foreach (self::METIERS as $metier) {
            foreach ($metier['regles'] as $cle => [$libelle, $defaut]) {
                $champs[] = ['cle' => $cle, 'type' => 'nombre', 'libelle' => $metier['libelle'].' · '.$libelle, 'suffixe' => 'pts', 'min' => -100, 'max' => 100, 'defaut' => $defaut, 'ecran' => true];
            }
        }
        foreach (['coef_simple', 'coef_moyen', 'coef_complexe'] as $cle) {
            $champs[] = ['cle' => $cle, 'type' => 'nombre', 'libelle' => 'Graphiste PAO · '.self::AUTRES[$cle][0], 'decimales' => 1, 'min' => 1, 'max' => 5, 'defaut' => self::AUTRES[$cle][1], 'ecran' => true];
        }
        $champs[] = ['cle' => 'poids_productivite', 'type' => 'nombre', 'libelle' => self::AUTRES['poids_productivite'][0], 'suffixe' => '%', 'min' => 0, 'max' => 100, 'defaut' => 40, 'ecran' => true];
        $champs[] = ['cle' => 'controles_entretien', 'type' => 'nombre', 'libelle' => self::AUTRES['controles_entretien'][0], 'min' => 0, 'max' => 20, 'defaut' => 2, 'ecran' => true];

        return $champs;
    }

    public static function metierDe(string $regle): ?string
    {
        foreach (self::METIERS as $metier => $definition) {
            if (isset($definition['regles'][$regle])) {
                return $metier;
            }
        }

        return null;
    }

    public function valeur(string $cle): int|float
    {
        if (\array_key_exists($cle, $this->valeurs) && is_numeric($this->valeurs[$cle])) {
            return $this->valeurs[$cle] + 0;
        }
        $metier = self::metierDe($cle);

        return $metier !== null ? self::METIERS[$metier]['regles'][$cle][1] : (self::AUTRES[$cle][1] ?? 0);
    }

    public function coefficient(string $difficulte): float
    {
        return (float) $this->valeur('coef_'.(isset(self::DIFFICULTES[$difficulte]) ? $difficulte : 'simple'));
    }

    /**
     * Points d'un travail : chaque règle avec sa quantité (tranches de
     * 30 min, dizaines de m²…), multipliés par la difficulté pour le
     * graphiste, arrondis à l'entier inférieur. Exemple W02 :
     * (10 + 5) × 1,5 = 22.
     *
     * @param array<string, int|float> $faits règle => quantité
     */
    public function points(array $faits, string $difficulte = 'simple'): int
    {
        $total = 0.0;
        $graphiste = false;
        foreach ($faits as $regle => $quantite) {
            $metier = self::metierDe($regle) ?? throw new \InvalidArgumentException('Règle inconnue : '.$regle);
            $graphiste = $graphiste || $metier === self::GRAPHISTE;
            $total += $this->valeur($regle) * $quantite;
        }

        return (int) floor(round($total * ($graphiste ? $this->coefficient($difficulte) : 1.0), 6));
    }

    /**
     * Points possibles : ceux du travail parfait (règles positives seulement).
     *
     * @param array<string, int|float> $faits règle => quantité
     */
    public function possibles(array $faits, string $difficulte = 'simple'): int
    {
        return $this->points(array_filter($faits, fn (int|float $q, string $regle): bool => $this->valeur($regle) > 0, \ARRAY_FILTER_USE_BOTH), $difficulte);
    }

    /** Note « Productivité et qualité » sur 100. */
    public static function note(int $gagnes, int $possibles): int
    {
        return $possibles <= 0 ? 0 : (int) round(max(0, min(100, $gagnes * 100 / $possibles)));
    }

    /**
     * Score sur 100 : la note pèse son poids, les autres critères se
     * partagent le reste à parts égales.
     *
     * @param list<int|float> $autresNotes ponctualité, appréciation… sur 100
     */
    public function score(int $note, array $autresNotes): float
    {
        $poids = max(0, min(100, (float) $this->valeur('poids_productivite')));
        if ($autresNotes === []) {
            return (float) $note;
        }
        $autres = array_sum($autresNotes) / \count($autresNotes);

        return round(($note * $poids + $autres * (100 - $poids)) / 100, 1);
    }

    /** Part de la prime versée (%) selon le score. */
    public static function palier(float $score): int
    {
        foreach (self::PALIERS as $minimum => $part) {
            if ($score >= $minimum) {
                return $part;
            }
        }

        return 0;
    }
}
