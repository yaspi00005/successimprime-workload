<?php

namespace App\Service\Sondages;

/**
 * Calculs de l'analyse des sondages (docs/sondages.md, indicateur 9 de
 * docs/indicateurs.md) : satisfaction moyenne, clients satisfaits, NPS,
 * taux de réponse, et résultats question par question. Sans base : les
 * notes arrivent en tableaux.
 */
final class Indicateurs
{
    /** Satisfaction moyenne sous ce seuil : alerte (indicateur 9). */
    public const SEUIL_MOYENNE = 4.2;

    /** Produit noté sous ce seuil : alerte. */
    public const SEUIL_PRODUIT = 3.5;

    /** Une note de 1 ou 2 crée une alerte de suivi : l'agent rappelle le client. */
    public const NOTE_ALERTE_MAX = 2;

    /** Textes libres montrés par question dans les résultats. */
    public const TEXTES_MONTRES = 20;

    /** @param list<int|null> $notes */
    public static function moyenne(array $notes): ?float
    {
        $notes = self::nombres($notes);

        return [] === $notes ? null : round(array_sum($notes) / \count($notes), 1);
    }

    /**
     * Part des notes de 4 et 5, en %.
     *
     * @param list<int|null> $notes
     */
    public static function satisfaits(array $notes): ?int
    {
        $notes = self::nombres($notes);
        if ([] === $notes) {
            return null;
        }

        return (int) round(100 * \count(array_filter($notes, static fn (int $n): bool => $n >= 4)) / \count($notes));
    }

    /**
     * NPS : % de 9 et 10 moins % de 0 à 6, de −100 à +100.
     *
     * @param list<int|null> $notes de 0 à 10
     */
    public static function nps(array $notes): ?int
    {
        $notes = self::nombres($notes);
        if ([] === $notes) {
            return null;
        }
        $promoteurs = \count(array_filter($notes, static fn (int $n): bool => $n >= 9));
        $detracteurs = \count(array_filter($notes, static fn (int $n): bool => $n <= 6));

        return (int) round(100 * ($promoteurs - $detracteurs) / \count($notes));
    }

    /** Réponses ÷ sondages envoyés, en % (null sans envoi). */
    public static function tauxReponse(int $reponses, ?int $envoyes): ?int
    {
        if (null === $envoyes || $envoyes <= 0) {
            return null;
        }

        return (int) min(100, round(100 * $reponses / $envoyes));
    }

    /** Écart en points entre deux valeurs (« +0,3 » sur 5, « −4 » de NPS). */
    public static function ecart(int|float|null $actuel, int|float|null $avant): ?float
    {
        return null === $actuel || null === $avant ? null : round($actuel - $avant, 1);
    }

    public static function moyenneEnAlerte(?float $moyenne): bool
    {
        return null !== $moyenne && $moyenne < self::SEUIL_MOYENNE;
    }

    public static function produitEnAlerte(?float $moyenne): bool
    {
        return null !== $moyenne && $moyenne < self::SEUIL_PRODUIT;
    }

    public static function noteEnAlerte(?int $note): bool
    {
        return null !== $note && $note >= 1 && $note <= self::NOTE_ALERTE_MAX;
    }

    /**
     * Les indicateurs d'une période.
     *
     * @param list<int|null> $notes   notes globales sur 5
     * @param list<int|null> $nps     recommandations de 0 à 10
     * @param int|null       $envoyes sondages envoyés (null : pas de taux, sondage libre)
     *
     * @return array{reponses: int, moyenne: ?float, satisfaits: ?int, nps: ?int, taux: ?int, alerte: bool, alerteNps: bool}
     */
    public static function resume(array $notes, array $nps, int $reponses, ?int $envoyes): array
    {
        $moyenne = self::moyenne($notes);
        $score = self::nps($nps);

        return [
            'reponses' => $reponses,
            'moyenne' => $moyenne,
            'satisfaits' => self::satisfaits($notes),
            'nps' => $score,
            'taux' => self::tauxReponse($reponses, $envoyes),
            'alerte' => self::moyenneEnAlerte($moyenne),
            'alerteNps' => null !== $score && $score < 0,
        ];
    }

