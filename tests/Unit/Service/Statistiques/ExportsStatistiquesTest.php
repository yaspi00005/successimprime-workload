<?php

namespace App\Tests\Unit\Service\Statistiques;

use App\Service\Statistiques\DroitsStatistiques;
use App\Service\Statistiques\Export\Export;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\Export\Tableau;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;

final class ExportsStatistiquesTest extends TestCase
{
    private const TOUT = ['ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR', 'ROLE_COUT_MARGE', 'ROLE_STATS_PERSO'];

    /** @return list<string> */
    private static function titres(Export $e): array
    {
        return array_map(static fn (Tableau $t): string => $t->titre, $e->tableaux);
    }

    private static function tableau(Export $e, string $titre): Tableau
    {
        foreach ($e->tableaux as $t) {
            if ($t->titre === $titre) {
                return $t;
            }
        }
        self::fail('Tableau absent : '.$titre);
    }

    /** @return list<string> */
    private static function colonnes(Tableau $t): array
    {
        return array_column($t->colonnes, 'libelle');
    }

    private static function globales(): array
    {
        return [
            'parAgent' => [['nom' => 'Aminata', 'nombre' => 3, 'montant' => 150000], ['nom' => 'Moussa', 'nombre' => 1, 'montant' => 20000]],
            'parCaissiere' => [['nom' => 'Mariam', 'nombre' => 4, 'montant' => 90000]],
            'parProduction' => [['nom' => 'Oumar', 'nombre' => 7]],
            'parMachine' => [['nom' => 'DTF', 'nombre' => 2, 'quantite' => 12.5]],
            'topProduits' => [['nom' => 'Flyers', 'nombre' => 2, 'quantite' => 1000]],
            'ventesArticles' => [['nom' => 'Mug', 'nombre' => 1, 'quantite' => 2, 'montant' => 6000]],
            'evolutionStock' => ['labels' => ['Sept.', 'Oct.'], 'entrees' => [5, 2], 'sorties' => [1, 3]],
        ];
    }

    public function testGlobalesAvecTousLesDroits(): void
    {
        $e = ExportsStatistiques::globales(self::globales(), DroitsStatistiques::depuisRoles(self::TOUT), 'du 01/10/2026 au 31/10/2026');

        self::assertContains('Encaissements par caissière', self::titres($e));
        $agents = self::tableau($e, 'Ventes par agent');
        self::assertSame(['Agent', 'Commandes', 'Montant TTC'], self::colonnes($agents));
        self::assertSame(['Aminata', 3, 150000], $agents->lignes[0]);
        self::assertSame(['Total', 4, 170000], $agents->total);
        self::assertSame([['Sept.', 5, 1], ['Oct.', 2, 3]], self::tableau($e, 'Variation du stock')->lignes);
        self::assertSame('statistiques-globales-du-01-10-2026-au-31-10-2026.xlsx', $e->nomFichier('xlsx'));
    }

