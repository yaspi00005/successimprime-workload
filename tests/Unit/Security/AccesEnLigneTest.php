<?php

namespace App\Tests\Unit\Security;

use App\Entity\AppareilAutorise;
use App\Entity\Employes;
use App\Entity\User;
use App\EventSubscriber\AccesEnLigneSubscriber;
use App\Repository\AppareilAutoriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AccesEnLigneTest extends TestCase
{
    private FabriqueCodes $fabrique;

    protected function setUp(): void
    {
        $this->fabrique = new FabriqueCodes();
    }

    private function acces(string $environnement = 'dev'): AccesEnLigne
    {
        // SMS non configuré : l'envoi échoue sans appel réseau.
        return new AccesEnLigne(
            true,
            $this->createStub(AppareilAutoriseRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->fabrique->codes($environnement),
            $this->createStub(NotificationService::class),
        );
    }

    private function utilisateur(?string $email = null): User
    {
        $employe = (new Employes())->setTelephone('76 12 42 12');
        if (null !== $email) {
            $employe->setEmail($email);
        }

        return (new User())->setEmploye($employe);
    }

    public function testCodeJusteOuvreLaSessionUneSeuleFois(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $envoi = $acces->envoyerCode($this->utilisateur(), $session, '10.0.0.1');

        self::assertFalse($envoi['envoye']);
        self::assertSame('sms', $envoi['canal']);
        self::assertSame('+223 •• •• 42 12', $envoi['destination']);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $envoi['codeDev']);
        self::assertSame('ok', $acces->verifierCode($session, $envoi['codeDev']));
        self::assertIsInt($session->get(AccesEnLigne::SESSION_ACTIVITE));
        self::assertSame('expire', $acces->verifierCode($session, $envoi['codeDev']));
    }

    public function testCinqCodesFauxBloquentLeCode(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $code = $acces->envoyerCode($this->utilisateur(), $session, '10.0.0.1')['codeDev'];
        $faux = '000000' === $code ? '111111' : '000000';

        for ($i = 1; $i < AccesEnLigne::ESSAIS_MAX; ++$i) {
            self::assertSame('faux', $acces->verifierCode($session, $faux));
        }
        self::assertSame('trop_essais', $acces->verifierCode($session, $faux));
        self::assertSame('trop_essais', $acces->verifierCode($session, $code));
    }

    public function testLeCodeNEstJamaisAfficheEnProduction(): void
    {
        $envoi = $this->acces('prod')->envoyerCode($this->utilisateur(), new Session(new MockArraySessionStorage()), '10.0.0.1');

        self::assertNull($envoi['codeDev']);
    }

    public function testRenvoiApresUneMinute(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $acces->envoyerCode($this->utilisateur(), $session, '10.0.0.1');

        self::assertGreaterThan(55, $acces->secondesAvantRenvoi($session));
    }

    public function testCodeDuPersonnelParEmailALAdresseDeSaFicheEmploye(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $envoi = $acces->envoyerCode($this->utilisateur('Kadiatou@SuccesImprim.ml'), $session, '10.0.0.1');

        self::assertTrue($envoi['envoye']);
        self::assertSame('email', $envoi['canal']);
        self::assertSame('k•••@s•••.ml', $envoi['destination']);
        self::assertNull($envoi['codeDev']);
        self::assertSame('kadiatou@succesimprim.ml', $this->fabrique->emails[0]->getTo()[0]->getAddress());
        self::assertSame('Votre code de sécurité Succès Imprim', $this->fabrique->emails[0]->getSubject());
        preg_match('/^\s+(\d{6})$/m', (string) $this->fabrique->emails[0]->getTextBody(), $m);
        self::assertSame('ok', $acces->verifierCode($session, $m[1]));
    }

    public function testTropDeCodesLaPageDuCodeResteOuverte(): void
    {
        $acces = $this->acces();
        for ($i = 0; $i < 3; ++$i) {
            $acces->envoyerCode($this->utilisateur('kadiatou@succesimprim.ml'), new Session(new MockArraySessionStorage()), '10.0.0.'.$i);
        }
        $session = new Session(new MockArraySessionStorage());
        $envoi = $acces->envoyerCode($this->utilisateur('kadiatou@succesimprim.ml'), $session, '10.0.0.9');

        self::assertTrue($envoi['limite']);
        self::assertCount(3, $this->fabrique->emails);
        // Sinon la page du code renverrait à l'accueil, qui en redemanderait un, sans fin.
        self::assertTrue($acces->codeEnAttente($session));
    }

    public function testRoleLecturePeutSeulementConsulter(): void
    {
        AccesEnLigneSubscriber::controlerRole(AppareilAutorise::ROLE_LECTURE, 'app_commandes_index', Request::create('/commandes'));

        $this->expectException(AccessDeniedHttpException::class);
        AccesEnLigneSubscriber::controlerRole(AppareilAutorise::ROLE_LECTURE, 'app_commandes_new', Request::create('/commandes/new', 'POST'));
    }

    public function testRoleEncaissementPeutEncaisserEtLivrer(): void
    {
        AccesEnLigneSubscriber::controlerRole(AppareilAutorise::ROLE_ENCAISSEMENT, 'app_commandes_paiement', Request::create('/commandes/1/paiement', 'POST'));
        AccesEnLigneSubscriber::controlerRole(AppareilAutorise::ROLE_ENCAISSEMENT, 'app_livraisons_livrer', Request::create('/livraisons/1/livrer', 'POST'));

        $this->expectException(AccessDeniedHttpException::class);
        AccesEnLigneSubscriber::controlerRole(AppareilAutorise::ROLE_ENCAISSEMENT, 'app_commandes_edit', Request::create('/commandes/1/edit', 'POST'));
    }

    public function testValidationDesPaiementsSeulementEnLocal(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        AccesEnLigneSubscriber::controlerRole(AppareilAutorise::ROLE_ADMIN, 'app_paiements_valider', Request::create('/paiements/1/valider', 'POST'));
    }

    public function testInvitationExpireApresCinqMinutes(): void
    {
        $invitation = (new AppareilAutorise())->setExpireLe(new \DateTimeImmutable('2026-10-11 10:05:00'));

        self::assertTrue($invitation->invitationValide(new \DateTimeImmutable('2026-10-11 10:04:59')));
        self::assertFalse($invitation->invitationValide(new \DateTimeImmutable('2026-10-11 10:05:01')));
    }
}
