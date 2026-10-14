<?php

namespace App\Tests\Unit\Service\Societes;

use App\Entity\AppartientASociete;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Service\Societes\RattachementSql;
use App\Service\Societes\SocieteCourante;
use App\Entity\Societe;
use App\Service\Synchro\CatalogueSynchro;
use App\Service\Synchro\Serialiseur;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Règle de rattachement (docs/societes.md, étape 1) : chaque donnée qui a
 * une société a sa règle, la même en PHP (nouveaux documents) et en SQL
 * (lignes insérées à la main, démonstration), appliquée dans le bon ordre.
 */
final class RegleRattachementTest extends TestCase
{
    /** Tables des données qui ont une société. */
    private const TABLES = [
        'Devis' => 'devis', 'Factures' => 'factures', 'Commandes' => 'commandes', 'Paiements' => 'paiements',
        'CompteTresorerie' => 'compte_tresorerie', 'MouvementTresorerie' => 'mouvement_tresorerie', 'DecaissementRecurrent' => 'decaissement_recurrent',
        'RapprochementBancaire' => 'rapprochement_bancaire', 'ClotureCaisse' => 'cloture_caisse', 'Depense' => 'depense', 'Articles' => 'articles',
        'StockEntrees' => 'stock_entrees', 'StockSorties' => 'stock_sorties', 'Achats' => 'achats', 'Immobilisation' => 'immobilisation',
        'DotationAmortissement' => 'dotation_amortissement', 'Entreprise' => 'entreprise',
    ];

    /** Une entité qui reçoit le trait sans règle choisie fait échouer ce test. */
    public function testChaqueDonneeRattacheeASaRegle(): void
    {
        $rattachees = [];
        foreach (glob(__DIR__.'/../../../../src/Entity/*.php') ?: [] as $fichier) {
            $classe = 'App\\Entity\\'.basename($fichier, '.php');
            if (class_exists($classe) && is_subclass_of($classe, AppartientASociete::class)) {
                $rattachees[] = (new \ReflectionClass($classe))->getShortName();
            }
        }
        sort($rattachees);
        $attendues = array_keys(self::TABLES);
        sort($attendues);
        self::assertSame($attendues, $rattachees);

        $sql = implode("\n", RattachementSql::instructions());
        foreach (self::TABLES as $entite => $table) {
            $classe = 'App\\Entity\\'.$entite;
            $parEmetteur = \in_array($classe, [Devis::class, Factures::class], true);
            $parDefaut = \in_array($table, RattachementSql::PAR_DEFAUT, true);
            $parLien = isset(SocieteCourante::LIENS[$classe]);
            foreach (SocieteCourante::LIENS[$classe] ?? [] as $getter) {
                self::assertTrue(method_exists($classe, $getter), $entite.'::'.$getter);
            }
            if (Entreprise::class === $classe) {
                continue; // choisie dans Réglages › Entreprises
            }
            self::assertTrue($parEmetteur || $parDefaut || $parLien || Commandes::class === $classe, $entite.' : quelle société ?');
            self::assertSame(!$parDefaut, str_contains($sql, 'UPDATE '.$table.' '), $table.' : la même règle en SQL');
        }
    }

    /** Une donnée prend la société d'une donnée déjà rangée : l'émetteur, puis la commande, puis le paiement. */
    public function testLOrdreDesInstructions(): void
    {
        $tables = array_map(static fn (string $sql): string => explode(' ', $sql)[1], RattachementSql::instructions());
        $premiere = static fn (string $table): int => (int) array_search($table, $tables, true);
        $derniere = static fn (string $table): int => (int) max(array_keys($tables, $table, true));

        self::assertLessThan($premiere('commandes'), $derniere('factures'));
        self::assertLessThan($premiere('commandes'), $derniere('devis'));
        self::assertLessThan($premiere('paiements'), $derniere('commandes'));
        self::assertLessThan($premiere('mouvement_tresorerie'), $derniere('paiements'));
    }

    public function testLaDemonstrationNeToucheQueSesLignes(): void
    {
        foreach (RattachementSql::instructions(900000) as $sql) {
            self::assertMatchesRegularExpression('/ WHERE \w\.id >= 900000( |$)/', $sql);
            self::assertStringStartsWith('UPDATE ', $sql);
        }
    }

    /**
     * En ligne, la société arrive avec l'entreprise et les documents, par son
     * identifiant public ; RCCM, NIF et adresse de la société restent au local.
     */
    public function testSynchronisation(): void
    {
        $types = CatalogueSynchro::types();
        $ordre = array_keys($types);

        self::assertLessThan(array_search('entreprise', $ordre, true), array_search('societe', $ordre, true), 'la société avant ce qui la cite');
        self::assertSame(['code', 'raisonSociale', 'parDefaut', 'actif'], $types['societe']['champs']);
        self::assertFalse(CatalogueSynchro::accepte('societe', false), 'les sociétés ne se modifient pas en ligne');
        foreach (['entreprise', 'devis', 'commande', 'facture', 'paiement'] as $type) {
            self::assertSame('societe', $types[$type]['relations']['societe'] ?? null, $type);
            self::assertArrayNotHasKey('societe', $types[$type]['remontees'] ?? [], $type.' : la société ne se change pas en ligne');
        }
    }

    /**
     * L'identifiant public d'une société est tiré de son code (MD5, sans la
     * variante RFC) : la synchronisation doit quand même la retrouver.
     */
    public function testIdentifiantTireDuCodeRetrouve(): void
    {
        $societe = new Societe();
        $depot = $this->createMock(EntityRepository::class);
        $depot->expects(self::once())->method('findOneBy')->willReturn($societe);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Societe::class)->willReturn($depot);

        $md5 = md5('societe:IMP');
        $publicId = substr($md5, 0, 8).'-'.substr($md5, 8, 4).'-'.substr($md5, 12, 4).'-'.substr($md5, 16, 4).'-'.substr($md5, 20);
        $serialiseur = new Serialiseur($em);

        self::assertSame($societe, $serialiseur->trouver('societe', $publicId));
        self::assertNull($serialiseur->trouver('societe', 'pas-un-identifiant'));
    }
}
