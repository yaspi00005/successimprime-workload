<?php

namespace App\Tests\Unit\Service\SuiviMetiers;

use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\SuiviEquipes;
use PHPUnit\Framework\TestCase;

final class SuiviEquipesTest extends TestCase
{
    private static function personne(string $code, array $droits = []): User
    {
        $role = (new RoleUtilisateur())->setCode($code)->setNom($code)->setDroits($droits);

        return (new User())->setProfil($role);
    }

    public function testLeMetierVientDuRole(): void
    {
        self::assertSame(Bareme::GRAPHISTE, SuiviEquipes::metier(self::personne('ROLE_GRAPHISTE')));
        self::assertSame(Bareme::OPERATEUR, SuiviEquipes::metier(self::personne('ROLE_PRODUCTION')));
        self::assertSame(Bareme::LIVREUR, SuiviEquipes::metier(self::personne('ROLE_LIVREUR')));
        self::assertNull(SuiviEquipes::metier(self::personne('ROLE_CAISSE_COMMANDE')));
    }

    public function testLEntretienPasseAvantLeRoleSaufPourUnChefDeProduction(): void
    {
        self::assertSame(Bareme::ENTRETIEN, SuiviEquipes::metier(self::personne('ROLE_CAISSE_COMMANDE', ['ROLE_ENTRETIEN'])));
        self::assertSame(Bareme::OPERATEUR, SuiviEquipes::metier(self::personne('ROLE_PRODUCTION', ['ROLE_ENTRETIEN', 'ROLE_PRODUCTION_GERER'])));
    }

    public function testLAdministrateurNEstPasSuivi(): void
    {
        self::assertNull(SuiviEquipes::metier((new User())->setRoles(['ROLE_ADMIN'])));
    }

    public function testNomCourt(): void
    {
        self::assertSame('Bakary C.', SuiviEquipes::court('Bakary  Coulibaly'));
        self::assertSame('Awa D.', SuiviEquipes::court('Awa Fanta Dembélé'));
        self::assertSame('Sékou', SuiviEquipes::court('Sékou'));
    }
}
