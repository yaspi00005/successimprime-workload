<?php

namespace App\Tests\Unit\Service\Livraisons;

use App\Entity\BonLivraison;
use App\Service\Livraisons\Tournee;
use PHPUnit\Framework\TestCase;

final class TourneeTest extends TestCase
{
    public function testAuPlusProcheDepuisLeDepot(): void
    {
        $points = [10 => [12.70, -8.00], 11 => null, 12 => [12.64, -8.00], 13 => [12.66, -8.00]];
        self::assertSame([12, 13, 10, 11], Tournee::auPlusProche($points, [12.63, -8.00]));
        self::assertSame([10, 13, 12, 11], Tournee::auPlusProche($points, null));
    }

    public function testALHeureSelonLaFinDuCreneau(): void
    {
        $b = new BonLivraison();
        $b->setCreneau('14:00-16:00')->setPrevuLe(new \DateTimeImmutable('2026-10-16'));
        self::assertFalse(Tournee::aLHeure($b));
        (new \ReflectionProperty(BonLivraison::class, 'livreLe'))->setValue($b, new \DateTimeImmutable('2026-10-16 15:40'));
        self::assertTrue(Tournee::aLHeure($b));
        (new \ReflectionProperty(BonLivraison::class, 'livreLe'))->setValue($b, new \DateTimeImmutable('2026-10-16 16:20'));
        self::assertFalse(Tournee::aLHeure($b));
    }
}
