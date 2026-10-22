<?php

namespace App\Service\Bascule;

use Doctrine\DBAL\Connection;

/**
 * Lit dans la base de l'imprimerie les nombres dont le classement a besoin
 * (lignes, valeurs remplies, doublons, lignes orphelines). Lecture seule ;
 * aucune valeur ne sort, seulement des comptes.
 */
final class SondeBase implements SondeDonnees
{
    /** @var array<string, int> */
    private array $lignes = [];

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function lignes(string $table): int
    {
        return $this->lignes[$table] ??= (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM '.$this->nom($table));
    }

    public function renseignees(string $table, string $colonne): int
    {
        return (int) $this->connexion->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s IS NOT NULL', $this->nom($table), $this->nom($colonne)));
    }

    public function longueurMax(string $table, string $colonne): int
    {
        return (int) $this->connexion->fetchOne(\sprintf('SELECT COALESCE(MAX(CHAR_LENGTH(%s)), 0) FROM %s', $this->nom($colonne), $this->nom($table)));
    }

    public function doublons(string $table, array $colonnes, bool $sansIdentifiantsVides = false): int
    {
        $liste = implode(', ', array_map($this->nom(...), $colonnes));
        $nonNuls = implode(' AND ', array_map(fn (string $c): string => $sansIdentifiantsVides
            ? \sprintf('%1$s IS NOT NULL AND HEX(%1$s) <> REPEAT(\'0\', LENGTH(%1$s) * 2)', $this->nom($c))
            : $this->nom($c).' IS NOT NULL', $colonnes));

        return (int) $this->connexion->fetchOne(\sprintf(
            'SELECT COUNT(*) FROM (SELECT 1 FROM %s WHERE %s GROUP BY %s HAVING COUNT(*) > 1) d',
            $this->nom($table), $nonNuls, $liste,
        ));
    }

    public function identifiantsVides(string $table, string $colonne): int
    {
        return (int) $this->connexion->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE %s', $this->nom($table), self::conditionIdentifiantVide($this->nom($colonne))));
    }

    /** Condition SQL d'un identifiant binaire jamais tiré (NULL ou octets à zéro). */
    public static function conditionIdentifiantVide(string $colonne): string
    {
        return \sprintf('(%1$s IS NULL OR HEX(%1$s) = REPEAT(\'0\', LENGTH(%1$s) * 2))', $colonne);
    }

    public function orphelins(string $table, array $colonnes, string $parente, array $colonnesParentes): int
    {
        $jointure = [];
        $nonNuls = [];
        foreach ($colonnes as $i => $colonne) {
            $jointure[] = \sprintf('p.%s = t.%s', $this->nom($colonnesParentes[$i] ?? 'id'), $this->nom($colonne));
            $nonNuls[] = 't.'.$this->nom($colonne).' IS NOT NULL';
        }

        return (int) $this->connexion->fetchOne(\sprintf(
            'SELECT COUNT(*) FROM %s t LEFT JOIN %s p ON %s WHERE %s AND p.%s IS NULL',
            $this->nom($table), $this->nom($parente), implode(' AND ', $jointure), implode(' AND ', $nonNuls), $this->nom($colonnesParentes[0] ?? 'id'),
        ));
    }

    public function valeurExiste(string $table, string $colonne, string $valeur): bool
    {
        return false !== $this->connexion->fetchOne(\sprintf('SELECT 1 FROM %s WHERE %s = ? LIMIT 1', $this->nom($table), $this->nom($colonne)), [$valeur]);
    }

    private function nom(string $identifiant): string
    {
        return '`'.str_replace('`', '``', $identifiant).'`';
    }
}
