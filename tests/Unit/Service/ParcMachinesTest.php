<?php

namespace App\Tests\Unit\Service;

use App\Service\ParcMachines;
use PHPUnit\Framework\TestCase;

final class ParcMachinesTest extends TestCase
{
    public function testDuree(): void
    {
        self::assertSame('40 min', ParcMachines::duree(40));
        self::assertSame('2 h', ParcMachines::duree(120));
        self::assertSame('1 h 05', ParcMachines::duree(65));
    }

    public function testQuand(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-11 11:20');
        self::assertSame('Demain 8 h', ParcMachines::quand(new \DateTimeImmutable('2026-10-12 08:00'), $maintenant));
        self::assertSame('Aujourd’hui 15 h 30', ParcMachines::quand(new \DateTimeImmutable('2026-10-11 15:30'), $maintenant));
        self::assertSame('Dans 3 jours', ParcMachines::quand(new \DateTimeImmutable('2026-10-14'), $maintenant));
        self::assertSame('Le 30/11', ParcMachines::quand(new \DateTimeImmutable('2026-11-30'), $maintenant));
    }
}
