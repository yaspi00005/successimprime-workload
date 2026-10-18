<?php

namespace App\Tests\Unit\Security;

use App\Entity\RoleUtilisateur;
use App\Entity\User;
use DoctrineMigrations\Version20261018175000;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../migrations/Version20261018175000.php';

/**
 * Réponse de Yaya (18/10/2026, Q2) : le rôle « Caisse et commercial » rend
 * l'argent du compte d'un client d'office ; un retrait sur la fiche d'une
 * personne reste respecté.
 */
final class CaisseRendArgentTest extends TestCase
{
    private const CAISSE = ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_JOURNAL_CAISSE'];

    public function testLeDroitEstAjouteUneSeuleFois(): void
    {
        $avec = Version20261018175000::avec(self::CAISSE);
        self::assertSame([...self::CAISSE, 'ROLE_SOLDE_CLIENT_REMBOURSER'], $avec);
        self::assertSame($avec, Version20261018175000::avec($avec));
        self::assertSame(self::CAISSE, Version20261018175000::sans($avec));
        self::assertSame('ROLE_CAISSE_COMMANDE', Version20261018175000::ROLE);
    }

    public function testLaCaisseRendLArgentSaufRetraitSurSaFiche(): void
    {
        $role = (new RoleUtilisateur())->setCode('ROLE_CAISSE_COMMANDE')->setNom('Caisse et commercial')->setDroits(Version20261018175000::avec(self::CAISSE));
        $aminata = (new User())->setProfil($role);
        self::assertContains('ROLE_SOLDE_CLIENT_REMBOURSER', $aminata->getRoles());

        $moussa = (new User())->setProfil($role)->definirDroits(self::CAISSE);
        self::assertNotContains('ROLE_SOLDE_CLIENT_REMBOURSER', $moussa->getRoles());
    }
}
