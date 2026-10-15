<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\ParametresPaiement;
use App\Service\Paiement\FraisOrangeMoney;
use App\Service\Paiement\OrangeMoney;
use PHPUnit\Framework\TestCase;

/**
 * Frais Orange Money payés par le client en plus du montant : fonds de
 * soutien et frais de retrait, aux taux de Réglages › Paiements, arrondis
 * au franc.
 */
final class FraisOrangeMoneyTest extends TestCase
{
    public function testUnPourCentEtUnPourCentParDefaut(): void
    {
        $frais = FraisOrangeMoney::calculer(25000, new ParametresPaiement());

        self::assertSame([25000, 250, 250, 500, 25500], [$frais->montant, $frais->fondsSoutien, $frais->fraisRetrait, $frais->frais(), $frais->total()]);
        self::assertSame([1.0, 1.0], [$frais->tauxFondsSoutien, $frais->tauxFraisRetrait]);
    }

    public function testArrondiAuFranc(): void
    {
        $taux = (new ParametresPaiement())->setTauxFondsSoutien(1.5)->setTauxFraisRetrait(1);

        // 12 345 × 1,5 % = 185,175 → 185 ; 12 345 × 1 % = 123,45 → 123.
        $frais = FraisOrangeMoney::calculer(12345, $taux);
        self::assertSame([185, 123, 12653], [$frais->fondsSoutien, $frais->fraisRetrait, $frais->total()]);

        // 50 × 1 % = 0,5 → 1 (au franc le plus proche).
        self::assertSame(52, FraisOrangeMoney::calculer(50, new ParametresPaiement())->total());
    }

    public function testTauxAZeroSansLigne(): void
    {
        $frais = FraisOrangeMoney::calculer(10000, (new ParametresPaiement())->setTauxFondsSoutien(0));

        self::assertSame([0, 100, 10100], [$frais->fondsSoutien, $frais->fraisRetrait, $frais->total()]);
        self::assertSame(10000, FraisOrangeMoney::calculer(10000, (new ParametresPaiement())->setTauxFondsSoutien(0)->setTauxFraisRetrait(0))->total());
    }

    public function testTauxAffiches(): void
    {
        self::assertSame('1 %', FraisOrangeMoney::taux(1.0));
        self::assertSame('1,5 %', FraisOrangeMoney::taux(1.5));
        self::assertSame('0,25 %', FraisOrangeMoney::taux(0.25));
        self::assertSame('10 %', FraisOrangeMoney::taux(10.0));
    }

    public function testSommeRecueDecomposee(): void
    {
        $taux = new ParametresPaiement();

        // Relevé du compte marchand : 25 500 F = 25 000 F + 250 + 250.
        $frais = FraisOrangeMoney::decomposer(25500, $taux);
        self::assertNotNull($frais);
        self::assertSame([25000, 250, 250], [$frais->montant, $frais->fondsSoutien, $frais->fraisRetrait]);

        // Chaque total possible retrouve son montant.
        foreach ([1000, 4999, 12345, 87650, 1000000] as $montant) {
            self::assertSame($montant, FraisOrangeMoney::decomposer(FraisOrangeMoney::calculer($montant, $taux)->total(), $taux)?->montant, (string) $montant);
        }
    }

    public function testTotalVuDansLeRecapitulatif(): void
    {
        $frais = FraisOrangeMoney::calculer(25000, new ParametresPaiement());

        self::assertFalse(OrangeMoney::totalChange('25500', $frais));
        self::assertFalse(OrangeMoney::totalChange('', $frais), 'sans total (sans JavaScript) : le serveur décide seul');
        self::assertTrue(OrangeMoney::totalChange('25000', $frais), 'taux changés ou paiement reçu entre-temps : le client revoit le récapitulatif');
    }

    public function testTauxDeLaTuile(): void
    {
        $p = (new ParametresPaiement())->changerTaux('tauxFondsSoutien', 0.5)->changerTaux('tauxFraisRetrait', 2);

        self::assertSame([0.5, 2.0], [$p->taux('tauxFondsSoutien'), $p->taux('tauxFraisRetrait')]);
        $this->expectException(\InvalidArgumentException::class);
        $p->taux('montant');
    }
}
