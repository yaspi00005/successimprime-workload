<?php

namespace App\Tests\Unit\Service\Bascule;

use App\Service\Bascule\Bascule;
use App\Service\Bascule\Ecart;
use App\Service\Bascule\LectureDiff;
use Doctrine\DBAL\Platforms\MariaDB1010Platform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;

final class LectureDiffTest extends TestCase
{
    private static function colonne(string $nom, string $type, ?int $longueur = null, bool $obligatoire = true): Column
    {
        return Column::editor()->setUnquotedName($nom)->setTypeName($type)->setLength($longueur)->setNotNull($obligatoire)->create();
    }

    private static function table(string $nom, array $colonnes, array $index = [], array $cles = []): Table
    {
        $editeur = Table::editor()->setUnquotedName($nom)
            ->setColumns(Column::editor()->setUnquotedName('id')->setTypeName('integer')->setAutoincrement(true)->create(), ...$colonnes)
            ->addPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create());
        foreach ($index as $i) {
            $editeur->addIndex($i);
        }
        foreach ($cles as $c) {
            $editeur->addForeignKeyConstraint($c);
        }

        return $editeur->create();
    }

    private static function index(string $nom, string $colonne, bool $unique = false): Index
    {
        $editeur = Index::editor()->setUnquotedName($nom)->setUnquotedColumnNames($colonne);
        if ($unique) {
            $editeur->setType(IndexType::UNIQUE);
        }

        return $editeur->create();
    }

    /** @return array<string, Ecart> par « genre cible » */
    private static function ecarts(Schema $boutique, Schema $reference): array
    {
        $diff = (new Comparator(new MariaDB1010Platform(), Bascule::configurationComparaison()))->compareSchemas($boutique, $reference);
        $parCle = [];
        foreach (LectureDiff::ecarts($diff) as $e) {
            $parCle[$e->genre.' '.$e->cible()] = $e;
        }
        ksort($parCle);

        return $parCle;
    }

    public function testLaComparaisonDonneDesEcartsSimples(): void
    {
        $boutique = new Schema([
            self::table('clients', [
                self::colonne('nom', 'string', 100),
                self::colonne('telephone', 'string', 20, false),
            ]),
            self::table('vieille', []),
            self::table('paiements', [
                self::colonne('commande_id', 'integer', null, false),
                self::colonne('mode', 'string', 100),
            ], [self::index('idx_ancien', 'commande_id')]),
        ]);
        $reference = new Schema([
            self::table('clients', [
                self::colonne('nom', 'string', 150),
                self::colonne('tel', 'string', 20, false),
                self::colonne('public_id', 'binary', 16),
            ], [self::index('UNIQ_PUBLIC', 'public_id', true)]),
            self::table('commandes', []),
            self::table('paiements', [
                self::colonne('commande_id', 'integer', null, false),
                self::colonne('mode', 'string', 30),
            ], [self::index('IDX_COMMANDE', 'commande_id')], [
                ForeignKeyConstraint::editor()->setUnquotedName('FK_PAIEMENT_COMMANDE')
                    ->setUnquotedReferencingColumnNames('commande_id')
                    ->setUnquotedReferencedTableName('commandes')
                    ->setUnquotedReferencedColumnNames('id')
                    ->create(),
            ]),
        ]);

        $ecarts = self::ecarts($boutique, $reference);

        self::assertSame([
            'cle_ajoutee paiements.FK_PAIEMENT_COMMANDE',
            'colonne_ajoutee clients.public_id',
            'colonne_ajoutee clients.tel',
            'colonne_en_trop clients.telephone',
            'colonne_modifiee clients.nom',
            'colonne_modifiee paiements.mode',
            'index_ajoute clients.UNIQ_PUBLIC',
            'index_renomme paiements.idx_ancien',
            'table_ajoutee commandes',
            'table_en_trop vieille',
        ], array_keys($ecarts));

        // Une colonne renommée n'est jamais devinée : une en trop, une ajoutée.
        self::assertFalse($ecarts['colonne_ajoutee clients.tel']->apres['obligatoire']);
        self::assertSame(20, $ecarts['colonne_en_trop clients.telephone']->avant['longueur']);

        $nom = $ecarts['colonne_modifiee clients.nom'];
        self::assertSame(['string', 100, true], [$nom->avant['type'], $nom->avant['longueur'], $nom->avant['obligatoire']]);
        self::assertSame(150, $nom->apres['longueur']);

        self::assertSame(['colonnes' => ['public_id'], 'unique' => true], $ecarts['index_ajoute clients.UNIQ_PUBLIC']->apres);
        self::assertSame('IDX_COMMANDE', $ecarts['index_renomme paiements.idx_ancien']->apres['nom']);
        self::assertSame(
            ['colonnes' => ['commande_id'], 'parente' => 'commandes', 'colonnesParentes' => ['id']],
            $ecarts['cle_ajoutee paiements.FK_PAIEMENT_COMMANDE']->apres,
        );
    }

    public function testDeuxSchemasIdentiquesNeDonnentAucunEcart(): void
    {
        $schema = static fn (): Schema => new Schema([self::table('clients', [self::colonne('nom', 'string', 100)], [self::index('IDX_NOM', 'nom')])]);

        self::assertSame([], self::ecarts($schema(), $schema()));
    }

    public function testVersionPourDatabaseUrl(): void
    {
        self::assertSame('10.4.28-MariaDB', Bascule::versionPourUrl('5.5.5-10.4.28-MariaDB-log'));
        self::assertSame('10.4.28-MariaDB', Bascule::versionPourUrl('10.4.28-MariaDB'));
        self::assertSame('10.11.14-MariaDB', Bascule::versionPourUrl('10.11.14-MariaDB-0+deb12u1'));
        self::assertSame('8.0.36', Bascule::versionPourUrl('8.0.36'));
    }
}
