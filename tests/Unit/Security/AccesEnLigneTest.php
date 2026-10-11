<?php

namespace App\Tests\Unit\Security;

use App\Entity\AppareilAutorise;
use App\Entity\Employes;
use App\Entity\User;
use App\EventSubscriber\AccesEnLigneSubscriber;
use App\Repository\AppareilAutoriseRepository;
use App\Security\AccesEnLigne;
use App\Security\CodeParSms;
use App\Service\NotificationService;
use App\Service\OrangeSmsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AccesEnLigneTest extends TestCase
{
    private function acces(string $environnement = 'dev'): AccesEnLigne
    {
        // SMS non configuré : l'envoi échoue sans appel réseau.
        $sms = new OrangeSmsService(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), '', '', '', '');

        return new AccesEnLigne(
            true,
            $this->createStub(AppareilAutoriseRepository::class),
            $this->createStub(EntityManagerInterface::class),
            new CodeParSms('secret-de-test', $environnement, $sms),
            $this->createStub(NotificationService::class),
        );
    }

    private function utilisateur(): User
    {
        $employe = (new Employes())->setTelephone('76 12 42 12');

        return (new User())->setEmploye($employe);
    }

    public function testCodeJusteOuvreLaSessionUneSeuleFois(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $envoi = $acces->envoyerCode($this->utilisateur(), $session);

        self::assertFalse($envoi['envoye']);
        self::assertSame('+223 •• •• 42 12', $envoi['telephone']);
        self::assertMatchesRegularExpression('/^\d{6}$/', (string) $envoi['codeDev']);
        self::assertSame('ok', $acces->verifierCode($session, $envoi['codeDev']));
        self::assertIsInt($session->get(AccesEnLigne::SESSION_ACTIVITE));
        self::assertSame('expire', $acces->verifierCode($session, $envoi['codeDev']));
    }

    public function testCinqCodesFauxBloquentLeCode(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $code = $acces->envoyerCode($this->utilisateur(), $session)['codeDev'];
        $faux = '000000' === $code ? '111111' : '000000';

        for ($i = 1; $i < AccesEnLigne::ESSAIS_MAX; ++$i) {
            self::assertSame('faux', $acces->verifierCode($session, $faux));
        }
        self::assertSame('trop_essais', $acces->verifierCode($session, $faux));
        self::assertSame('trop_essais', $acces->verifierCode($session, $code));
    }

    public function testLeCodeNEstJamaisAfficheEnProduction(): void
    {
        $envoi = $this->acces('prod')->envoyerCode($this->utilisateur(), new Session(new MockArraySessionStorage()));

        self::assertNull($envoi['codeDev']);
    }

    public function testRenvoiApresUneMinute(): void
    {
        $acces = $this->acces();
        $session = new Session(new MockArraySessionStorage());
        $acces->envoyerCode($this->utilisateur(), $session);

        self::assertGreaterThan(55, $acces->secondesAvantRenvoi($session));
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
