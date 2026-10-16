<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Entity\DevisDetailFinition;
use App\Entity\DevisDetails;
use App\Entity\Finition;
use App\Service\Bascule\ConversionsDonnees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * « Garder le total » (décision de Yaya) : une ligne dont la remise a été
 * convertie par la bascule et dont le nouveau calcul ne redonne pas
 * exactement le total enregistré garde ce total tant que rien de ce qui
 * le fait ne change ; sinon elle est recalculée et redevient ordinaire.
 *
 * L'exemple de la bascule : 1 000 flyers à 25 F, 12,5 % de remise dans
 * l'ancienne version (total 21 875 F) ; la remise devient 3 F par flyer
 * (3,125 arrondi), et le nouveau calcul donnerait 22 000 F.
 */
final class TotalFigeTest extends TestCase
{
    /** Ligne telle que lue en base après la bascule (marquée total_fige). */
    private static function lue(CommandesDetails|DevisDetails $ligne): CommandesDetails|DevisDetails
    {
        (new \ReflectionProperty($ligne, 'totalFige'))->setValue($ligne, true);
        $ligne->memoriserCalculFige(); // #[ORM\PostLoad]

        return $ligne;
    }

    private static function flyers(CommandesDetails|DevisDetails $ligne): CommandesDetails|DevisDetails
    {
        $ligne->setDesignation('Flyers A5')->setModeCalcul('exemplaire')->setQuantite(1000)->setPrixUnitaire('25.0000')
            ->setRemise(3)->setTva(18)->setTotalHt(21875)->setTotalTtc(25813);

        return self::lue($ligne);
    }

    private static function finition(string $classe, int $id, int $prix, int $quantite, int $montant): CommandeDetailFinition|DevisDetailFinition
    {
        $finition = new Finition();
        (new \ReflectionProperty(Finition::class, 'id'))->setValue($finition, $id);

        return (new $classe())->setFinition($finition)->setNomFinition('Œillets')->setPrixApplique($prix)->setModeCalcul('unite')->setQuantite($quantite)->setMontant($montant);
    }

