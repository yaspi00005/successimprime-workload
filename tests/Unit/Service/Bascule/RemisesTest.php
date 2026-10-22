<?php

namespace App\Tests\Unit\Service\Bascule;

use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Entity\DevisDetailFinition;
use App\Entity\DevisDetails;
use App\Service\Bascule\LigneRemise;
use App\Service\Bascule\Remises;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RemisesTest extends TestCase
{
    /**
     * Lignes d'essai : [mode, quantité, surface, longueur, faces, prix, finitions].
     *
     * @return iterable<string, array{string, int, ?string, ?string, int, string, list<int>}>
     */
    public static function lignes(): iterable
    {
        yield 'flyers' => ['exemplaire', 1000, null, null, 1, '25.0000', []];
        yield 'bâche au m²' => ['metre_carre', 3, '3.0000', null, 1, '4500.0000', [2000]];
        yield 'petite surface' => ['metre_carre', 500, '0.0630', null, 1, '2000.0000', []];
        yield 'au mètre' => ['metre', 2, null, '12.7500', 1, '1200.0000', []];
        yield 'recto verso' => ['face', 50, null, null, 2, '150.0000', [1500, 1000]];
        yield 'forfait' => ['forfait', 1, null, null, 1, '15000.0000', [3000]];
        yield 'prix décimal' => ['unite', 7, null, null, 1, '3499.5000', []];
        yield 'surface vide' => ['metre_carre', 1, null, null, 1, '4500.0000', [5000]];
    }

    /**
     * Le calcul repris par la bascule est celui des entités de la nouvelle
     * version, pour chaque remise par unité essayée.
     *
     * @param list<int> $finitions
     */
    #[DataProvider('lignes')]
    public function testMemeCalculQueLesEntites(string $mode, int $quantite, ?string $surface, ?string $longueur, int $faces, string $prix, array $finitions): void
    {
        $facteur = Remises::facteur($mode, $quantite, $surface, $longueur, $faces);
        $brut = Remises::brut($prix, $facteur, array_sum($finitions));
        foreach ([0, 1, 3, 22, 400, 3500, 99999] as $remise) {
            foreach ([new CommandesDetails(), new DevisDetails()] as $ligne) {
                $ligne->setModeCalcul($mode)->setQuantite($quantite)->setSurface($surface)->setLongueur($longueur)->setNombreFaces($faces)->setPrixUnitaire($prix)->setRemise($remise);
                foreach ($finitions as $montant) {
                    $ligne->addFinition(($ligne instanceof CommandesDetails ? new CommandeDetailFinition() : new DevisDetailFinition())->setMontant($montant));
                }
                $brutEntite = $ligne->calculerMontantImpression() + array_sum($finitions);
                self::assertSame($brutEntite, $brut, $mode);
                self::assertSame($brutEntite - $ligne->getMontantRemise(), Remises::totalNouveau($brut, $remise, $facteur), $mode.' remise '.$remise);
            }
        }
    }

    public function testAncienCalculEnPourcentage(): void
    {
        self::assertSame(21875, Remises::totalAncien(25000, 12.5));
        self::assertSame(12001, Remises::totalAncien(18000, 33.33)); // remise 5 999,4 arrondie à 5 999
        self::assertSame(0, Remises::totalAncien(24500, 100));
        self::assertSame(25000, Remises::totalAncien(25000, 0));
        self::assertSame(1, Remises::totalAncien(3, 50)); // 1,5 arrondi à 2 (comme PHP round)
    }

    public function testMontantDeLaRemise(): void
    {
        // Ligne cohérente : brut − total, au franc près.
        self::assertSame(3125, Remises::montant(12.5, 21875, 25000));
        self::assertSame(2, Remises::montant(50, 1, 3));
        // 100 % : tout le brut.
        self::assertSame(24500, Remises::montant(100, 0, 24500));
        // Brut recalculé qui ne redonne pas le total : formule de l'ancienne mise à jour depuis le total enregistré.
        self::assertSame(1056, Remises::montant(10, 9500, 10000)); // round(9500 × 10 / 90)
    }

    public function testRemiseParUnite(): void
    {
        self::assertSame(3, Remises::parUnite(3125, 25000, 1000.0));   // 3,125 F par exemplaire
        self::assertSame(400, Remises::parUnite(10199, 30600, 25.5));  // 399,96 F par mètre
        self::assertSame(200, Remises::parUnite(6300, 63000, 31.5));   // m² exact
        self::assertSame(5999, Remises::parUnite(5999, 18000, 1.0));   // forfait
        // Ligne offerte : arrondi au franc supérieur, le plafond redonne 0.
        self::assertSame(3500, Remises::parUnite(24500, 24500, 7.0));
        self::assertSame(3334, Remises::parUnite(1000, 1000, 0.3));
        self::assertSame(0, Remises::totalNouveau(1000, 3334, 0.3));
        // Rien à retirer, ou aucune unité facturable.
        self::assertSame(0, Remises::parUnite(0, 25000, 1000.0));
        self::assertSame(0, Remises::parUnite(500, 5000, 0.0));
    }

    public function testVerdict(): void
    {
        self::assertSame(Remises::IDENTIQUE, Remises::verdict(21875, 21875, 1000.0, true));
        self::assertSame(Remises::ARRONDI, Remises::verdict(21875, 22000, 1000.0, true));   // 125 F sur 1 000 exemplaires
        self::assertSame(Remises::ARRONDI, Remises::verdict(10, 11, 0.25, true));          // au moins 1 F
        self::assertSame(Remises::ECART, Remises::verdict(10, 12, 0.25, true));
        self::assertSame(Remises::ECART, Remises::verdict(4050, 4500, 0.0, true));         // surface vide : la remise ne s'exprime pas par unité
        self::assertSame(Remises::DEJA_DIFFERENTE, Remises::verdict(9500, 8940, 10.0, false));
    }

    /**
     * Les cinq pourcentages de la répétition : chaque ligne cohérente retombe
     * sur son total enregistré, exactement ou à 1 F près par unité.
     */
    public function testConversionDesLignesCoherentes(): void
    {
        $cas = [
            ['10.00', 'metre_carre', 3, '3.0000', null, 1, '4500', 2000],
            ['12.50', 'exemplaire', 1000, null, null, 1, '25', 0],
            ['33.33', 'forfait', 1, null, null, 1, '15000', 3000],
            ['100.00', 'unite', 7, null, null, 1, '3500', 0],
            ['33.33', 'metre', 2, null, '12.7500', 1, '1200', 0],
            ['12.50', 'face', 50, null, null, 2, '150', 2500],
            ['10.00', 'metre_carre', 500, '0.0630', null, 1, '2000', 0],
            ['14.2857', 'metre_carre', 1, '1.1645', null, 1, '7000', 0],
            ['33.3333', 'forfait', 1, null, null, 1, '45000', 5000],
        ];
        foreach ($cas as [$p, $mode, $q, $surface, $longueur, $faces, $prix, $finitions]) {
            $facteur = Remises::facteur($mode, $q, $surface, $longueur, $faces);
            $total = Remises::totalAncien(Remises::brut($prix, $facteur, $finitions), (float) $p);
            $ligne = new LigneRemise(1, $p, $total, $prix, $q, $surface, $longueur, $faces, $mode, $finitions);
            self::assertTrue($ligne->suitLePourcentage(), $p.' '.$mode);
            $verdict = $ligne->verdict($ligne->remiseParUnite(), true);
            self::assertContains($verdict, [Remises::IDENTIQUE, Remises::ARRONDI], $p.' '.$mode);
        }
    }
}
