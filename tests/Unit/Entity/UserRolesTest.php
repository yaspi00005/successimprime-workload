<?php

namespace App\Tests\Unit\Entity;

use App\Entity\RoleUtilisateur;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserRolesTest extends TestCase
{
    public function testLesInformationsSensiblesSAjoutentAuProfil(): void
    {
        $user = (new User())->setRoles(['ROLE_GRAPHISTE', 'ROLE_COUT_MARGE', 'ROLE_STATS_VENTES', 'ROLE_INCONNU']);

        self::assertSame(['ROLE_GRAPHISTE', 'ROLE_STATS_VENTES', 'ROLE_COUT_MARGE'], array_values(array_diff($user->getRoles(), ['ROLE_USER'])));
    }

    public function testUneInformationSensibleSeuleNeSuffitPas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new User())->setRoles(['ROLE_PAIEMENT_VOIR']);
    }

    public function testLAdministrateurResteSeul(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN', 'ROLE_COUT_MARGE']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testLesDroitsViennentDuRoleAvecLesCasesDeLaPersonne(): void
    {
        $caisse = (new RoleUtilisateur())->setCode('ROLE_CAISSE_COMMANDE')->setNom('Caisse')
            ->setDroits(['ROLE_COMMANDE', 'ROLE_PAIEMENT_VOIR', 'ROLE_INCONNU']);
        $user = (new User())->setProfil($caisse)->definirDroits(['ROLE_COMMANDE', 'ROLE_STATS_GLOBAL']);

        self::assertSame(['ROLE_STATS_GLOBAL'], $user->getDroitsAjoutes());
        self::assertSame(['ROLE_PAIEMENT_VOIR'], $user->getDroitsRetires());
        self::assertSame(['ROLE_CAISSE_COMMANDE', 'ROLE_COMMANDE', 'ROLE_STATS_GLOBAL', 'ROLE_USER'], $user->getRoles());
        self::assertSame('herite', $user->etatDroit('ROLE_COMMANDE'));
        self::assertSame('retire', $user->etatDroit('ROLE_PAIEMENT_VOIR'));
        self::assertSame('ajoute', $user->etatDroit('ROLE_STATS_GLOBAL'));
        self::assertSame('non', $user->etatDroit('ROLE_LIVRAISON'));
        self::assertSame(2, $user->nombreDroitsPersonnalises());
    }

    public function testUnDroitAjouteAuRoleArriveChezTousCeuxQuiLOnt(): void
    {
        $role = (new RoleUtilisateur())->setCode('ROLE_PERSO_COMPTABLE')->setNom('Comptable')->setDroits([]);
        $user = (new User())->setProfil($role);

        self::assertNotContains('ROLE_TRESORERIE_VOIR', $user->getRoles());

        $role->setDroits(['ROLE_TRESORERIE_VOIR']);

        self::assertContains('ROLE_TRESORERIE_VOIR', $user->getRoles());
    }

    public function testLeRoleAdministrateurDonneTout(): void
    {
        $admin = (new RoleUtilisateur())->setCode(RoleUtilisateur::CODE_ADMIN)->setNom('Administrateur');
        $user = (new User())->setProfil($admin);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertTrue($admin->aLeDroit('ROLE_COUT_MARGE'));
    }
}