    public function testGlobalesSansMontantsNiPaiements(): void
    {
        $d = self::globales();
        $d['parCaissiere'] = null;
        $e = ExportsStatistiques::globales($d, DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL']), 'octobre 2026');

        self::assertNotContains('Encaissements par caissière', self::titres($e));
        $agents = self::tableau($e, 'Ventes par agent');
        self::assertSame(['Agent', 'Commandes'], self::colonnes($agents));
        self::assertSame(['Aminata', 3], $agents->lignes[0]);
        self::assertSame(['Total', 4], $agents->total);
        self::assertSame(['Article', 'Sorties', 'Quantité'], self::colonnes(self::tableau($e, 'Ventes d\'articles')));

        // Même si le contrôleur passait les encaissements, sans le droit ils ne sortent pas.
        $fuite = ExportsStatistiques::globales(self::globales(), DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL']), 'octobre 2026');
        self::assertNotContains('Encaissements par caissière', self::titres($fuite));
    }

    public function testCommandesSelonLesDroits(): void
    {
        $d = [
            'evolution' => ['labels' => ['Sept.', 'Oct.'], 'commandes' => [10, 12], 'ca' => [500000, 640000]],
            'parTranche' => [['libelle' => '0 à 30 jours', 'nombre' => 1, 'montant' => 25000]],
            'commandesNonSoldees' => [['numero' => 'CMD-1', 'client' => 'Awa', 'date' => new \DateTimeImmutable('2026-10-02'), 'jours' => 12, 'reste' => 25000]],
            'totalReste' => 25000,
        ];

        $tout = ExportsStatistiques::commandes($d, DroitsStatistiques::depuisRoles(self::TOUT), 'par mois');
        self::assertSame(['Évolution des commandes', 'Impayés par ancienneté', 'Commandes non soldées'], self::titres($tout));
        self::assertSame([['Sept.', 10, 500000], ['Oct.', 12, 640000]], $tout->tableaux[0]->lignes);
        self::assertSame(['CMD-1', 'Awa', $d['commandesNonSoldees'][0]['date'], 12, 25000], self::tableau($tout, 'Commandes non soldées')->lignes[0]);

        $sansDroits = ExportsStatistiques::commandes($d, DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL']), 'par mois');
        self::assertSame(['Évolution des commandes'], self::titres($sansDroits));
        self::assertSame(['Période', 'Commandes'], self::colonnes($sansDroits->tableaux[0]));
        self::assertSame([['Sept.', 10], ['Oct.', 12]], $sansDroits->tableaux[0]->lignes);
    }

    public function testClientsSansMontant(): void
    {
        $d = ['caParClient' => [['nom' => 'Awa', 'nombre' => 2, 'montant' => 50000]], 'nouveauxClients' => ['labels' => ['Oct.'], 'valeurs' => [3]]];

        self::assertSame(['Client', 'Commandes', 'Chiffre d\'affaires'], self::colonnes(ExportsStatistiques::clients($d, DroitsStatistiques::depuisRoles(self::TOUT), 'x', 'mois')->tableaux[0]));
        $e = ExportsStatistiques::clients($d, DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL']), 'x', 'mois');
        self::assertSame([['Awa', 2]], $e->tableaux[0]->lignes);
        self::assertSame('Nouveaux clients par mois', $e->tableaux[1]->titre);
    }

    public function testRapportsSansIndicateursCommerciaux(): void
    {
        $d = [
            'kpi' => ['surface' => 218.4, 'surface_evolution' => 12, 'nouveaux' => 4, 'nouveaux_evolution' => null, 'commandes' => 54, 'marge' => ['taux' => 41], 'marge_evolution' => -2],
            'objectifs' => [],
        ];
        $e = ExportsStatistiques::rapports($d, DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL']), '12 mois');

        self::assertSame(['Indicateurs'], self::titres($e));
        self::assertSame(['m² imprimés', 'Nouveaux clients', 'Commandes'], array_column($e->tableaux[0]->lignes, 0));
        self::assertSame('pas de comparaison', $e->tableaux[0]->lignes[1][3]);

        $avecMarge = ExportsStatistiques::rapports($d, DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL', 'ROLE_COUT_MARGE']), '12 mois');
        self::assertSame(['Marge brute', 41, '%', '−2 pts'], $avecMarge->tableaux[0]->lignes[0]);
    }

    public function testRapportsAvecObjectifsParMois(): void
    {
        $d = [
            'kpi' => ['ca' => 3000000, 'ca_evolution' => 5, 'surface' => 10, 'surface_evolution' => null, 'nouveaux' => 1, 'nouveaux_evolution' => null, 'commandes' => 3, 'panier' => 100000, 'factures' => 30, 'marge' => null],
            'mois' => [
                ['mois' => new \DateTimeImmutable('2026-09-01'), 'montant' => 1000000, 'avant' => 800000],
                ['mois' => new \DateTimeImmutable('2026-10-01'), 'montant' => 2000000, 'avant' => 900000],
            ],
            'objectifs_mois' => ['2026-09' => null, '2026-10' => 4000000],
            'top_client' => null,
            'top_agent' => ['nom' => 'Aminata', 'montant' => 900000, 'part' => 30],
        ];
        $e = ExportsStatistiques::rapports($d, DroitsStatistiques::depuisRoles(self::TOUT), '12 mois');

        $mois = self::tableau($e, 'CA facturé par mois');
        self::assertSame(['Mois', 'CA facturé', 'Même mois N−1', 'Objectif', 'Atteint'], self::colonnes($mois));
        self::assertSame([['09/2026', 1000000, 800000, null, null], ['10/2026', 2000000, 900000, 4000000, 50]], $mois->lignes);
        self::assertSame(['Total', 3000000, 1700000, 4000000, null], $mois->total);
        self::assertSame([['Top agent', 'Aminata', 900000, 30]], self::tableau($e, 'Meilleurs')->lignes);
    }

    public function testUnTableauParObjectif(): void
    {
        $ligne = static fn (string $indicateur, string $axe, string $libelle, string $etat, ?int $objectif): array => [
            'indicateur' => $indicateur, 'axe' => $axe, 'libelle' => $libelle, 'etat' => $etat, 'realise' => 50.0, 'objectif' => $objectif,
            'part' => null === $objectif ? null : 50, 'projection' => 120.0, 'part_projetee' => null === $objectif ? null : 120,
        ];
        $tableaux = ExportsStatistiques::tableauxObjectifs([
            $ligne('ca', 'global', 'CA facturé', 'en_bonne_voie', 100),
            $ligne('ca', 'agent', 'Aminata', 'sans_objectif', null),
            $ligne('taux_encaissement', 'global', 'Taux', 'a_surveiller', 80),
        ]);

        self::assertCount(2, $tableaux);
        self::assertSame('Objectif · CA facturé', $tableaux[0]->titre);
        self::assertSame(['Périmètre', 'Nom', 'Réalisé', 'Objectif', 'Atteint', 'Projection fin de mois', 'Projection / objectif', 'État'], self::colonnes($tableaux[0]));
        self::assertSame(['Entreprise', 'Toute l\'entreprise', 50.0, 100, 50, 120.0, 120, 'En bonne voie'], $tableaux[0]->lignes[0]);
        self::assertSame('Sans objectif', $tableaux[0]->lignes[1][7]);
        // Un taux ne se projette pas.
        self::assertSame(['Périmètre', 'Nom', 'Réalisé', 'Objectif', 'Atteint', 'État'], self::colonnes($tableaux[1]));
    }

    public function testMesStatistiquesNExportentQueCeQuiEstVisible(): void
    {
        $s = ['ventes' => 100000, 'commandes' => 2, 'panierMoyen' => 50000, 'devisEmis' => 3, 'tauxTransformation' => 66, 'encaissements' => 80000, 'devisARelancer' => 1, 'tauxRemise' => 1.4, 'parMois' => [['mois' => new \DateTimeImmutable('2026-10-01'), 'montant' => 100000]]];
        $e = ExportsStatistiques::mesStatistiques(['s' => $s, 'objectifs' => [], 'chiffreAffaires' => null, 'marge' => null, 'tresorerie' => null], 'Aminata Keïta', 'octobre 2026');

        self::assertSame(['Indicateurs', 'Ventes par mois'], self::titres($e));
        self::assertSame('Statistiques · Aminata Keïta', $e->titre);

        $avec = ExportsStatistiques::mesStatistiques(['s' => $s, 'objectifs' => [], 'chiffreAffaires' => null, 'marge' => null, 'tresorerie' => 31000000], 'Aminata Keïta', 'octobre 2026');
        self::assertSame([['Trésorerie (soldes des comptes)', 31000000]], self::tableau($avec, 'Chiffres de l\'entreprise')->lignes);
    }

    public function testTableauRefuseUneLigneBoiteuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tableau('X', ['A' => 'texte', 'B' => 'fcfa'], [['seul']]);
    }

    public function testAffichageDesValeurs(): void
    {
        self::assertSame('1 250 000 F', Tableau::afficher(1250000, 'fcfa'));
        self::assertSame('82 %', Tableau::afficher(82, 'pourcent'));
        self::assertSame('82,5 %', Tableau::afficher(82.5, 'pourcent'));
        self::assertSame('218,4 m²', Tableau::afficher(218.44, 'm2'));
        self::assertSame('02/10/2026', Tableau::afficher(new \DateTimeImmutable('2026-10-02'), 'date'));
        self::assertSame('—', Tableau::afficher(null, 'fcfa'));
    }

    public function testNomsDeFeuilles(): void
    {
        $pris = [];

        self::assertSame('Objectif · CA facturé', ExportExcel::nomFeuille('Objectif · CA facturé', $pris));
        self::assertSame('Ventes 2026 10', ExportExcel::nomFeuille('Ventes [2026/10]', $pris));
        self::assertSame(31, mb_strlen(ExportExcel::nomFeuille(str_repeat('Chiffre d\'affaires ', 4), $pris)));
        // Excel ne distingue pas les majuscules dans les noms de feuilles.
        self::assertSame('objectif · ca facturé (2)', ExportExcel::nomFeuille('objectif · ca facturé', $pris));
        self::assertSame('Feuille', ExportExcel::nomFeuille('[]', $pris));
    }

    public function testValeursDesCellules(): void
    {
        self::assertSame(0.82, ExportExcel::valeurCellule(82, 'pourcent'));
        self::assertSame(1250000, ExportExcel::valeurCellule(1249999.6, 'fcfa'));
        self::assertSame(12.3, ExportExcel::valeurCellule(12.34, 'm2'));
        self::assertNull(ExportExcel::valeurCellule(null, 'entier'));
        self::assertSame('=1+1', ExportExcel::valeurCellule('=1+1', 'texte'));
        self::assertEqualsWithDelta(46297.0, ExportExcel::valeurCellule(new \DateTimeImmutable('2026-10-02 00:00'), 'date'), 0.001);
    }

    public function testClasseurUneFeuilleParTableau(): void
    {
        $export = new Export('Commandes', 'octobre 2026', [
            new Tableau('Impayés', ['Client' => 'texte', 'Date' => 'date', 'Part' => 'pourcent', 'Reste' => 'fcfa'], [['=HYPERLINK("x")', new \DateTimeImmutable('2026-10-02'), 40, 25000]], ['Total', null, null, 25000]),
            new Tableau('Vide', ['Nom' => 'texte'], []),
        ], 'commandes');
        $classeur = (new ExportExcel())->classeur($export, 'Succès Imprim', new \DateTimeImmutable('2026-10-14 09:00'), 'Kadiatou Diallo');

        self::assertSame(['Impayés', 'Vide'], $classeur->getSheetNames());
        $f = $classeur->getSheet(0);
        self::assertSame('Commandes · Impayés', $f->getCell('A1')->getValue());
        self::assertSame('Succès Imprim · octobre 2026', $f->getCell('A2')->getValue());
        self::assertSame('Édité le 14/10/2026 à 09:00 par Kadiatou Diallo', $f->getCell('A3')->getValue());
        self::assertSame(['Client', 'Date', 'Part', 'Reste'], [$f->getCell('A5')->getValue(), $f->getCell('B5')->getValue(), $f->getCell('C5')->getValue(), $f->getCell('D5')->getValue()]);
        // Un texte ne devient jamais une formule.
        self::assertSame(DataType::TYPE_STRING, $f->getCell('A6')->getDataType());
        self::assertSame('dd/mm/yyyy', $f->getStyle('B6')->getNumberFormat()->getFormatCode());
        self::assertSame(0.4, $f->getCell('C6')->getValue());
        self::assertSame('0%', $f->getStyle('C6')->getNumberFormat()->getFormatCode());
        self::assertSame('#,##0 "F"', $f->getStyle('D7')->getNumberFormat()->getFormatCode());
        self::assertTrue($f->getStyle('A7')->getFont()->getBold());
        self::assertSame('A6', $f->getFreezePane());
        self::assertSame('Aucune donnée sur la période.', $classeur->getSheet(1)->getCell('A6')->getValue());

        $vide = (new ExportExcel())->classeur(new Export('Rien', 'x', [], 'rien'), 'S', new \DateTimeImmutable(), 'K');
        self::assertSame('Aucun chiffre à exporter avec vos droits.', $vide->getSheet(0)->getCell('A6')->getValue());
    }
}