    /**
     * Résultats question par question.
     *
     * @param list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}> $questions
     * @param array<int, array<string, mixed>>                                                              $reponses  [id de la réponse => [cle => valeur]], la plus récente d'abord
     *
     * @return list<array<string, mixed>>
     */
    public static function parQuestion(array $questions, array $reponses): array
    {
        $resultats = [];
        foreach ($questions as $q) {
            $valeurs = [];
            foreach ($reponses as $id => $r) {
                $v = Questions::valeur($q, $r[$q['cle']] ?? null);
                if (null !== $v) {
                    $valeurs[$id] = $v;
                }
            }
            $n = \count($valeurs);
            $resultat = $q + ['repondu' => $n];
            $part = static fn (int $x): int => 0 === $n ? 0 : (int) round(100 * $x / $n);
            switch ($q['type']) {
                case Questions::ETOILES:
                    $repartition = [];
                    foreach ([5, 4, 3, 2, 1] as $note) {
                        $x = \count(array_filter($valeurs, static fn (int $v): bool => $v === $note));
                        $repartition[] = ['note' => $note, 'nombre' => $x, 'part' => $part($x)];
                    }
                    $resultat += ['moyenne' => self::moyenne(array_values($valeurs)), 'satisfaits' => self::satisfaits(array_values($valeurs)), 'repartition' => $repartition];
                    break;
                case Questions::NPS:
                    $p = \count(array_filter($valeurs, static fn (int $v): bool => $v >= 9));
                    $d = \count(array_filter($valeurs, static fn (int $v): bool => $v <= 6));
                    $resultat += ['score' => self::nps(array_values($valeurs)), 'groupes' => [
                        ['libelle' => 'Promoteurs (9-10)', 'nombre' => $p, 'part' => $part($p)],
                        ['libelle' => 'Passifs (7-8)', 'nombre' => $n - $p - $d, 'part' => $part($n - $p - $d)],
                        ['libelle' => 'Détracteurs (0-6)', 'nombre' => $d, 'part' => $part($d)],
                    ]];
                    break;
                case Questions::OUI_NON:
                    $oui = \count(array_filter($valeurs, static fn (string $v): bool => 'oui' === $v));
                    $resultat += ['options' => [
                        ['libelle' => 'Oui', 'nombre' => $oui, 'part' => $part($oui)],
                        ['libelle' => 'Non', 'nombre' => $n - $oui, 'part' => $part($n - $oui)],
                    ]];
                    break;
                case Questions::CHOIX_UNIQUE:
                case Questions::CHOIX_MULTIPLE:
                    $options = [];
                    foreach ($q['choix'] as $c) {
                        $x = \count(array_filter($valeurs, static fn (string|array $v): bool => \is_array($v) ? \in_array($c, $v, true) : $v === $c));
                        $options[] = ['libelle' => $c, 'nombre' => $x, 'part' => $part($x)];
                    }
                    $resultat += ['options' => $options];
                    break;
                case Questions::TEXTE:
                    $textes = [];
                    foreach (\array_slice($valeurs, 0, self::TEXTES_MONTRES, true) as $id => $texte) {
                        $textes[] = ['reponse' => $id, 'texte' => $texte];
                    }
                    $resultat += ['textes' => $textes];
                    break;
            }
            $resultats[] = $resultat;
        }

        return $resultats;
    }

    /**
     * @param list<int|float|string|null> $notes
     *
     * @return list<int>
     */
    private static function nombres(array $notes): array
    {
        return array_values(array_map('intval', array_filter($notes, static fn ($n): bool => null !== $n && is_numeric($n))));
    }
}
