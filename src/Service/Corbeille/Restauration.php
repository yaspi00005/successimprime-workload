<?php

namespace App\Service\Corbeille;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Remet en base les lignes d'une entrée de la corbeille, avec leurs
 * identifiants d'origine (docs/corbeille.md).
 *
 * Avant d'écrire quoi que ce soit : chaque ligne doit avoir disparu de sa
 * table, et chaque fiche vers laquelle elle pointait doit exister (ou
 * revenir dans la même restauration). Sinon rien n'est fait et le message
 * dit, en français, ce qui manque. L'écriture se fait dans la transaction
 * de l'appelant (Corbeille::restaurer).
 */
final class Restauration
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly SchemaCorbeille $schema,
    ) {
    }

    /**
     * Ce qui empêche la restauration, ou null si elle est possible.
     *
     * @param list<array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, libelle: string}> $lignes lignes décodées, dans l'ordre de remise en place
     * @param callable(string, string): ?string                                                                      $ailleurs entrée de la corbeille qui contient cette ligne (table, clé lisible), ou null
     */
    public function empechement(array $lignes, callable $ailleurs): ?string
    {
        $q = $this->connexion->quoteSingleIdentifier(...);
        $revenues = [];
        foreach ($lignes as $ligne) {
            $revenues[Instantane::cle($ligne['table'], array_values($ligne['cle']))] = true;
        }

        foreach ($lignes as $ligne) {
            if (!$this->schema->existe($ligne['table'])) {
                return \sprintf('%s ne peut pas revenir : sa table « %s » n\'existe plus dans cette version de l\'application.', $ligne['libelle'], $ligne['table']);
            }
            if (isset(TypesCorbeille::NON_RESTAURABLES[$ligne['table']])) {
                return \sprintf('%s ne peut pas revenir : %s.', $ligne['libelle'], TypesCorbeille::NON_RESTAURABLES[$ligne['table']]);
            }
            if ($this->existe($ligne['table'], $ligne['cle'])) {
                return \sprintf('%s est déjà de nouveau en place (n° %s) : rien n\'a été restauré.', $ligne['libelle'], Instantane::cleLisible($ligne['cle']));
            }
            foreach ($this->schema->referencesDe($ligne['table']) as $fk) {
                $cible = [];
                foreach ($fk['colonnes'] as $i => $colonne) {
                    $cible[$fk['colonnesCible'][$i]] = $ligne['donnees'][$colonne] ?? null;
                }
                if (\in_array(null, $cible, true)) {
                    continue;
                }
                if ($fk['colonnesCible'] === $this->schema->clePrimaire($fk['cible']) && isset($revenues[Instantane::cle($fk['cible'], array_values($cible))])) {
                    continue;
                }
                $conditions = array_map(static fn (string $c): string => $q($c).' = ?', array_keys($cible));
                $trouve = $this->connexion->fetchOne(\sprintf('SELECT 1 FROM %s WHERE %s', $q($fk['cible']), implode(' AND ', $conditions)), array_values($cible));
                if (false !== $trouve) {
                    continue;
                }
                $numero = Instantane::cleLisible($cible);
                $entree = $ailleurs($fk['cible'], $numero);
                $manque = \sprintf('%s n° %s', mb_strtolower(TypesCorbeille::type($fk['cible'])), $numero);

                return null !== $entree
                    ? \sprintf('%s ne peut pas revenir seul : %s auquel il était rattaché est aussi dans la corbeille (« %s »). Restaurez-le d\'abord.', $ligne['libelle'], $manque, $entree)
                    : \sprintf('%s ne peut pas revenir : %s auquel il était rattaché n\'existe plus. Rien n\'a été restauré.', $ligne['libelle'], $manque);
            }
        }

        return null;
    }

    /**
     * Écrit les lignes, dans l'ordre. Une valeur unique déjà reprise (même
     * numéro, même code) arrête tout avec un message clair.
     *
     * @param list<array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, libelle: string}> $lignes
     *
     * @throws \DomainException
     */
    public function inserer(array $lignes): void
    {
        foreach ($lignes as $ligne) {
            $colonnes = [];
            foreach ($ligne['donnees'] as $colonne => $valeur) {
                $colonnes[$this->connexion->quoteSingleIdentifier((string) $colonne)] = $valeur;
            }
            try {
                $this->connexion->insert($this->connexion->quoteSingleIdentifier($ligne['table']), $colonnes);
            } catch (UniqueConstraintViolationException) {
                throw new \DomainException(\sprintf('%s ne peut pas revenir : une autre fiche porte déjà le même numéro, le même code ou le même nom. Rien n\'a été restauré.', $ligne['libelle']));
            } catch (ForeignKeyConstraintViolationException) {
                throw new \DomainException(\sprintf('%s ne peut pas revenir : une fiche à laquelle il était rattaché n\'existe plus. Rien n\'a été restauré.', $ligne['libelle']));
            }
        }
    }

    /**
     * Rebranche les fiches restées en place dont le lien avait été vidé,
     * seulement s'il est encore vide (personne ne l'a changé depuis).
     *
     * @param list<array<string, mixed>> $liens [{table, valeurs: {colonne: valeur}, cle: {colonne: valeur}}]
     */
    public function rebrancher(array $liens): int
    {
        $q = $this->connexion->quoteSingleIdentifier(...);
        $n = 0;
        foreach ($liens as $lien) {
            $table = (string) ($lien['table'] ?? '');
            $valeurs = (array) ($lien['valeurs'] ?? []);
            $cle = (array) ($lien['cle'] ?? []);
            if ('' === $table || [] === $valeurs || [] === $cle || !$this->schema->existe($table)) {
                continue;
            }
            $poser = array_map(static fn (string $c): string => $q($c).' = ?', array_keys($valeurs));
            $vides = array_map(static fn (string $c): string => $q($c).' IS NULL', array_keys($valeurs));
            $conditions = array_map(static fn (string $c): string => $q($c).' = ?', array_keys($cle));
            $n += (int) $this->connexion->executeStatement(
                \sprintf('UPDATE %s SET %s WHERE %s AND %s', $q($table), implode(', ', $poser), implode(' AND ', $conditions), implode(' AND ', $vides)),
                [...array_values($valeurs), ...array_values($cle)],
            );
        }

        return $n;
    }

    /** @param array<string, mixed> $cle */
    public function existe(string $table, array $cle): bool
    {
        $q = $this->connexion->quoteSingleIdentifier(...);
        $conditions = array_map(static fn (string $c): string => $q($c).' = ?', array_keys($cle));

        return false !== $this->connexion->fetchOne(\sprintf('SELECT 1 FROM %s WHERE %s', $q($table), implode(' AND ', $conditions)), array_values($cle));
    }
}
