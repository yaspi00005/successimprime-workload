<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\ParametresPaiement;
use App\Service\Paiement\RepartitionPaiement;
use PHPUnit\Framework\TestCase;

/** Répartition d'un paiement de plusieurs factures et acomptes (maquette FP03). */
final class RepartitionPaiementTest extends TestCase
{
    /** Facture échue, acompte, facture à venir : la plus ancienne échéance d'abord. */
    private const DUS = ['roll-up' => 62500, 'cartes' => 12500, 't-shirts' => 64000];

    public function testAvoirPuisOrangeMoneyALaPlusAncienne(): void
    {
        $plan = RepartitionPaiement::planifier(self::DUS, 5000, true, null, new ParametresPaiement());

        self::assertSame([139000, 5000, 134000, 134000], [$plan['total'], $plan['avoir'], $plan['omMax'], $plan['om']]);
        self::assertSame(['om' => 57500, 'avoir' => 5000], array_intersect_key($plan['parts']['roll-up'], ['om' => 0, 'avoir' => 0]));
        self::assertSame(12500, $plan['parts']['cartes']['om']);
        self::assertSame(64000, $plan['parts']['t-shirts']['om']);
        self::assertSame(0, $plan['parts']['t-shirts']['avoir']);
    }

    public function testFraisCalculesUneFoisSurLeTotalPuisPartages(): void
    {
        $plan = RepartitionPaiement::planifier(self::DUS, 5000, true, null, new ParametresPaiement());

        // 1 % + 1 % sur 134 000 F payés chez Orange : un seul passage, un seul calcul.
        self::assertSame([134000, 1340, 1340, 136680], [$plan['frais']->montant, $plan['frais']->fondsSoutien, $plan['frais']->fraisRetrait, $plan['frais']->total()]);
        self::assertSame(1340, array_sum(array_column($plan['parts'], 'fondsSoutien')));
        self::assertSame(1340, array_sum(array_column($plan['parts'], 'fraisRetrait')));
        self::assertSame(134000, array_sum(array_column($plan['parts'], 'om')));
        // Au prorata de la part Orange Money de chacun.
        self::assertSame(575, $plan['parts']['roll-up']['fondsSoutien']);
        self::assertSame(640, $plan['parts']['t-shirts']['fondsSoutien']);

        // Taux à 0 : aucun frais.
        $sans = RepartitionPaiement::planifier(self::DUS, 0, false, null, (new ParametresPaiement())->setTauxFondsSoutien(0)->setTauxFraisRetrait(0));
        self::assertSame(139000, $sans['frais']->total());
        self::assertSame(0, array_sum(array_column($sans['parts'], 'fraisRetrait')));
    }

    public function testAutreMontantPaieLesPlusAnciennesDAbord(): void
    {
        $plan = RepartitionPaiement::planifier(self::DUS, 5000, true, 60000, new ParametresPaiement());

        self::assertSame(60000, $plan['om']);
        self::assertSame(['roll-up', 'cartes'], array_keys($plan['parts']), 'rien pour la facture à venir');
        self::assertSame([57500, 2500], [$plan['parts']['roll-up']['om'], $plan['parts']['cartes']['om']]);
        // Les frais suivent le montant payé, pas le total dû.
        self::assertSame([600, 600, 61200], [$plan['frais']->fondsSoutien, $plan['frais']->fraisRetrait, $plan['frais']->total()]);
    }

    public function testAutreMontantEntreLeMinimumEtLeTotal(): void
    {
        foreach ([999, 0, 134001] as $montant) {
            try {
                RepartitionPaiement::planifier(self::DUS, 5000, true, $montant, new ParametresPaiement());
                self::fail($montant.' F ne doit pas passer');
            } catch (\DomainException) {
            }
        }
        self::assertSame('Le montant doit être d\'au moins 1 000 F.', RepartitionPaiement::refusMontant(999, 134000));
        self::assertSame('Le montant ne peut pas dépasser 134 000 F.', RepartitionPaiement::refusMontant(134001, 134000));
        self::assertNull(RepartitionPaiement::refusMontant(1000, 134000));
        self::assertNull(RepartitionPaiement::refusMontant(134000, 134000));
        // Moins de 1 000 F à payer : le minimum est ce qui reste.
        self::assertNull(RepartitionPaiement::refusMontant(800, 800));
        self::assertNotNull(RepartitionPaiement::refusMontant(500, 800));
        self::assertSame('Rien à payer par Orange Money : votre avoir couvre tout.', RepartitionPaiement::refusMontant(1000, 0));
    }

    public function testAvoirQuiCouvreToutSansOrangeMoney(): void
    {
        $plan = RepartitionPaiement::planifier(['a' => 3000, 'b' => 2000], 10000, true, null, new ParametresPaiement());

        self::assertSame([5000, 5000, 0, 0], [$plan['total'], $plan['avoir'], $plan['omMax'], $plan['om']]);
        self::assertSame(0, $plan['frais']->total());
        self::assertSame([3000, 2000], array_column($plan['parts'], 'avoir'));
    }

    public function testSansAvoirChoisiRienNEstDeduit(): void
    {
        $plan = RepartitionPaiement::planifier(self::DUS, 5000, false, null, new ParametresPaiement());

        self::assertSame([0, 139000], [$plan['avoir'], $plan['om']]);
    }

    public function testRienDeChoisi(): void
    {
        $this->expectException(\DomainException::class);
        RepartitionPaiement::planifier(['a' => 0], 0, false, null, new ParametresPaiement());
    }

    public function testPartageAuFrancPres(): void
    {
        self::assertSame(['a' => 1, 'b' => 1, 'c' => 1], RepartitionPaiement::proportionnel(3, ['a' => 1, 'b' => 1, 'c' => 1]));
        // À égalité, le premier (la plus ancienne échéance) prend le franc de plus.
        self::assertSame(['a' => 1, 'b' => 1, 'c' => 0], RepartitionPaiement::proportionnel(2, ['a' => 1, 'b' => 1, 'c' => 1]));
        self::assertSame(['a' => 34, 'b' => 33, 'c' => 33], RepartitionPaiement::proportionnel(100, ['a' => 10, 'b' => 10, 'c' => 10]));
        self::assertSame(['a' => 0, 'b' => 0], RepartitionPaiement::proportionnel(10, ['a' => 0, 'b' => 0]));
        self::assertSame(['a' => 3, 'b' => 2], RepartitionPaiement::dansLOrdre(['a' => 3, 'b' => 4], 5));
    }
}
