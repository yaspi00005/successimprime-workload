<?php

namespace App\Tests\Unit\Service\Parametres;

use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\ReglagesSaisie;
use PHPUnit\Framework\TestCase;

final class ReglagesSaisieTest extends TestCase
{
    public function testPaliersParDefaut(): void
    {
        self::assertSame([1000, 1500, 2000, 2500], ReglagesSaisie::lirePaliers(ReglagesSaisie::PALIERS_DEFAUT));
    }

    public function testPaliersLusRangesEtSansDoublon(): void
    {
        // Espaces de milliers, « F », point-virgule et retour à la ligne acceptés.
        self::assertSame([500, 1500, 2000], ReglagesSaisie::lirePaliers("2 000 F ; 1\u{a0}500\n500, 1500"));
        self::assertSame([], ReglagesSaisie::lirePaliers('   '), 'vide : aucun palier');
    }

    public function testPaliersRefuses(): void
    {
        self::assertNull(ReglagesSaisie::lirePaliers('1000, beaucoup'));
        self::assertNull(ReglagesSaisie::lirePaliers('1000, -500'));
        self::assertNull(ReglagesSaisie::lirePaliers('0, 1000'));
        self::assertNull(ReglagesSaisie::lirePaliers('1000, 1500.5'), 'pas de décimale');
        self::assertSame([1500], ReglagesSaisie::lirePaliers('1.500'), 'point des milliers');
        self::assertNull(ReglagesSaisie::lirePaliers('150000'), 'au-delà du maximum');
        self::assertNull(ReglagesSaisie::lirePaliers('1, 2, 3, 4, 5, 6, 7, 8, 9'), 'trop de paliers');
    }

    public function testChoixDuFormulaire(): void
    {
        self::assertSame(
            ['Sans frais' => 0, "1\u{a0}000\u{a0}F" => 1000, "2\u{a0}500\u{a0}F" => 2500],
            ReglagesSaisie::choixFrais([1000, 2500]),
        );
        // Un montant enregistré avant un changement de réglage reste proposé.
        self::assertSame([0, 1000, 1200, 2500], array_values(ReglagesSaisie::choixFrais([1000, 2500], 1200)));
        self::assertSame(['Sans frais' => 0], ReglagesSaisie::choixFrais([]));
    }

    public function testSeulsZeroEtLesPaliersSontPermis(): void
    {
        $paliers = [1000, 1500, 2000, 2500];
        self::assertTrue(ReglagesSaisie::fraisPermis(0, $paliers));
        self::assertTrue(ReglagesSaisie::fraisPermis(2500, $paliers));
        self::assertFalse(ReglagesSaisie::fraisPermis(3000, $paliers), 'au-dessus du maximum');
        self::assertFalse(ReglagesSaisie::fraisPermis(1200, $paliers));
        self::assertTrue(ReglagesSaisie::fraisPermis(1200, $paliers, 1200), 'montant déjà enregistré');
    }

    public function testObjetsSuggeres(): void
    {
        self::assertSame(
            ['Impressions', 'Prestations informatiques', 'Conception graphique', 'Signalétique', 'Textile personnalisé'],
            ReglagesSaisie::lireObjets(ReglagesSaisie::OBJETS_DEFAUT),
        );
        self::assertSame(['Impressions', 'Pose'], ReglagesSaisie::lireObjets("  Impressions ,impressions\n\nPose ; "));
        self::assertCount(ReglagesSaisie::OBJETS_NOMBRE_MAX, ReglagesSaisie::lireObjets(implode(',', range(1, 30))));
    }

    public function testMontantAffiche(): void
    {
        self::assertSame("1\u{a0}500\u{a0}F", ReglagesSaisie::montant(1500));
        self::assertSame("500\u{a0}F", ReglagesSaisie::montant(500));
    }

    public function testTuileDirectionSeulement(): void
    {
        $tuile = CatalogueParametres::tuiles()[ReglagesSaisie::TUILE];
        self::assertSame(['ROLE_ADMIN'], $tuile['roles']);
        self::assertSame('ventes', $tuile['famille']);
    }

    public function testSaisieDesPaliersControleeEtRangee(): void
    {
        $champs = CatalogueParametres::tuiles()[ReglagesSaisie::TUILE]['champs'];

        $lecture = CatalogueParametres::lireSaisie($champs, ['paliers_livraison' => '2 000 ; 1000 F, 1500', 'livraison_en_ligne' => '2 000', 'objets' => 'Impressions, Pose']);
        self::assertSame([], $lecture['erreurs']);
        self::assertSame('1000, 1500, 2000', $lecture['valeurs']['paliers_livraison']);
        self::assertSame(2000, $lecture['valeurs']['livraison_en_ligne']);
        self::assertSame('Impressions, Pose', $lecture['valeurs']['objets']);

        $refus = CatalogueParametres::lireSaisie($champs, ['paliers_livraison' => '1000, gratuit', 'livraison_en_ligne' => '0', 'objets' => str_repeat('a', 501)]);
        self::assertSame(['paliers_livraison', 'objets'], array_keys($refus['erreurs']));
        self::assertSame('500 caractères au plus.', $refus['erreurs']['objets']);
    }

    public function testFraisDeLivraisonEnLigneParmiLesPaliers(): void
    {
        $champs = CatalogueParametres::tuiles()[ReglagesSaisie::TUILE]['champs'];

        // Ni 0 ni un palier : refusé avec la liste, la valeur n'est pas gardée.
        $refus = CatalogueParametres::lireSaisie($champs, ['paliers_livraison' => '1000, 2000', 'livraison_en_ligne' => '1800', 'objets' => '']);
        self::assertSame(['livraison_en_ligne'], array_keys($refus['erreurs']));
        self::assertStringStartsWith('0 ou un des paliers : 1', $refus['erreurs']['livraison_en_ligne']);
        self::assertArrayNotHasKey('livraison_en_ligne', $refus['valeurs']);
        // Sans palier : seulement 0 (livraison offerte).
        self::assertSame('Sans palier, seulement 0.', CatalogueParametres::lireSaisie($champs, ['paliers_livraison' => '', 'livraison_en_ligne' => '1000', 'objets' => ''])['erreurs']['livraison_en_ligne']);
        self::assertSame(0, CatalogueParametres::lireSaisie($champs, ['paliers_livraison' => '', 'livraison_en_ligne' => '0', 'objets' => ''])['valeurs']['livraison_en_ligne']);

        // Le site propose le réglage s'il est permis ; sinon le palier le plus proche (le plus petit à égalité).
        self::assertSame(2000, ReglagesSaisie::fraisEnLigneParmi(2000, [1000, 1500, 2000]));
        self::assertSame(0, ReglagesSaisie::fraisEnLigneParmi(0, [1000, 1500]));
        self::assertSame(1500, ReglagesSaisie::fraisEnLigneParmi(1700, [1000, 1500, 2500]));
        self::assertSame(1000, ReglagesSaisie::fraisEnLigneParmi(1500, [1000, 2000]));
        self::assertSame(0, ReglagesSaisie::fraisEnLigneParmi(2000, []), 'sans palier : pas de frais');
    }
}
