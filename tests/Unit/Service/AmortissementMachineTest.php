<?php

namespace App\Tests\Unit\Service;

use App\Entity\Machines;
use App\Service\AmortissementMachine;
use App\Service\CalculateurCoutRevient;
use PHPUnit\Framework\TestCase;

final class AmortissementMachineTest extends TestCase
{
    private function solvant(): Machines
    {
        return (new Machines())->setPrixAchat(18_000_000)->setDureeAmortissementMois(60)->setCapaciteUsage(60_000)->setValeurResiduelle(0);
    }

    public function testCoutParUniteDeLaSpecification(): void
    {
        self::assertSame(300.0, $this->solvant()->coutUsageUnitaire());
        self::assertSame(250.0, $this->solvant()->setValeurResiduelle(3_000_000)->coutUsageUnitaire());
        self::assertNull((new Machines())->setPrixAchat(1000)->coutUsageUnitaire());
    }

    public function testCumulALUsagePlafonne(): void
    {
        $m = $this->solvant();
        $m->enregistrerUsage(1000);
        self::assertSame(['charge' => 300000.0, 'methode' => 'usage'], AmortissementMachine::cumul($m, 'usage', new \DateTimeImmutable()));
        $m->enregistrerUsage(100_000);
        self::assertSame(18_000_000.0, AmortissementMachine::cumul($m, 'usage', new \DateTimeImmutable())['charge']);
    }

    public function testSansCapaciteOuEnLineaireOnEtaleSurLaDuree(): void
    {
        $m = $this->solvant()->setDateMiseService(new \DateTime('2026-01-01'));
        self::assertSame(['charge' => 2_700_000.0, 'methode' => 'lineaire'], AmortissementMachine::cumul($m, 'lineaire', new \DateTimeImmutable('2026-10-11')));
        self::assertSame('lineaire', AmortissementMachine::cumul($m->setCapaciteUsage(null), 'usage', new \DateTimeImmutable('2026-10-11'))['methode']);
    }

    public function testPourTravailEnPagesA4PourLesMachinesALaFeuille(): void
    {
        $canon = (new Machines())->setModeFacturation(Machines::MODE_FACTURATION_FEUILLE)->setPrixAchat(1_000_000)->setCapaciteUsage(500_000);
        self::assertSame(20, AmortissementMachine::pourTravail($canon, 'usage', 0.21 * 0.297 * 10));
        self::assertNull(AmortissementMachine::pourTravail($canon, 'lineaire', 1.0));
    }

    public function testLeCoutDeRevientPrendLAmortissementALUsage(): void
    {
        $calcul = new CalculateurCoutRevient(null, 'usage');
        $m = $this->solvant()->setVitesseM2Heure(20)->setCoutEncreM2(0);
        $ligne = (new \App\Entity\CommandesDetails())->setMachine($m)->setLargeur('1')->setLongueur('2')->setQuantite(1);
        self::assertSame(600, $calcul->pourLigne($ligne)['machine']);
    }
}
