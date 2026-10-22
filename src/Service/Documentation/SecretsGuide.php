<?php

namespace App\Service\Documentation;

/**
 * Dernier contrôle avant d'écrire le guide technique : rien qui ressemble
 * à un secret (clé privée, jeton, mot de passe dans une adresse, valeur
 * d'une variable sensible de l'environnement : .env, .env.local).
 *
 * Statique et pur, sauf la lecture de l'environnement.
 */
final class SecretsGuide
{
    /** @var array<string, string> motif => ce qu'il trouve */
    private const MOTIFS = [
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/' => 'clé privée',
        '/\bsk-ant-[A-Za-z0-9_-]{10,}/' => 'clé d\'API Anthropic',
        '/\bAKIA[0-9A-Z]{16}\b/' => 'clé AWS',
        '/\beyJ[A-Za-z0-9_-]{15,}\.[A-Za-z0-9_-]{15,}\./' => 'jeton JWT',
        '#\b[a-z][a-z0-9+.-]*://[^\s:/@"]+:[^\s@"/]{3,}@#i' => 'mot de passe dans une adresse',
        '/\bsi_[A-Za-z0-9]{24,}\b/' => 'jeton d\'API de l\'application',
        '/(?<![A-Za-z0-9])[0-9a-f]{48,}(?![A-Za-z0-9])/' => 'longue suite hexadécimale (clé ?)',
    ];

    /** Noms de variables d'environnement dont la valeur ne doit jamais sortir. */
    private const VARIABLES = '/(SECRET|KEY|CLE|TOKEN|PASS|DSN|DATABASE_URL|PRIVATE|CLIENT_ID|SID)/i';

    /**
     * @param array<string|int, string> $valeurs valeurs exactes interdites (nom de la variable => valeur)
     *
     * @return list<string> ce qui a été trouvé (sans le secret lui-même)
     */
    public static function chercher(string $texte, array $valeurs = []): array
    {
        $trouves = [];
        foreach (self::MOTIFS as $motif => $quoi) {
            if (preg_match_all($motif, $texte, $m, \PREG_OFFSET_CAPTURE) > 0) {
                // La ligne seulement : le secret lui-même ne s'affiche pas.
                $ligne = substr_count(substr($texte, 0, $m[0][0][1]), "\n") + 1;
                $trouves[] = \sprintf('%s (%d fois, la première à la ligne %d)', $quoi, \count($m[0]), $ligne);
            }
        }
        foreach ($valeurs as $nom => $valeur) {
            if (\strlen($valeur) >= 8 && str_contains($texte, $valeur)) {
                $trouves[] = 'valeur de la variable '.(\is_string($nom) ? $nom : 'sensible').' de l\'environnement';
            }
        }

        return $trouves;
    }

    /**
     * Valeurs des variables sensibles de l'environnement (pour vérifier
     * qu'aucune n'est dans le guide).
     *
     * @return array<string, string>
     */
    public static function valeursSensiblesDeLEnvironnement(): array
    {
        $valeurs = [];
        foreach ([$_SERVER, $_ENV, getenv()] as $source) {
            foreach ($source as $nom => $valeur) {
                if (\is_string($nom) && \is_string($valeur) && preg_match(self::VARIABLES, $nom) && \strlen($valeur) >= 8 && !\in_array(strtolower($valeur), ['doctrine://default', 'null://null', 'sync://'], true)) {
                    $valeurs[$nom] = $valeur;
                }
            }
        }

        return $valeurs;
    }
}