    /** @return iterable<string, array{class-string}> */
    public static function lignes(): iterable
    {
        yield 'commande' => [CommandesDetails::class];
        yield 'devis' => [DevisDetails::class];
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testReenregistreeSansChangementGardeSonTotal(string $classe): void
    {
        $ligne = self::flyers(new $classe());

        // Le formulaire renvoie les mêmes valeurs, écrites autrement, et le total de son aperçu.
        $ligne->setQuantite(1000)->setPrixUnitaire('25')->setRemise('3')->setTva(18)->setTotalHt(22000)->setTotalTtc(25960);
        $ligne->calculerTotaux(true);
        $ligne->calculerTotaux(true); // promotions, totaux du document : encore une fois

        self::assertSame([21875, 25813], [$ligne->getTotalHt(), $ligne->getTotalTtc()]);
        self::assertTrue($ligne->isTotalFige());
        // Le document montre ce qui a vraiment été retiré : 25 000 − 21 875.
        self::assertSame(3125, $ligne->getMontantRemise());
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testModifieeEstRecalculeeEtRedevientOrdinaire(string $classe): void
    {
        $ligne = self::flyers(new $classe());

        $ligne->setQuantite(1200);
        $ligne->calculerTotaux(true);

        // 1 200 × 25 − 3 × 1 200 = 26 400, TVA 18 %.
        self::assertSame([26400, 31152], [$ligne->getTotalHt(), $ligne->getTotalTtc()]);
        self::assertFalse($ligne->isTotalFige());
        self::assertSame(3600, $ligne->getMontantRemise());

        // Revenir à 1 000 ne refige pas : c'est une ligne ordinaire.
        $ligne->setQuantite(1000);
        $ligne->calculerTotaux(true);
        self::assertSame(22000, $ligne->getTotalHt());
        self::assertFalse($ligne->isTotalFige());
    }

    /** @return iterable<string, array{\Closure(CommandesDetails): mixed, int}> */
    public static function changements(): iterable
    {
        yield 'prix' => [static fn (CommandesDetails $l) => $l->setPrixUnitaire(30), 27000];
        yield 'remise' => [static fn (CommandesDetails $l) => $l->setRemise(4), 21000];
        yield 'remise de promotion' => [static fn (CommandesDetails $l) => (new \ReflectionProperty($l, 'remisePromotion'))->setValue($l, 1), 21000];
        yield 'mode de calcul' => [static fn (CommandesDetails $l) => $l->setModeCalcul('forfait'), 22];
        yield 'finition ajoutée' => [static fn (CommandesDetails $l) => $l->addFinition(self::finition(CommandeDetailFinition::class, 7, 50, 10, 500)), 22500];
    }

    /**
     * @param \Closure(CommandesDetails): mixed $changer
     */
    #[DataProvider('changements')]
    public function testChaqueChangementDuCalculRecalcule(\Closure $changer, int $totalHt): void
    {
        $ligne = self::flyers(new CommandesDetails());

        $changer($ligne);
        $ligne->calculerTotaux(true);

        self::assertSame($totalHt, $ligne->getTotalHt());
        self::assertFalse($ligne->isTotalFige());
    }

    public function testLigneAuMetreCarre(): void
    {
        // Bâche 3 m × 1,5 m à 3 000 F le m², 10 % : 13 500 − 1 350 = 12 150 ; remise 300 F/m² → 12 150 aussi
        // à la nouvelle version, mais le total a été retouché à 12 100 dans l'ancienne : figée.
        $ligne = (new CommandesDetails())->setDesignation('Bâche')->setModeCalcul('metre_carre')->setLargeur('3.0000')->setLongueur('1.5000')
            ->setSurface('4.5000')->setQuantite(1)->setPrixUnitaire(3000)->setRemise(300)->setTotalHt(12100)->setTotalTtc(12100);
        $ligne->addFinition(self::finition(CommandeDetailFinition::class, 3, 100, 8, 800));
        self::lue($ligne);

        // Mêmes dimensions renvoyées par le formulaire (centimètres → mètres), même finition.
        $ligne->setLargeur(3)->setLongueur(1.5);
        $ligne->calculerTotaux(true);
        self::assertSame(12100, $ligne->getTotalHt());
        self::assertTrue($ligne->isTotalFige());

        // Finition dont le montant change : recalculée (3 000 × 4,5 + 900 − 300 × 4,5).
        $ligne->getFinitions()->first()->setQuantite(9)->setMontant(900);
        $ligne->calculerTotaux(true);
        self::assertSame(13050, $ligne->getTotalHt());
        self::assertFalse($ligne->isTotalFige());
    }

    public function testDimensionChangeeAuMetreCarre(): void
    {
        $ligne = (new DevisDetails())->setDesignation('Bâche')->setModeCalcul('metre_carre')->setLargeur('3.0000')->setLongueur('1.5000')
            ->setSurface('4.5000')->setQuantite(1)->setPrixUnitaire(3000)->setRemise(300)->setTotalHt(12100)->setTotalTtc(12100);
        self::lue($ligne);

        $ligne->setLargeur(2);
        $ligne->calculerTotaux();

        // 2 × 1,5 = 3 m² : 9 000 − 900.
        self::assertSame(8100, $ligne->getTotalHt());
        self::assertFalse($ligne->isTotalFige());
    }

    public function testUneLigneOrdinaireNEstJamaisFigee(): void
    {
        $ligne = (new CommandesDetails())->setDesignation('Flyers A5')->setModeCalcul('exemplaire')->setQuantite(1000)->setPrixUnitaire(25)
            ->setRemise(3)->setTotalHt(21875);
        $ligne->memoriserCalculFige();

        $ligne->calculerTotaux(true);

        self::assertSame(22000, $ligne->getTotalHt());
        self::assertFalse($ligne->isTotalFige());
    }

    public function testLaBasculeEtLaMigrationCreentLaMemeColonne(): void
    {
        $migration = (string) file_get_contents(\dirname(__DIR__, 3).'/migrations/Version20261015231500.php');

        self::assertStringContainsString('ADD total_fige '.ConversionsDonnees::DEFINITION_TOTAL_FIGE, $migration);
        self::assertSame('total_fige', ConversionsDonnees::COLONNE_TOTAL_FIGE);
        // La migration ne recrée pas une colonne que la bascule a déjà ajoutée et remplie.
        self::assertStringContainsString("hasColumn('total_fige')", $migration);
    }
}
