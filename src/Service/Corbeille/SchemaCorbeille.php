<?php

namespace App\Service\Corbeille;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Ce que la base dit de ses tables, pour la corbeille : clé primaire de
 * chaque table et clés étrangères (qui pointe vers quoi, et ce que la base
 * fait à la suppression : CASCADE, SET NULL ou refus).
 *
 * Lu une fois par processus. Sur MariaDB, deux requêtes sur
 * information_schema ; ailleurs (SQLite des tests), l'introspection de DBAL.
 */
final class SchemaCorbeille
{
    /** @var array<string, list<string>>|null table => colonnes de la clé primaire */
    private ?array $cles = null;

    /** @var list<array{table: string, colonnes: list<string>, cible: string, colonnesCible: list<string>, regle: string}>|null */
    private ?array $etrangeres = null;

    public function __construct(private readonly Connection $connexion)
    {
    }

    /** @return list<string> */
    public function clePrimaire(string $table): array
    {
        $this->charger();

        return $this->cles[$table] ?? [];
    }

    public function existe(string $table): bool
    {
        $this->charger();

        return isset($this->cles[$table]);
    }

    /**
     * Clés étrangères des autres tables qui pointent vers celle-ci.
     *
     * @return list<array{table: string, colonnes: list<string>, cible: string, colonnesCible: list<string>, regle: string}>
     */
    public function referencesVers(string $table): array
    {
        $this->charger();

        return array_values(array_filter($this->etrangeres ?? [], static fn (array $f): bool => $f['cible'] === $table));
    }

    /**
     * Clés étrangères de cette table vers d'autres.
     *
     * @return list<array{table: string, colonnes: list<string>, cible: string, colonnesCible: list<string>, regle: string}>
     */
    public function referencesDe(string $table): array
    {
        $this->charger();

        return array_values(array_filter($this->etrangeres ?? [], static fn (array $f): bool => $f['table'] === $table));
    }

    private function charger(): void
    {
        if (null !== $this->cles) {
            return;
        }
        if ($this->connexion->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->chargerMariaDb();

            return;
        }
        $this->chargerDbal();
    }

    private function chargerMariaDb(): void
    {
        $cles = [];
        foreach ($this->connexion->fetchAllAssociative(
            "SELECT t.TABLE_NAME AS t, k.COLUMN_NAME AS c FROM information_schema.TABLES t
             LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.TABLE_SCHEMA = t.TABLE_SCHEMA AND k.TABLE_NAME = t.TABLE_NAME AND k.CONSTRAINT_NAME = 'PRIMARY'
             WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' ORDER BY t.TABLE_NAME, k.ORDINAL_POSITION"
        ) as $l) {
            $cles[(string) $l['t']] ??= [];
            if (null !== $l['c']) {
                $cles[(string) $l['t']][] = (string) $l['c'];
            }
        }

        $etrangeres = [];
        foreach ($this->connexion->fetchAllAssociative(
            'SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS cible, k.REFERENCED_COLUMN_NAME AS cc, r.DELETE_RULE AS regle
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
             WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION'
        ) as $l) {
            $cle = $l['t'].'|'.$l['n'];
            $etrangeres[$cle] ??= ['table' => (string) $l['t'], 'colonnes' => [], 'cible' => (string) $l['cible'], 'colonnesCible' => [], 'regle' => strtoupper((string) $l['regle'])];
            $etrangeres[$cle]['colonnes'][] = (string) $l['c'];
            $etrangeres[$cle]['colonnesCible'][] = (string) $l['cc'];
        }

        $this->cles = $cles;
        $this->etrangeres = array_values($etrangeres);
    }

    private function chargerDbal(): void
    {
        $gestion = $this->connexion->createSchemaManager();
        $cles = [];
        $etrangeres = [];
        foreach ($gestion->introspectSchema()->getTables() as $table) {
            $nom = $table->getObjectName()->toString();
            $nom = trim($nom, '"`');
            $primaire = $table->getPrimaryKeyConstraint();
            $cles[$nom] = null === $primaire ? [] : array_map(static fn ($c): string => trim($c->toString(), '"`'), $primaire->getColumnNames());
            foreach ($table->getForeignKeys() as $fk) {
                $etrangeres[] = [
                    'table' => $nom,
                    'colonnes' => array_map(static fn ($c): string => trim($c->toString(), '"`'), $fk->getReferencingColumnNames()),
                    'cible' => trim($fk->getReferencedTableName()->toString(), '"`'),
                    'colonnesCible' => array_map(static fn ($c): string => trim($c->toString(), '"`'), $fk->getReferencedColumnNames()),
                    'regle' => strtoupper(str_replace('_', ' ', $fk->getOnDeleteAction()->name)),
                ];
            }
        }
        $this->cles = $cles;
        $this->etrangeres = $etrangeres;
    }
}
