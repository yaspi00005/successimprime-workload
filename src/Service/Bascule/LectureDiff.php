<?php

namespace App\Service\Bascule;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;

/**
 * Traduit la comparaison de Doctrine (base de la boutique → migration de
 * base) en écarts simples, un par table, colonne, index ou lien.
 */
final class LectureDiff
{
    /** @return list<Ecart> */
    public static function ecarts(SchemaDiff $diff): array
    {
        $ecarts = [];
        foreach ($diff->getCreatedTables() as $table) {
            $ecarts[] = new Ecart(Ecart::TABLE_AJOUTEE, self::nomTable($table));
        }
        foreach ($diff->getDroppedTables() as $table) {
            $ecarts[] = new Ecart(Ecart::TABLE_EN_TROP, self::nomTable($table));
        }

        foreach ($diff->getAlteredTables() as $tableDiff) {
            $t = self::nomTable($tableDiff->getOldTable());
            foreach ($tableDiff->getAddedColumns() as $c) {
                $ecarts[] = new Ecart(Ecart::COLONNE_AJOUTEE, $t, self::nomColonne($c), [], self::colonne($c));
            }
            foreach ($tableDiff->getDroppedColumns() as $c) {
                $ecarts[] = new Ecart(Ecart::COLONNE_EN_TROP, $t, self::nomColonne($c), self::colonne($c));
            }
            foreach ($tableDiff->getChangedColumns() as $cd) {
                $ecarts[] = new Ecart(Ecart::COLONNE_MODIFIEE, $t, self::nomColonne($cd->getOldColumn()), self::colonne($cd->getOldColumn()), self::colonne($cd->getNewColumn()));
            }
            foreach ($tableDiff->getAddedIndexes() as $i) {
                $ecarts[] = new Ecart(Ecart::INDEX_AJOUTE, $t, self::nomIndex($i), [], self::index($i));
            }
            foreach ($tableDiff->getDroppedIndexes() as $i) {
                $ecarts[] = new Ecart(Ecart::INDEX_RETIRE, $t, self::nomIndex($i), self::index($i));
            }
            foreach ($tableDiff->getRenamedIndexes() as $ancien => $i) {
                $ecarts[] = new Ecart(Ecart::INDEX_RENOMME, $t, (string) $ancien, [], ['nom' => self::nomIndex($i)] + self::index($i));
            }
            foreach ($tableDiff->getAddedForeignKeys() as $fk) {
                $ecarts[] = new Ecart(Ecart::CLE_AJOUTEE, $t, self::nomCle($fk), [], self::cle($fk));
            }
            foreach ($tableDiff->getDroppedForeignKeys() as $fk) {
                $ecarts[] = new Ecart(Ecart::CLE_RETIREE, $t, self::nomCle($fk), self::cle($fk));
            }
        }

        return $ecarts;
    }

    /** @return array<string, mixed> */
    public static function colonne(Column $c): array
    {
        return [
            'type' => Type::lookupName($c->getType()),
            'longueur' => $c->getLength(),
            'precision' => $c->getPrecision(),
            'echelle' => $c->getScale(),
            'nonSigne' => $c->getUnsigned(),
            'obligatoire' => $c->getNotnull(),
            'defaut' => null === $c->getDefault() ? null : (string) $c->getDefault(),
            'interclassement' => $c->getCollation(),
        ];
    }

    /** @return array{colonnes: list<string>, unique: bool} */
    public static function index(Index $i): array
    {
        return [
            'colonnes' => array_values(array_map(static fn (IndexedColumn $c): string => $c->getColumnName()->getIdentifier()->getValue(), $i->getIndexedColumns())),
            'unique' => IndexType::UNIQUE === $i->getType() || 'primary' === strtolower(self::nomIndex($i)),
        ];
    }

    /** @return array{colonnes: list<string>, parente: string, colonnesParentes: list<string>} */
    public static function cle(ForeignKeyConstraint $fk): array
    {
        $noms = static fn (array $liste): array => array_values(array_map(static fn (UnqualifiedName $n): string => $n->getIdentifier()->getValue(), $liste));

        return [
            'colonnes' => $noms($fk->getReferencingColumnNames()),
            'parente' => $fk->getReferencedTableName()->getUnqualifiedName()->getValue(),
            'colonnesParentes' => $noms($fk->getReferencedColumnNames()),
        ];
    }

    public static function nomTable(Table $t): string
    {
        return $t->getObjectName()->getUnqualifiedName()->getValue();
    }

    public static function nomColonne(Column $c): string
    {
        return $c->getObjectName()->getIdentifier()->getValue();
    }

    public static function nomIndex(Index $i): string
    {
        return $i->getObjectName()->getIdentifier()->getValue();
    }

    public static function nomCle(ForeignKeyConstraint $fk): string
    {
        return $fk->getObjectName()?->getIdentifier()->getValue() ?? '(sans nom)';
    }
}
