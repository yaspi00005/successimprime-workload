<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Service\Paiement\EncaissementGroupe;
use PHPUnit\Framework\TestCase;

/**
 * Un montant reçu à la caisse pour plusieurs commandes : réparti la plus
 * ancienne d'abord, contrôlé avant d'enregistrer.
 */
final class EncaissementGroupeTest extends TestCase
{
    public function testLaPlusAncienneDAbord(): void
    {
        // 200 000 F reçus pour 250 000 F dus : les deux premières soldées, la dernière en partie.
        self::assertSame(['parts' => [11 => 100000, 12 => 80000, 13 => 20000], 'surplus' => 0], EncaissementGroupe::repartir(200000, [11 => 100000, 12 => 80000, 13 => 70000]));
        self::assertSame(['parts' => [11 => 50000], 'surplus' => 0], EncaissementGroupe::repartir(50000, [11 => 100000, 12 => 80000]));
        self::assertSame(['parts' => [11 => 100000, 12 => 80000], 'surplus' => 20000], EncaissementGroupe::repartir(200000, [11 => 100000, 12 => 80000]), 'plus que dû : le surplus');
        self::assertSame(['parts' => [], 'surplus' => 0], EncaissementGroupe::repartir(0, [11 => 100000]));
    }

    public function testControles(): void
    {
        $restes = [11 => ['reste' => 100000], 12 => ['reste' => 80000]];
        EncaissementGroupe::verifier([11 => 100000, 12 => 50000], $restes, 150000, 0);
        EncaissementGroupe::verifier([11 => 100000, 12 => 80000], $restes, 190000, 10000);

        foreach ([
            'pas de montant' => [[11 => 1000], 0, 0],
            'rien de coché' => [[], 1000, 0],
            'commande d\'un autre client' => [[99 => 1000], 1000, 0],
            'plus que le reste' => [[12 => 90000], 90000, 0],
            'total différent du reçu' => [[11 => 100000], 150000, 0],
        ] as $cas => [$parts, $recu, $surplus]) {
            try {
                EncaissementGroupe::verifier($parts, $restes, $recu, $surplus);
                self::fail($cas);
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
