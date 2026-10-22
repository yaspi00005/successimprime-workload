<?php

namespace App\Tests\Unit\Security;

use App\Controller\ImmobilisationsController;
use App\Entity\Employes;
use App\Entity\Immobilisation;
use App\Entity\User;
use App\EventSubscriber\PatrimoinePrimesHorsLigneSubscriber;
use App\Security\PrimesVoter;
use App\Service\Primes\Primes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * « Toute info sensible doit tenir compte des droits et rôles » : scores
 * et primes (agent : les siens ; responsable : son équipe ; admin : tout ;
 * les autres : rien), valeurs des biens (admin et comptabilité seulement).
 */
final class DroitsPatrimoinePrimesTest extends TestCase
{
    private User $admin;
    private User $responsable;
    private User $agentDeLEquipe;
    private User $autreAgent;

    protected function setUp(): void
    {
        $this->admin = (new User())->setRoles(['ROLE_ADMIN']);
        $this->responsable = (new User())->setRoles(['ROLE_GRAPHISTE']);
        $this->agentDeLEquipe = (new User())->setRoles(['ROLE_GRAPHISTE'])->setEmploye((new Employes())->setResponsable($this->responsable));
        $this->autreAgent = (new User())->setRoles(['ROLE_LIVREUR'])->setEmploye(new Employes());
    }

    public function testLeResponsableVoitSonEquipeEtPasLesAutres(): void
    {
        $voter = $this->voter(false);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton($this->responsable), $this->agentDeLEquipe, [PrimesVoter::AGENT]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton($this->responsable), $this->autreAgent, [PrimesVoter::AGENT]));
    }

    public function testUnAgentNeVoitNiSesCollegues(): void
    {
        $voter = $this->voter(false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton($this->autreAgent), $this->agentDeLEquipe, [PrimesVoter::AGENT]));
        // Ni sa propre fiche côté équipe : sa note est dans Mon espace (N04), sans l'évaluer lui-même.
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton($this->agentDeLEquipe), $this->agentDeLEquipe, [PrimesVoter::AGENT]));
    }

    public function testLAdminVoitTout(): void
    {
        $voter = $this->voter(true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton($this->admin), $this->autreAgent, [PrimesVoter::AGENT]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->jeton($this->admin), null, [PrimesVoter::EQUIPE]));
    }

    public function testFiltreDeLaVueDEnsemble(): void
    {
        $personnel = [$this->admin, $this->responsable, $this->agentDeLEquipe, $this->autreAgent];

        self::assertSame([$this->responsable, $this->agentDeLEquipe, $this->autreAgent], Primes::visiblesPar($this->admin, $personnel), 'l\'admin voit tout le monde sauf lui-même');
        self::assertSame([$this->agentDeLEquipe], Primes::visiblesPar($this->responsable, $personnel), 'le responsable voit son équipe');
        self::assertSame([], Primes::visiblesPar($this->autreAgent, $personnel), 'un agent ne voit personne');
    }

    /** Registre vu par un agent (ses biens confiés) : ni valeur d'achat, ni cumul, ni valeur nette. */
    public function testLeRegistreSansMontantsPourLAgent(): void
    {
        $bien = new Immobilisation('IMM-0001', 'iMac PAO', 'informatique');
        $lignes = [['bien' => $bien, 'calcul' => ['valeurAchat' => 1_450_000, 'cumul' => 900_000, 'dotation' => 40_000, 'valeurNette' => 550_000, 'libelleMethode' => 'Linéaire 3 ans']]];

        $vues = ImmobilisationsController::sansMontants($lignes);

        self::assertSame($bien, $vues[0]['bien']);
        self::assertSame(['libelleMethode' => 'Linéaire 3 ans'], $vues[0]['calcul']);
    }

    public function testEcransFermesEnLigne(): void
    {
        foreach (['app_immobilisations_patrimoine', 'app_immobilisations_machine', 'app_primes_index', 'app_primes_agent', 'app_bareme_primes', 'app_mon_espace_performance', 'app_mon_espace_prime'] as $route) {
            self::assertTrue(PatrimoinePrimesHorsLigneSubscriber::estLocale($route), $route);
        }
        foreach (['app_mon_espace_index', 'app_bareme_index', 'app_comptabilite_index', 'app_paie_index'] as $route) {
            self::assertFalse(PatrimoinePrimesHorsLigneSubscriber::estLocale($route), $route);
        }
    }

    private function voter(bool $admin): PrimesVoter
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribut): bool => $admin && 'ROLE_ADMIN' === $attribut);

        return new PrimesVoter($security, $this->createStub(EntityManagerInterface::class));
    }

    private function jeton(User $u): UsernamePasswordToken
    {
        return new UsernamePasswordToken($u, 'main', $u->getRoles());
    }
}
