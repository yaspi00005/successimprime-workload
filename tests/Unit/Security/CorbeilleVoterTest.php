<?php

namespace App\Tests\Unit\Security;

use App\Entity\CompteClient;
use App\Entity\Corbeille;
use App\Entity\User;
use App\Security\CorbeilleVoter;
use App\Security\Droits;
use App\Security\ModulesRoles;
use App\Service\Corbeille\TypesCorbeille;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Corbeille (docs/corbeille.md) : la Direction et le droit « Corbeille »,
 * sur l'ERP local seulement ; chacun n'y voit que les fiches de ses autres
 * droits.
 */
final class CorbeilleVoterTest extends TestCase
{
    public function testLaDirectionVoitTout(): void
    {
        $voter = $this->voter(['ROLE_ADMIN', 'ROLE_CORBEILLE']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton(), null, [CorbeilleVoter::CORBEILLE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton(), $this->entree('produits'), [CorbeilleVoter::ENTREE]));
        self::assertNull($voter->tablesVisibles());
    }

    public function testSansLeDroitCorbeilleRienNeSOuvre(): void
    {
        $voter = $this->voter(['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(), null, [CorbeilleVoter::CORBEILLE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(), $this->entree('clients'), [CorbeilleVoter::ENTREE]));
    }

    public function testLeDroitCorbeilleSeulementPourLesFichesDeSesAutresDroits(): void
    {
        $voter = $this->voter([Droits::CORBEILLE, 'ROLE_CLIENT', 'ROLE_DEVIS']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton(), null, [CorbeilleVoter::CORBEILLE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton(), $this->entree('clients'), [CorbeilleVoter::ENTREE]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton(), $this->entree('devis'), [CorbeilleVoter::ENTREE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(), $this->entree('paiements'), [CorbeilleVoter::ENTREE]), 'paiement : droit Paiements');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(), $this->entree('produits'), [CorbeilleVoter::ENTREE]), 'catalogue : Direction');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(), $this->entree('table_sans_type'), [CorbeilleVoter::ENTREE]), 'inconnue : Direction');

        $visibles = $voter->tablesVisibles();
        self::assertContains('clients', $visibles);
        self::assertContains('devis', $visibles);
        self::assertNotContains('paiements', $visibles);
        self::assertNotContains('user', $visibles);
    }

    public function testFermeeEnLigne(): void
    {
        $voter = $this->voter(['ROLE_ADMIN', 'ROLE_CORBEILLE'], true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(), null, [CorbeilleVoter::CORBEILLE]));
    }

    public function testUnClientDeLEspaceNEntreJamais(): void
    {
        $voter = $this->voter(['ROLE_ADMIN', 'ROLE_CORBEILLE']);
        $jeton = new UsernamePasswordToken($this->createStub(CompteClient::class), 'espace', ['ROLE_CLIENT_ESPACE']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($jeton, null, [CorbeilleVoter::CORBEILLE]));
    }

    public function testDroitEtModeVoirDansRolesEtMenus(): void
    {
        self::assertArrayHasKey(Droits::CORBEILLE, Droits::catalogue());
        self::assertArrayHasKey(Droits::LECTURE_CORBEILLE, Droits::catalogue());
        self::assertNotContains(Droits::LECTURE_CORBEILLE, Droits::sansRestriction(), 'la Direction n\'est jamais en lecture seule');
        self::assertSame([ModulesRoles::MODIFIER, ModulesRoles::VOIR, ModulesRoles::AUCUN], ModulesRoles::niveauxPossibles('corbeille'));
        self::assertSame(ModulesRoles::VOIR, ModulesRoles::niveau('corbeille', [Droits::CORBEILLE, Droits::LECTURE_CORBEILLE]));
        self::assertSame('corbeille', ModulesRoles::moduleEnLecture('app_corbeille_restaurer', [Droits::CORBEILLE, Droits::LECTURE_CORBEILLE]));
        self::assertNull(ModulesRoles::moduleEnLecture('app_corbeille_restaurer', [Droits::CORBEILLE]));
        self::assertSame(TypesCorbeille::DIRECTION, User::ROLE_ADMIN);
    }

    /** @param list<string> $roles */
    private function voter(array $roles, bool $enLigne = false): CorbeilleVoter
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribut): bool => \in_array($attribut, $roles, true));

        return new CorbeilleVoter($security, $enLigne);
    }

    private function jeton(): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new User(), 'main', ['ROLE_USER']);
    }

    private function entree(string $table): Corbeille
    {
        $entree = new Corbeille();
        (new \ReflectionProperty(Corbeille::class, 'tableNom'))->setValue($entree, $table);

        return $entree;
    }
}
