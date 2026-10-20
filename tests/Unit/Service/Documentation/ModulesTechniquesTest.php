<?php

namespace App\Tests\Unit\Service\Documentation;

use App\Service\Documentation\ModulesTechniques;
use PHPUnit\Framework\TestCase;

final class ModulesTechniquesTest extends TestCase
{
    public function testAdressesTechniquesAvantLeMenu(): void
    {
        self::assertSame('synchronisation', ModulesTechniques::moduleRoute('app_synchro_lot', '/api/synchro/lot'));
        self::assertSame('espace_client', ModulesTechniques::moduleRoute('app_espace_commandes', '/espace/commandes'));
        self::assertSame('securite', ModulesTechniques::moduleRoute('app_login', '/login'));
    }

    public function testRouteRangeeParLeMenu(): void
    {
        self::assertSame('ventes', ModulesTechniques::moduleRoute('app_devis_new', '/devis/new'));
        self::assertSame('equipe', ModulesTechniques::moduleRoute('app_paie_payer', '/equipe/paie/1/payer'));
    }

    public function testRouteInconnueVaDansCommun(): void
    {
        self::assertSame(ModulesTechniques::COMMUN, ModulesTechniques::moduleRoute('zz_inconnue', '/zz-inconnue'));
    }

    public function testServiceParSonDossier(): void
    {
        self::assertSame('atelier', ModulesTechniques::moduleService('App\Service\Achats\SuiviAchats'));
        self::assertNull(ModulesTechniques::moduleService('App\Service\EnLigne'));
    }

    public function testEntiteParLeDebutLePlusLong(): void
    {
        self::assertSame('ventes', ModulesTechniques::moduleEntite('App\Entity\Devis'));
        self::assertSame('ventes', ModulesTechniques::moduleEntite('App\Entity\DevisDetails'));
        self::assertSame('finances', ModulesTechniques::moduleEntite('App\Entity\LigneReleveOrangeMoney'));
    }

    public function testDominantIgnoreCommunEtDepartageParLOrdre(): void
    {
        self::assertSame('ventes', ModulesTechniques::dominant(['commun' => 9, 'finances' => 2, 'ventes' => 2]));
        self::assertSame('finances', ModulesTechniques::dominant(['ventes' => 1, 'finances' => 3]));
        self::assertNull(ModulesTechniques::dominant(['commun' => 3]));
        self::assertNull(ModulesTechniques::dominant([]));
    }

    public function testNomCourt(): void
    {
        self::assertSame('DevisController', ModulesTechniques::nomCourt('App\Controller\DevisController::new'));
        self::assertSame('Devis', ModulesTechniques::nomCourt('Devis'));
    }

    public function testChaqueModuleALibelle(): void
    {
        foreach (array_keys(ModulesTechniques::MODULES) as $module) {
            self::assertNotSame('', ModulesTechniques::libelle($module), $module);
        }
    }
}
