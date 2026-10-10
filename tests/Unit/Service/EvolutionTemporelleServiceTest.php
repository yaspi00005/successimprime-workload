<?php

namespace App\Tests\Unit\Service;

use App\Service\EvolutionTemporelleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvolutionTemporelleServiceTest extends TestCase
{
    private EvolutionTemporelleService $service;

    protected function setUp(): void
    {
        $this->service = new EvolutionTemporelleService();
    }

    public function testGranulariteInconnueRetombeSurMois(): void
    {
        self::assertSame('mois', $this->service->normaliserGranularite(null));
        self::assertSame('mois', $this->service->normaliserGranularite('trimestre'));
        self::assertSame('semaine', $this->service->normaliserGranularite('semaine'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function datesProvider(): iterable
    {
        yield 'jour' => ['jour', '2026-10-09', '09/10'];
        yield 'semaine' => ['semaine', '2026-W41', 'S41'];
        yield 'mois' => ['mois', '2026-10', 'Oct 26'];
        yield 'annee' => ['annee', '2026', '2026'];
    }

    #[DataProvider('datesProvider')]
    public function testCleEtLibellePourDate(string $granularite, string $cle, string $libelle): void
    {
        $date = new \DateTimeImmutable('2026-10-09 15:30');

        self::assertSame($cle, $this->service->clePourDate($date, $granularite));
        self::assertSame($libelle, $this->service->libellePourDate($date, $granularite));
    }

    public function testSemaineIsoEnFinDAnnee(): void
    {
        // Le 1er janvier 2027 appartient a la semaine 53 de 2026 (ISO 8601).
        $date = new \DateTimeImmutable('2027-01-01');

        self::assertSame('2026-W53', $this->service->clePourDate($date, 'semaine'));
    }

    public function testGenererPaniersRespecteLeNombreEtLOrdre(): void
    {
        [$cles, $labels, $debut] = $this->service->genererPaniers('mois', 12);

        self::assertCount(12, $cles);
        self::assertCount(12, $labels);
        self::assertSame((new \DateTimeImmutable('now'))->format('Y-m'), end($cles));
        self::assertSame($cles, array_values(array_unique($cles)));

        $triees = $cles;
        sort($triees);
        self::assertSame($triees, $cles);

        self::assertSame('01 00:00', $debut->format('d H:i'));
        self::assertSame($cles[0], $debut->format('Y-m'));
    }

    public function testNombreDePointsParDefaut(): void
    {
        self::assertCount(30, $this->service->genererPaniers('jour')[0]);
        self::assertCount(12, $this->service->genererPaniers('semaine')[0]);
        self::assertCount(5, $this->service->genererPaniers('annee')[0]);
    }
}
