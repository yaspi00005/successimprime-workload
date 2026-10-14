<?php

namespace App\Tests\Unit\Service;

use App\Service\Parametres\CatalogueParametres;
use PHPUnit\Framework\TestCase;

final class CatalogueParametresTest extends TestCase
{
    public function testSixFamillesEtToutesLesTuilesRangees(): void
    {
        $tuiles = CatalogueParametres::tuiles();

        self::assertCount(6, CatalogueParametres::FAMILLES);
        self::assertCount(29, $tuiles);

        foreach ($tuiles as $cle => $tuile) {
            self::assertArrayHasKey($tuile['famille'], CatalogueParametres::FAMILLES, $cle);
            self::assertNotEmpty($tuile['roles'], $cle.' doit être réservé à des rôles');
            self::assertContains('ROLE_ADMIN', $tuile['roles'], $cle);
            foreach ($tuile['champs'] as $champ) {
                self::assertTrue(isset($champ['source']) || \array_key_exists('defaut', $champ), $cle.'.'.$champ['cle'].' sans valeur par défaut');
            }
        }
    }

    public function testRechercheOrangeTrouveLesCinqReglagesDeLaMaquette(): void
    {
        $trouves = [];
        foreach (CatalogueParametres::tuiles() as $cle => $tuile) {
            foreach ($tuile['motsCles'] as $texte) {
                if (str_contains(mb_strtolower($texte), 'orange')) {
                    $trouves[$cle] = true;
                }
            }
        }

        foreach (['paiements', 'avantages', 'paie', 'modeles'] as $attendu) {
            self::assertArrayHasKey($attendu, $trouves);
        }
    }

    public function testValeursParDefautEtStockees(): void
    {
        $champs = CatalogueParametres::tuiles()['pointage']['champs'];
        $valeurs = CatalogueParametres::avecDefauts($champs, ['tolerance_minutes' => 15]);

        self::assertSame(15, $valeurs['tolerance_minutes']);
        self::assertTrue($valeurs['photo']);
        self::assertSame('20:00', $valeurs['alerte_depart']);
    }

    public function testChampsAvecSourceNeSontPasStockes(): void
    {
        $valeurs = CatalogueParametres::avecDefauts(CatalogueParametres::tuiles()['paiements']['champs'], []);

        self::assertArrayNotHasKey('tauxFraisRetrait', $valeurs);
        self::assertSame(50, $valeurs['acompte']);
    }

    public function testLectureDeLaSaisie(): void
    {
        $champs = CatalogueParametres::tuiles()['paiements']['champs'];
        $lecture = CatalogueParametres::lireSaisie($champs, [
            'orange_money' => '1',
            'virement_seuil' => '500 000',
            'acompte' => '30',
            'recu' => 'sms',
            'tauxFraisRetrait' => '1,5',
        ]);

        self::assertSame([], $lecture['erreurs']);
        self::assertTrue($lecture['valeurs']['orange_money']);
        self::assertFalse($lecture['valeurs']['especes'], 'case décochée = non');
        self::assertSame(500000, $lecture['valeurs']['virement_seuil']);
        self::assertSame(1.5, $lecture['valeurs']['tauxFraisRetrait']);
        self::assertSame('sms', $lecture['valeurs']['recu']);
    }

    public function testSaisieRefusee(): void
    {
        $lecture = CatalogueParametres::lireSaisie(CatalogueParametres::tuiles()['paiements']['champs'], [
            'virement_seuil' => 'beaucoup',
            'acompte' => '150',
            'recu' => 'pigeon',
            'tauxFraisRetrait' => '1',
        ]);

        self::assertArrayHasKey('virement_seuil', $lecture['erreurs']);
        self::assertArrayHasKey('acompte', $lecture['erreurs']);
        self::assertArrayHasKey('recu', $lecture['erreurs']);

        $heures = CatalogueParametres::lireSaisie(CatalogueParametres::tuiles()['horaires']['champs'], ['jours' => 'lun-sam', 'ouverture' => '25:00', 'fermeture' => '18:00']);
        self::assertSame(['ouverture'], array_keys($heures['erreurs']));
    }

    public function testDifferencesNeGardeQueCeQuiChange(): void
    {
        $diff = CatalogueParametres::differences(
            ['a' => true, 'b' => 10, 'c' => 1.0],
            ['a' => true, 'b' => 12, 'c' => 1],
        );

        self::assertSame(['b' => 10], $diff['avant']);
        self::assertSame(['b' => 12], $diff['apres']);
    }

    public function testAffichage(): void
    {
        self::assertSame('oui', CatalogueParametres::afficher(['type' => 'interrupteur'], true));
        self::assertSame('500 000 F', CatalogueParametres::afficher(['type' => 'nombre', 'suffixe' => 'F'], 500000));
        self::assertSame('1,50 %', CatalogueParametres::afficher(['type' => 'nombre', 'suffixe' => '%', 'decimales' => 2], 1.5));
        self::assertSame('lun – sam', CatalogueParametres::afficher(CatalogueParametres::champ('horaires', 'jours') ?? [], 'lun-sam'));
    }

    public function testRecentsSansDoublonEtLimites(): void
    {
        self::assertSame(['paie', 'pointage', 'grille'], CatalogueParametres::ajouterRecent(['pointage', 'grille', 'paie', 'taxes'], 'paie'));
        self::assertSame(['taxes'], CatalogueParametres::ajouterRecent([], 'taxes'));
    }
}
