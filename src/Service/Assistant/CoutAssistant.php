<?php

namespace App\Service\Assistant;

/**
 * Dépense d'une réponse de l'API, d'après son « usage » (jetons lus,
 * écrits, mis en cache). Pur, pour être testé sans base.
 *
 * Le modèle de la conversation est compté au tarif de la tuile Réglages ;
 * un modèle de repli du serveur (refus du premier modèle, fallbacks) l'est
 * au sien, et un modèle inconnu au plus cher de la table.
 */
final class CoutAssistant
{
    /** Dollars par million de jetons : entrée, sortie, lecture du cache, écriture du cache (5 min). */
    public const TARIFS = [
        'claude-opus-5-5' => ['entree' => 4.0, 'sortie' => 20.0, 'cacheLecture' => 0.20, 'cacheEcriture' => 5.0],
        'claude-sonnet-5-5' => ['entree' => 2.0, 'sortie' => 10.0, 'cacheLecture' => 0.20, 'cacheEcriture' => 2.5],
        'claude-haiku-5-5' => ['entree' => 0.10, 'sortie' => 0.50, 'cacheLecture' => 0.01, 'cacheEcriture' => 0.125],
        // Modèles de repli du serveur (fallbacks: default).
        'claude-opus-5' => ['entree' => 5.0, 'sortie' => 25.0, 'cacheLecture' => 0.50, 'cacheEcriture' => 6.25],
        'claude-opus-4-8' => ['entree' => 5.0, 'sortie' => 25.0, 'cacheLecture' => 0.50, 'cacheEcriture' => 6.25],
    ];

    /**
     * Jetons et dollars d'une réponse.
     *
     * Avec le repli du serveur, usage.iterations détaille chaque tentative
     * (« message » puis « fallback_message ») et fait foi ; l'usage du haut
     * ne compte alors que la dernière.
     *
     * @param array<string, mixed>                                                   $usage      usage de la réponse, au format de l'API
     * @param string                                                                 $modele     modèle demandé (celui de la conversation)
     * @param array{entree: float, sortie: float, cacheLecture: float, cacheEcriture: float} $tarif tarif de ce modèle (tuile Réglages)
     *
     * @return array{jetons: array{entree: int, sortie: int, cacheLecture: int, cacheEcriture: int}, usd: float}
     */
    public static function calculer(array $usage, string $modele, array $tarif): array
    {
        $tentatives = [];
        foreach (\is_array($usage['iterations'] ?? null) ? $usage['iterations'] : [] as $iteration) {
            if (\is_array($iteration) && \in_array($iteration['type'] ?? '', ['message', 'fallback_message'], true)) {
                $tentatives[] = $iteration;
            }
        }
        if ([] === $tentatives) {
            $tentatives[] = $usage;
        }

        $jetons = ['entree' => 0, 'sortie' => 0, 'cacheLecture' => 0, 'cacheEcriture' => 0];
        $usd = 0.0;
        foreach ($tentatives as $t) {
            $j = [
                'entree' => (int) ($t['input_tokens'] ?? 0),
                'sortie' => (int) ($t['output_tokens'] ?? 0),
                'cacheLecture' => (int) ($t['cache_read_input_tokens'] ?? 0),
                'cacheEcriture' => (int) ($t['cache_creation_input_tokens'] ?? 0),
            ];
            $modeleTentative = \is_string($t['model'] ?? null) && '' !== $t['model'] ? $t['model'] : $modele;
            $prix = $modeleTentative === $modele ? $tarif : self::tarif($modeleTentative);
            foreach ($j as $cle => $nombre) {
                $jetons[$cle] += $nombre;
                $usd += $nombre * $prix[$cle] / 1_000_000;
            }
        }

        return ['jetons' => $jetons, 'usd' => $usd];
    }

    /**
     * Tarif connu d'un modèle, sinon le plus cher de chaque colonne : le
     * plafond du jour ne sous-estime jamais.
     *
     * @return array{entree: float, sortie: float, cacheLecture: float, cacheEcriture: float}
     */
    public static function tarif(string $modele): array
    {
        if (isset(self::TARIFS[$modele])) {
            return self::TARIFS[$modele];
        }
        $max = ['entree' => 0.0, 'sortie' => 0.0, 'cacheLecture' => 0.0, 'cacheEcriture' => 0.0];
        foreach (self::TARIFS as $t) {
            foreach ($t as $cle => $v) {
                $max[$cle] = max($max[$cle], $v);
            }
        }

        return $max;
    }

    public static function fcfa(float $usd, float $taux): float
    {
        return round($usd * $taux, 2);
    }

    /** Le plafond du jour est-il atteint ? 0 coupe l'assistant. */
    public static function plafondAtteint(float $depenseDuJour, int $limite): bool
    {
        return $limite <= 0 || $depenseDuJour >= $limite;
    }
}
