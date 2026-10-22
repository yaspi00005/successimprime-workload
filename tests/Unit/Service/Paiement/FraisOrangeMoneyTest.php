<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Paiements;
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

    /** Paiement en attente tel que relu en base, frais déjà posés. */
    private static function paiementEnAttente(string $mode, int $montant, bool $fonds, bool $retrait, int $montantFonds, int $montantRetrait): Paiements
    {
        return (new Paiements())->setMode($mode)->setMontant($montant)
            ->setFondsSoutienInclus($fonds)->setFraisRetraitInclus($retrait)
            ->setMontantFondsSoutien($montantFonds)->setMontantFraisRetrait($montantRetrait);
    }

    /** @return array{montant: int, mode: string, fondsSoutienInclus: bool, fraisRetraitInclus: bool} */
    private static function avant(Paiements $p): array
    {
        return ['montant' => $p->getMontant(), 'mode' => $p->getMode(), 'fondsSoutienInclus' => $p->isFondsSoutienInclus(), 'fraisRetraitInclus' => $p->isFraisRetraitInclus()];
    }

    public function testCaisseFraisSeulementPourLesCasesCochees(): void
    {
        $p = (new Paiements())->setMode(Paiements::MODE_WAVE)->setMontant(20000)->setFraisRetraitInclus(true);

        FraisOrangeMoney::appliquer($p, new ParametresPaiement());

        self::assertSame([0, 200], [$p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()]);
        self::assertSame(20000, $p->getMontant(), 'les frais ne vont jamais dans le montant');
    }

    public function testModificationCocherLaCaseCalculeLesFrais(): void
    {
        $p = self::paiementEnAttente(Paiements::MODE_ORANGE_MONEY, 25000, false, false, 0, 0);
        $avant = self::avant($p);

        $p->setFondsSoutienInclus(true)->setFraisRetraitInclus(true);
        FraisOrangeMoney::appliquer($p, new ParametresPaiement(), $avant);

        self::assertSame([250, 250], [$p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()]);
    }

    public function testModificationDecocherLaCaseRetireLesFrais(): void
    {
        $p = self::paiementEnAttente(Paiements::MODE_ORANGE_MONEY, 25000, true, true, 250, 250);
        $avant = self::avant($p);

        $p->setFraisRetraitInclus(false);
        FraisOrangeMoney::appliquer($p, new ParametresPaiement(), $avant);

        self::assertSame([250, 0], [$p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()]);
    }

    public function testModificationAutreModeVideLesCases(): void
    {
        foreach ([Paiements::MODE_ESPECES, Paiements::MODE_VIREMENT, Paiements::MODE_CARTE] as $mode) {
            $p = self::paiementEnAttente(Paiements::MODE_ORANGE_MONEY, 25000, true, true, 250, 250);
            $avant = self::avant($p);

            $p->setMode($mode);
            FraisOrangeMoney::appliquer($p, new ParametresPaiement(), $avant);

            self::assertSame([false, false, 0, 0], [$p->isFondsSoutienInclus(), $p->isFraisRetraitInclus(), $p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()], $mode);
        }
    }

    public function testModificationSansChangementGardeLesFraisEnregistres(): void
    {
        // Frais d'un paiement en ligne, pris aux taux du jour du paiement (1,5 % depuis changé en 1 %).
        $p = self::paiementEnAttente(Paiements::MODE_ORANGE_MONEY, 20000, true, true, 300, 200);

        FraisOrangeMoney::appliquer($p, new ParametresPaiement(), self::avant($p));

        self::assertSame([300, 200], [$p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()]);
    }

    public function testModificationDuMontantRecalculeLesFrais(): void
    {
        $p = self::paiementEnAttente(Paiements::MODE_ORANGE_MONEY, 20000, true, true, 300, 200);
        $avant = self::avant($p);

        $p->setMontant(30000);
        FraisOrangeMoney::appliquer($p, new ParametresPaiement(), $avant);

        self::assertSame([300, 300], [$p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()]);
    }

    public function testCaseCocheeSansFraisEnregistresLesCalcule(): void
    {
        // Case enregistrée cochée mais sans montant (ancienne modification) : on calcule.
        $p = self::paiementEnAttente(Paiements::MODE_WAVE, 10000, true, false, 0, 0);

        FraisOrangeMoney::appliquer($p, new ParametresPaiement(), self::avant($p));

        self::assertSame([100, 0], [$p->getMontantFondsSoutien(), $p->getMontantFraisRetrait()]);
    }
}
