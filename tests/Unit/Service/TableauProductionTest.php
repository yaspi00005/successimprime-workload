<?php

namespace App\Tests\Unit\Service;

use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Service\TableauProduction;
use PHPUnit\Framework\TestCase;

final class TableauProductionTest extends TestCase
{
    public function testLePrepresseDecideAvantLImpression(): void
    {
        $aProduire = CommandesDetails::PRODUCTION_A_PRODUIRE;
        self::assertSame('pao', TableauProduction::colonne($aProduire, null, false));
        self::assertSame('pao', TableauProduction::colonne($aProduire, ControlePrePresse::STATUT_CORRECTION_REQUISE, false));
        self::assertSame('bat', TableauProduction::colonne($aProduire, ControlePrePresse::STATUT_EN_ATTENTE_BAT, false));
        self::assertSame('impression', TableauProduction::colonne($aProduire, ControlePrePresse::STATUT_ENVOYE_PRODUCTION, false));
    }

    public function testEtapesDeProduction(): void
    {
        self::assertSame('impression', TableauProduction::colonne(CommandesDetails::PRODUCTION_EN_COURS, ControlePrePresse::STATUT_EN_ATTENTE_BAT, false));
        self::assertSame('finition', TableauProduction::colonne(CommandesDetails::PRODUCTION_TERMINEE, null, false));
        self::assertSame('prete', TableauProduction::colonne(CommandesDetails::PRODUCTION_EN_LIVRAISON, null, false));
        self::assertSame('livree', TableauProduction::colonne(CommandesDetails::PRODUCTION_LIVREE, null, true));
        self::assertNull(TableauProduction::colonne(CommandesDetails::PRODUCTION_LIVREE, null, false));
        self::assertNull(TableauProduction::colonne(CommandesDetails::PRODUCTION_ANNULEE, null, false));
    }

    public function testRetard(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-11 16:00');
        self::assertNull(TableauProduction::retard(null, $maintenant));
        self::assertNull(TableauProduction::retard(new \DateTimeImmutable('2026-10-11'), $maintenant), 'Une date sans heure court jusqu\'au soir.');
        self::assertSame('4 h', TableauProduction::retard(new \DateTimeImmutable('2026-10-11 12:00'), $maintenant));
        self::assertSame('1 j', TableauProduction::retard(new \DateTimeImmutable('2026-10-09'), $maintenant), 'Due le 9 au soir, 40 h plus tard.');
    }
}
