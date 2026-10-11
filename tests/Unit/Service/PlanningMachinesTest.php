<?php

namespace App\Tests\Unit\Service;

use App\Entity\OrdreProduction;
use App\Service\PlanningMachines;
use PHPUnit\Framework\TestCase;

final class PlanningMachinesTest extends TestCase
{
    public function testUnOrdreSansCreneauNEstPasPlace(): void
    {
        self::assertSame([null, null], PlanningMachines::creneau(new OrdreProduction(), new \DateTimeImmutable('2026-10-11 09:00')));
    }

    public function testCreneauPrevu(): void
    {
        $ordre = (new OrdreProduction())->planifier(new \DateTimeImmutable('2026-10-11 11:00'), 210);
        [$debut, $fin] = PlanningMachines::creneau($ordre, new \DateTimeImmutable('2026-10-11 09:00'));

        self::assertSame('11:00', $debut->format('H:i'));
        self::assertSame('14:30', $fin->format('H:i'));
    }

    public function testSansDureeUneHeureParDefaut(): void
    {
        $ordre = (new OrdreProduction())->planifier(new \DateTimeImmutable('2026-10-11 15:00'), null);
        [, $fin] = PlanningMachines::creneau($ordre, new \DateTimeImmutable('2026-10-11 09:00'));

        self::assertSame('16:00', $fin->format('H:i'));
    }
}
