<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\FinancesDuJour;
use PHPUnit\Framework\TestCase;

final class FinancesDuJourTest extends TestCase
{
    public function testPartDeLObjectif(): void
    {
        self::assertSame(54, FinancesDuJour::part(4860000, 9000000));
        self::assertSame(0, FinancesDuJour::part(0, 9000000));
        self::assertSame(112, FinancesDuJour::part(10080000, 9000000));
        // Sans objectif fixé, pas de pourcentage.
        self::assertNull(FinancesDuJour::part(4860000, 0));
    }

    public function testLibelleDesEncaissementsAValider(): void
    {
        self::assertSame('2 encaissements en ligne', FinancesDuJour::libelleEncaissements(2, 2));
        self::assertSame('1 encaissement en ligne', FinancesDuJour::libelleEncaissements(1, 1));
        // Dès qu'un paiement a été saisi au comptoir, ce n'est plus « en ligne ».
        self::assertSame('3 encaissements à valider', FinancesDuJour::libelleEncaissements(3, 2));
        self::assertSame('1 encaissement à valider', FinancesDuJour::libelleEncaissements(1, 0));
    }

    public function testOrdreDeLaMaquettePuisLaPlusAncienne(): void
    {
        $lignes = [
            ['type' => 'orange_money', 'date' => new \DateTimeImmutable('2026-10-01')],
            ['type' => 'demande', 'date' => new \DateTimeImmutable('2026-10-07')],
            ['type' => 'encaissements', 'date' => new \DateTimeImmutable('2026-10-12')],
            ['type' => 'depense', 'date' => new \DateTimeImmutable('2026-10-13 16:20')],
            ['type' => 'depense', 'date' => new \DateTimeImmutable('2026-10-11 09:00')],
            ['type' => 'decaissements', 'date' => new \DateTimeImmutable('2026-10-02')],
        ];

        $tries = FinancesDuJour::trier($lignes);

        self::assertSame(['depense', 'depense', 'encaissements', 'demande', 'decaissements', 'orange_money'], array_column($tries, 'type'));
        self::assertSame('2026-10-11', $tries[0]['date']->format('Y-m-d'));
    }
}
