<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Twig\PiecesM2Extension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pièces au m² sur une ligne de devis ou de commande
 * (docs/saisie-devis-commandes.md) : 1 000 autocollants de 5 × 5 cm en
 * vinyle à 6 500 F le m². Calcul habituel : 1 000 × 0,0025 = 2,5 m² ;
 * en pièces au m² : 361 pièces au m², 1 000 ÷ 361 → 2,78 m² facturés.
 */
final class PiecesAuM2Test extends TestCase
{
    /** @return iterable<string, array{class-string<CommandesDetails|DevisDetails>}> */
    public static function lignes(): iterable
    {
        yield 'commande' => [CommandesDetails::class];
        yield 'devis' => [DevisDetails::class];
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    private static function autocollants(string $classe, int $quantite = 1000): CommandesDetails|DevisDetails
    {
        return (new $classe())->setDesignation('Autocollants')->setModeCalcul('metre_carre')
            ->setLargeur(0.05)->setLongueur(0.05)->setQuantite($quantite)->setPrixUnitaire(6500);
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testSansLaCaseLeCalculAuMetreCarreResteLeMeme(string $classe): void
    {
        $ligne = self::autocollants($classe)->calculerTotaux();

        self::assertNull($ligne->getEspacementPiecesM2());
        self::assertFalse($ligne->isEnPiecesAuM2());
        self::assertSame(16250, $ligne->getTotalHt(), '2,5 m² × 6 500');
        self::assertEqualsWithDelta(2.5, $ligne->getSurfaceTotale(), 0.0001);
        self::assertSame('', PiecesM2Extension::ligne($ligne));
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testCaseCocheeLaSurfaceFactureeVientDesPiecesAuM2(string $classe): void
    {
        $ligne = self::autocollants($classe)->choisirPiecesAuM2(true, 1.0)->calculerTotaux();

        self::assertSame(1.0, $ligne->getEspacementPiecesM2());
        self::assertSame(361, $ligne->getPiecesAuM2()['parM2'] ?? null);
        self::assertSame(2.78, $ligne->getPiecesAuM2()['surface'] ?? null);
        self::assertSame(18070, $ligne->getTotalHt(), '2,78 m² × 6 500');
        self::assertSame(2.78, $ligne->getSurfaceTotale());
        self::assertSame(1000, $ligne->getQuantite(), 'la quantité reste le nombre de pièces');
        self::assertSame("361 pièces au m² (19 × 19, espacement 1 mm) · 2,78 m² facturés", PiecesM2Extension::ligne($ligne));
        self::assertSame('361 pièces au m²', PiecesM2Extension::ligne($ligne, true));
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testQuantiteDonneeEnMetresCarres(string $classe): void
    {
        // 3 m² demandés : 1 083 pièces (plancher de 3 × 361), qui font juste 3 m².
        $ligne = self::autocollants($classe, 1083)->choisirPiecesAuM2(true, 1.0)->calculerTotaux();

        self::assertSame(3.0, $ligne->getPiecesAuM2()['surface'] ?? null);
        self::assertSame(19500, $ligne->getTotalHt());
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testLaRemiseParMetreCarreSuitLaSurfaceFacturee(string $classe): void
    {
        // Remise de 500 F par m² facturé : 2,78 × 500 = 1 390.
        $ligne = self::autocollants($classe)->setRemise(500)->choisirPiecesAuM2(true, 1.0)->calculerTotaux();

        self::assertSame(18070 - 1390, $ligne->getTotalHt());
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testLEspacementChoisiResteSurLaLigne(string $classe): void
    {
        $ligne = self::autocollants($classe)->choisirPiecesAuM2(true, 1.0)->calculerTotaux();

        // Le réglage passe à 3 mm : la ligne déjà en pièces au m² garde 1 mm, et son total.
        $ligne->choisirPiecesAuM2(true, 3.0)->calculerTotaux();
        self::assertSame(1.0, $ligne->getEspacementPiecesM2());
        self::assertSame(18070, $ligne->getTotalHt());

        // Décochée puis recochée : le nouveau réglage s'applique (324 pièces au m² → 3,09 m²).
        $ligne->choisirPiecesAuM2(false, 3.0)->calculerTotaux();
        self::assertNull($ligne->getEspacementPiecesM2());
        self::assertSame(16250, $ligne->getTotalHt());
        $ligne->choisirPiecesAuM2(true, 3.0)->calculerTotaux();
        self::assertSame(3.0, $ligne->getEspacementPiecesM2());
        self::assertSame(324, $ligne->getPiecesAuM2()['parM2'] ?? null);
        self::assertSame(20085, $ligne->getTotalHt(), '3,09 m² × 6 500');
    }

    /** @return iterable<string, array{\Closure(CommandesDetails|DevisDetails): mixed}> */
    public static function lignesSansPiecesAuM2(): iterable
    {
        yield 'à l\'unité' => [static fn (CommandesDetails|DevisDetails $l) => $l->setModeCalcul('unite')];
        yield 'au mètre' => [static fn (CommandesDetails|DevisDetails $l) => $l->setModeCalcul('metre')];
        yield 'ligne article' => [static fn (CommandesDetails|DevisDetails $l) => $l->setTypeLigne('article')];
        yield 'pièce de 1,20 m' => [static fn (CommandesDetails|DevisDetails $l) => $l->setLargeur(1.2)];
        yield 'sans longueur' => [static fn (CommandesDetails|DevisDetails $l) => $l->setLongueur(null)];
    }

    /**
     * @param \Closure(CommandesDetails|DevisDetails): mixed $preparer
     */
    #[DataProvider('lignesSansPiecesAuM2')]
    public function testLaCaseNeSAppliqueQuAUnProduitAuMetreCarreQuiTientSurUnMetre(\Closure $preparer): void
    {
        foreach ([new CommandesDetails(), new DevisDetails()] as $vide) {
            $ligne = self::autocollants($vide::class);
            $preparer($ligne);

            $ligne->choisirPiecesAuM2(true, 1.0);

            self::assertNull($ligne->getEspacementPiecesM2(), $vide::class);
            self::assertFalse($ligne->isEnPiecesAuM2());
        }
    }

    public function testUneLigneQuiChangeDeModeRevientAuCalculHabituel(): void
    {
        // Espacement gardé en base, mais la ligne est passée à l'unité : il ne compte plus.
        $ligne = self::autocollants(CommandesDetails::class)->choisirPiecesAuM2(true, 1.0);
        $ligne->setModeCalcul('unite')->calculerTotaux();

        self::assertFalse($ligne->isEnPiecesAuM2());
        self::assertSame(6500 * 1000, $ligne->getTotalHt());
    }

    public function testEspacementEnregistreAuDixieme(): void
    {
        $ligne = (new CommandesDetails())->setEspacementPiecesM2('1,25');
        self::assertSame(1.3, $ligne->getEspacementPiecesM2());
        self::assertNull($ligne->setEspacementPiecesM2('')->getEspacementPiecesM2());
        self::assertSame(20.0, $ligne->setEspacementPiecesM2(99)->getEspacementPiecesM2(), 'au plus 20 mm');
    }

    /**
     * Total figé (TotalFigeTrait) : réenregistrée sans changement, la ligne
     * garde son total ; cocher la case change le calcul, elle est recalculée.
     *
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testUneLigneAuTotalFigeEstRecalculeeQuandOnCocheLaCase(string $classe): void
    {
        $ligne = self::autocollants($classe)->setTotalHt(16000)->setTotalTtc(16000);
        (new \ReflectionProperty($ligne, 'totalFige'))->setValue($ligne, true);
        $ligne->memoriserCalculFige();

        $ligne->choisirPiecesAuM2(false, 1.0)->calculerTotaux();
        self::assertSame(16000, $ligne->getTotalHt(), 'case laissée décochée : rien ne change');
        self::assertTrue($ligne->isTotalFige());

        $ligne->choisirPiecesAuM2(true, 1.0)->calculerTotaux();
        self::assertSame(18070, $ligne->getTotalHt());
        self::assertFalse($ligne->isTotalFige());
    }

    /**
     * @param class-string<CommandesDetails|DevisDetails> $classe
     */
    #[DataProvider('lignes')]
    public function testUneLigneFigeeEnPiecesAuM2LeResteSansChangement(string $classe): void
    {
        $ligne = self::autocollants($classe)->setEspacementPiecesM2(1)->setTotalHt(18000)->setTotalTtc(18000);
        (new \ReflectionProperty($ligne, 'totalFige'))->setValue($ligne, true);
        $ligne->memoriserCalculFige();

        // Le formulaire renvoie la case cochée et les mêmes dimensions.
        $ligne->setLargeur(0.05)->setLongueur(0.05)->choisirPiecesAuM2(true, 2.0)->calculerTotaux();

        self::assertSame(18000, $ligne->getTotalHt());
        self::assertTrue($ligne->isTotalFige());
    }
}
