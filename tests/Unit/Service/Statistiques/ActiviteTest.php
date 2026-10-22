<?php

namespace App\Tests\Unit\Service\Statistiques;

use App\Entity\Commandes;
use App\Service\Statistiques\Activite;
use App\Service\Statistiques\DroitsStatistiques;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\StatistiquesRapports;
use PHPUnit\Framework\TestCase;

/**
 * Statistiques : l'imprimerie seule par défaut, le hors imprimerie à part
 * et le total des deux (docs/hors-imprimerie.md).
 */
final class ActiviteTest extends TestCase
{
    public function testLeFiltreVautLImprimerieSansValeurConnue(): void
    {
        self::assertSame(Activite::IMPRIMERIE, Activite::lire(null));
        self::assertSame(Activite::IMPRIMERIE, Activite::lire('autre'));
        self::assertSame(Activite::IMPRIMERIE, Activite::lire(['hors']));
        self::assertSame(Activite::HORS, Activite::lire('hors'));
        self::assertSame(Activite::TOUT, Activite::lire('tout'));
    }

    public function testQuelleVenteEntreDansLActivite(): void
    {
        self::assertTrue(Activite::retient(false, Activite::IMPRIMERIE));
        self::assertFalse(Activite::retient(true, Activite::IMPRIMERIE));
        self::assertTrue(Activite::retient(true, Activite::HORS));
        self::assertFalse(Activite::retient(false, Activite::HORS));
        self::assertTrue(Activite::retient(true, Activite::TOUT));
        self::assertTrue(Activite::retient(false, Activite::TOUT));
    }

    public function testLaRepartitionAdditionneEtDonneLaPartDuCa(): void
    {
        $r = Activite::repartition(['ca' => 600000, 'commandes' => 12], ['ca' => 1400000, 'commandes' => 2]);

        self::assertSame(['ca' => 2000000, 'commandes' => 14], $r['total']);
        self::assertSame(30, $r['imprimerie']['part']);
        self::assertSame(70, $r['hors']['part']);
    }

    public function testSansCaNiVenteLesPartsRestentVides(): void
    {
        // Sans le droit de voir les montants, pas de CA : pas de part non plus.
        $sansMontant = Activite::repartition(['commandes' => 3], ['commandes' => 0]);
        self::assertSame(['commandes' => 3], $sansMontant['total']);
        self::assertArrayNotHasKey('part', $sansMontant['imprimerie']);

        $vide = Activite::repartition(['ca' => 0, 'commandes' => 0], ['ca' => 0, 'commandes' => 0]);
        self::assertNull($vide['imprimerie']['part']);
        self::assertNull($vide['hors']['part']);
    }

    public function testUnTauxNeSAdditionnePas(): void
    {
        $r = Activite::repartition(['ca' => 100, 'marge' => 40, 'commandes' => 1], ['ca' => 100, 'marge' => null, 'commandes' => 1], ['marge' => 35]);

        self::assertSame(35, $r['total']['marge']);
        self::assertSame(200, $r['total']['ca']);
        // Sans total fourni, un chiffre manquant d'un côté laisse le total vide.
        self::assertNull(Activite::repartition(['marge' => 40], ['marge' => null])['total']['marge']);
    }

    public function testRepartitionDesStatistiquesEtRapports(): void
    {
        $commandes = [(new Commandes()), (new Commandes()), (new Commandes())->setHorsImprimerie(true)];
        $ventes = [['montant' => 50000, 'hors' => false], ['montant' => 950000, 'hors' => true]];
        $chiffrees = [
            ['categorie' => 'Bâche', 'ht' => 1000, 'cout' => 600, 'hors' => false],
            ['categorie' => 'Autre', 'ht' => 1000, 'cout' => 900, 'hors' => true],
        ];

        $r = StatistiquesRapports::repartition($commandes, $ventes, $chiffrees);

        self::assertSame(['ca' => 50000, 'commandes' => 2, 'marge' => 40, 'part' => 5], $r['imprimerie']);
        self::assertSame(['ca' => 950000, 'commandes' => 1, 'marge' => 10, 'part' => 95], $r['hors']);
        self::assertSame(['marge' => 25, 'ca' => 1000000, 'commandes' => 3], $r['total']);
        self::assertArrayNotHasKey('marge', StatistiquesRapports::repartition($commandes, $ventes, null)['total']);
    }

    public function testLExportNeMontreLeCaQuAvecSonDroit(): void
    {
        $avec = ExportsStatistiques::tableauRepartition(Activite::repartition(['ca' => 300, 'commandes' => 2], ['ca' => 700, 'commandes' => 1]));
        self::assertSame(['Activité', 'CA facturé', 'Part', 'Commandes'], array_column($avec->colonnes, 'libelle'));
        self::assertSame([['Imprimerie', 300, 30, 2], ['Hors imprimerie', 700, 70, 1]], $avec->lignes);
        self::assertSame(['Total', 1000, 100, 3], $avec->total);

        $sans = ExportsStatistiques::tableauRepartition(Activite::repartition(['commandes' => 2], ['commandes' => 1]));
        self::assertSame(['Activité', 'Commandes'], array_column($sans->colonnes, 'libelle'));
        self::assertSame(['Total', 3], $sans->total);

        // Écran global sans le droit Ventes : le tableau est là, sans montant.
        $droits = DroitsStatistiques::calculer(static fn (string $r): bool => 'ROLE_STATS_GLOBAL' === $r);
        $d = ['repartition' => Activite::repartition(['commandes' => 2], ['commandes' => 1]), 'parAgent' => [], 'parProduction' => [], 'parMachine' => [], 'topProduits' => [], 'ventesArticles' => [], 'evolutionStock' => ['labels' => [], 'entrees' => [], 'sorties' => []]];
        $export = ExportsStatistiques::globales($d, $droits, 'octobre');
        self::assertSame('Imprimerie et hors imprimerie', $export->tableaux[0]->titre);
        self::assertNotContains('CA facturé', array_column($export->tableaux[0]->colonnes, 'libelle'));
    }
}
