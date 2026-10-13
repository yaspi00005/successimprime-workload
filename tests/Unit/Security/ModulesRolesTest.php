<?php

namespace App\Tests\Unit\Security;

use App\EventSubscriber\LectureSeuleSubscriber;
use App\Security\Droits;
use App\Security\ModulesRoles;
use PHPUnit\Framework\TestCase;

final class ModulesRolesTest extends TestCase
{
    private const CAISSE = ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_JOURNAL_CAISSE', 'ROLE_PAIEMENT_VOIR', 'ROLE_STATS_PERSO'];

    public function testNiveauxLusDepuisLesDroits(): void
    {
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::niveau('ventes', self::CAISSE));
        self::assertSame(ModulesRoles::AUCUN, ModulesRoles::niveau('atelier', self::CAISSE));
        self::assertSame(ModulesRoles::VOIR, ModulesRoles::niveau('atelier', ['ROLE_PRODUCTION_VOIR']));
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::niveau('atelier', ['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER']));
        self::assertSame(ModulesRoles::VOIR, ModulesRoles::niveau('clients', ['ROLE_CLIENT', Droits::LECTURE_CLIENTS]));
    }

    public function testModulesFixes(): void
    {
        self::assertSame(ModulesRoles::AUCUN, ModulesRoles::niveau('reglages', self::CAISSE));
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::niveau('reglages', [], true));
        self::assertSame(ModulesRoles::VOIR, ModulesRoles::niveau('mon_espace', []));
        self::assertSame([], ModulesRoles::niveauxPossibles('stock'));
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::niveau('finances', [], true));
    }

    public function testPartiel(): void
    {
        // Le graphiste fait des devis et commandes, mais n'encaisse pas.
        self::assertTrue(ModulesRoles::partiel('ventes', ['ROLE_DEVIS', 'ROLE_COMMANDE']));
        self::assertFalse(ModulesRoles::partiel('ventes', self::CAISSE));
        self::assertFalse(ModulesRoles::partiel('atelier', []));
    }

    public function testCycleDesNiveaux(): void
    {
        self::assertSame(ModulesRoles::VOIR, ModulesRoles::suivant('clients', ModulesRoles::MODIFIER));
        self::assertSame(ModulesRoles::AUCUN, ModulesRoles::suivant('clients', ModulesRoles::VOIR));
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::suivant('clients', ModulesRoles::AUCUN));
        // Pas de lecture seule pour l'equipe (salaires) : Modifier ou rien.
        self::assertSame(ModulesRoles::AUCUN, ModulesRoles::suivant('equipe', ModulesRoles::MODIFIER));
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::suivant('equipe', ModulesRoles::AUCUN));
    }

    public function testAppliquerNeToucheQueLeModule(): void
    {
        $voir = ModulesRoles::appliquer('ventes', ModulesRoles::VOIR, self::CAISSE);
        self::assertContains(Droits::LECTURE_VENTES, $voir);
        self::assertContains('ROLE_COMMANDE', $voir);
        self::assertContains('ROLE_CLIENT', $voir, 'autre module');
        self::assertContains('ROLE_PAIEMENT_VOIR', $voir, 'montant');
        self::assertSame(ModulesRoles::VOIR, ModulesRoles::niveau('ventes', $voir));

        $aucun = ModulesRoles::appliquer('ventes', ModulesRoles::AUCUN, $voir);
        self::assertNotContains(Droits::LECTURE_VENTES, $aucun);
        self::assertNotContains('ROLE_COMMANDE', $aucun);
        self::assertSame(['ROLE_CLIENT', 'ROLE_STATS_PERSO', 'ROLE_PAIEMENT_VOIR'], $aucun);

        $modifier = ModulesRoles::appliquer('ventes', ModulesRoles::MODIFIER, $aucun);
        self::assertSame(ModulesRoles::MODIFIER, ModulesRoles::niveau('ventes', $modifier));
        self::assertNotContains(Droits::LECTURE_VENTES, $modifier);

        $atelier = ModulesRoles::appliquer('atelier', ModulesRoles::VOIR, ['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER']);
        self::assertSame(['ROLE_PRODUCTION_VOIR'], $atelier);
    }

    public function testNiveauImpossibleRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModulesRoles::appliquer('equipe', ModulesRoles::VOIR, []);
    }

    public function testModuleEnLecture(): void
    {
        $droits = ['ROLE_CLIENT', Droits::LECTURE_CLIENTS, 'ROLE_COMMANDE'];
        self::assertSame('clients', ModulesRoles::moduleEnLecture('app_clients_edit', $droits));
        self::assertSame('clients', ModulesRoles::moduleEnLecture('app_prospects_index', $droits));
        self::assertNull(ModulesRoles::moduleEnLecture('app_commandes_new', $droits));
        self::assertNull(ModulesRoles::moduleEnLecture('app_clients_edit', ['ROLE_CLIENT']));
    }

    public function testEcrituresRefuseesEnLecture(): void
    {
        self::assertTrue(LectureSeuleSubscriber::ecrit('POST', 'app_clients_show'));
        self::assertTrue(LectureSeuleSubscriber::ecrit('GET', 'app_clients_nouveau'));
        self::assertTrue(LectureSeuleSubscriber::ecrit('GET', 'app_clients_edit_form'));
        self::assertTrue(LectureSeuleSubscriber::ecrit('GET', 'app_devis_modifier'));
        self::assertFalse(LectureSeuleSubscriber::ecrit('GET', 'app_clients_show'));
        self::assertFalse(LectureSeuleSubscriber::ecrit('GET', 'app_clients_index'));
        self::assertFalse(LectureSeuleSubscriber::ecrit('HEAD', 'app_devis_show'));
    }

    public function testLaDirectionNAJamaisDeRestriction(): void
    {
        self::assertNotContains(Droits::LECTURE_VENTES, Droits::sansRestriction());
        self::assertContains('ROLE_CLIENT', Droits::sansRestriction());
    }
}
