<?php

namespace App\Tests\Unit\Service;

use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Service\JournalComptable;
use PHPUnit\Framework\TestCase;

final class JournalComptableTest extends TestCase
{
    public function testFactureEquilibreeAvecTvaEtMajoration(): void
    {
        $facture = (new Factures())
            ->setNumero('F-0101')
            ->setDateFacture(new \DateTimeImmutable('2026-10-10'))
            ->setTotalHt(45763)
            ->setMontantTva(8237)
            ->setMontantSurfacturation(2000);

        $lignes = JournalComptable::ecrituresFacture($facture);

        self::assertSame(['411', '702', '702', '4431'], array_column($lignes, 'compte'));
        self::assertSame(56000, $lignes[0]['debit']);
        self::assertSame(array_sum(array_column($lignes, 'debit')), array_sum(array_column($lignes, 'credit')));
        self::assertStringStartsWith('Majoration', $lignes[2]['libelle']);
    }

    public function testDepenseEnCaisse(): void
    {
        $caisse = (new CompteTresorerie())->setType(CompteTresorerie::TYPE_CAISSE);
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCategorie(MouvementTresorerie::CATEGORIE_CARBURANT)
            ->setCompteSource($caisse)
            ->setMontant(12000)
            ->setLibelle('Carburant livraison')
            ->setDateOperation(new \DateTimeImmutable('2026-10-09'));

        [$journal, $lignes] = JournalComptable::ecrituresMouvement($mouvement);

        self::assertSame('caisse', $journal);
        self::assertSame(['6042', '571'], array_column($lignes, 'compte'));
        self::assertSame([12000, 0], array_column($lignes, 'debit'));
        self::assertSame([0, 12000], array_column($lignes, 'credit'));
    }

    public function testBalance(): void
    {
        $l = static fn (string $c, int $d, int $cr): array => ['compte' => $c, 'debit' => $d, 'credit' => $cr];
        $balance = JournalComptable::balance(['ventes' => [$l('411', 100, 0), $l('702', 0, 100)], 'caisse' => [$l('571', 60, 0), $l('411', 0, 60)]]);

        self::assertSame(['411', '571', '702'], array_column($balance, 'compte'));
        self::assertSame(40, $balance[0]['solde']);
        self::assertSame(-100, $balance[2]['solde']);
    }

    public function testComptesDeTresorerie(): void
    {
        self::assertSame('5213', JournalComptable::compteTresorerie((new CompteTresorerie())->setType(CompteTresorerie::TYPE_ORANGE_MONEY)));
        self::assertSame('mobile', JournalComptable::journalDe((new CompteTresorerie())->setType(CompteTresorerie::TYPE_WAVE)));
        self::assertSame('471', JournalComptable::compteTresorerie(null));
    }
}
