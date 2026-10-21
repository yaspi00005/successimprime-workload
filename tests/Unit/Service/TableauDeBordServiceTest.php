<?php

namespace App\Tests\Unit\Service;

use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Service\TableauDeBordService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TableauDeBordServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool, ?string, string}>
     */
    public static function lignesProvider(): iterable
    {
        yield 'fichier pas encore controle' => [CommandesDetails::PRODUCTION_A_PRODUIRE, true, false, null, 'pao'];
        yield 'correction demandee' => [CommandesDetails::PRODUCTION_A_PRODUIRE, true, false, ControlePrePresse::STATUT_CORRECTION_REQUISE, 'pao'];
        yield 'BAT chez le client' => [CommandesDetails::PRODUCTION_A_PRODUIRE, true, false, ControlePrePresse::STATUT_EN_ATTENTE_BAT, 'bat'];
        yield 'BAT valide' => [CommandesDetails::PRODUCTION_A_PRODUIRE, true, true, ControlePrePresse::STATUT_VALIDE, 'impression'];
        yield 'sans prepresse' => [CommandesDetails::PRODUCTION_A_PRODUIRE, false, false, null, 'impression'];
        yield 'en production' => [CommandesDetails::PRODUCTION_EN_COURS, true, true, null, 'impression'];
        // Imprimée, pas encore contrôlée ni étiquetée (PD06) : pas encore « Prête ».
        yield 'terminee' => [CommandesDetails::PRODUCTION_TERMINEE, true, true, null, 'finition'];
        yield 'prete a livrer' => [CommandesDetails::PRODUCTION_PRETE_LIVRAISON, true, true, null, 'prete'];
    }

    #[DataProvider('lignesProvider')]
    public function testColonnePourLigne(string $statut, bool $prePresse, bool $bat, ?string $controle, string $colonne): void
    {
        self::assertSame($colonne, TableauDeBordService::colonnePourLigne($statut, $prePresse, $bat, $controle));
        self::assertArrayHasKey($colonne, TableauDeBordService::COLONNES_PRODUCTION);
    }

    public function testVariation(): void
    {
        self::assertSame(12, TableauDeBordService::variation(112, 100));
        self::assertSame(-50, TableauDeBordService::variation(50, 100));
        self::assertNull(TableauDeBordService::variation(50, 0));
    }

    public function testPointsCourbe(): void
    {
        self::assertSame('', TableauDeBordService::pointsCourbe([5]));
        self::assertSame('', TableauDeBordService::pointsCourbe([0, 0, 0]));
        self::assertSame('0,22 40,12 80,2', TableauDeBordService::pointsCourbe([0, 5, 10]));
    }

    public function testSerieHebdomadaire(): void
    {
        $debut = new \DateTimeImmutable('2026-09-07');
        $operations = [
            ['date' => new \DateTimeImmutable('2026-09-01'), 'montant' => 999],
            ['date' => new \DateTimeImmutable('2026-09-08 10:00'), 'montant' => 100],
            ['date' => new \DateTimeImmutable('2026-09-13 23:00'), 'montant' => 50],
            ['date' => new \DateTimeImmutable('2026-09-14'), 'montant' => 30],
        ];

        self::assertSame([150, 30, 0], TableauDeBordService::serieHebdomadaire($operations, $debut, 3));
    }

    public function testSerieMensuelle(): void
    {
        $debut = new \DateTimeImmutable('2025-11-01');
        $operations = [
            ['date' => new \DateTimeImmutable('2025-11-15'), 'montant' => 200],
            ['date' => new \DateTimeImmutable('2026-01-03'), 'montant' => 400],
            ['date' => new \DateTimeImmutable('2026-02-01'), 'montant' => 999],
        ];

        $serie = TableauDeBordService::serieMensuelle($operations, $debut, 3);

        self::assertSame(['N', 'D', 'J'], array_column($serie, 'libelle'));
        self::assertSame([200, 0, 400], array_column($serie, 'montant'));
        self::assertSame([50, 0, 100], array_column($serie, 'hauteur'));
        self::assertSame([false, false, true], array_column($serie, 'courant'));
    }
}
