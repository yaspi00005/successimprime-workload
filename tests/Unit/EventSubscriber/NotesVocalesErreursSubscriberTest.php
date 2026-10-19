<?php

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\NotesVocalesErreursSubscriber;
use Doctrine\DBAL\Driver\Exception as ExceptionPilote;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class NotesVocalesErreursSubscriberTest extends TestCase
{
    private function abonne(bool $connecte = true): NotesVocalesErreursSubscriber
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($connecte ? new User() : null);

        return new NotesVocalesErreursSubscriber($security, new NullLogger());
    }

    private static function evenement(string $route, \Throwable $e): ExceptionEvent
    {
        $request = Request::create('/x', 'POST');
        $request->attributes->set('_route', $route);

        return new ExceptionEvent(self::createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $e);
    }

    public function testBaseNonMigreeDonneLaCommandeAuPersonnel(): void
    {
        $pilote = $this->createStub(ExceptionPilote::class);
        $evenement = self::evenement('app_messages_clients_vocale_commande', new \RuntimeException('x', 0, new InvalidFieldNameException($pilote, null)));
        $this->abonne()->surErreur($evenement);

        $reponse = $evenement->getResponse();
        self::assertNotNull($reponse);
        self::assertSame(500, $reponse->getStatusCode());
        self::assertStringContainsString('doctrine:migrations:migrate', (string) $reponse->getContent());
    }

    public function testLeClientNeVoitPasLeDetailTechnique(): void
    {
        $evenement = self::evenement('app_espace_message_vocale', new \RuntimeException('SQLSTATE secret'));
        $this->abonne()->surErreur($evenement);

        self::assertStringNotContainsString('SQLSTATE', (string) $evenement->getResponse()?->getContent());
    }

    public function testJetonExpireEtSessionExpiree(): void
    {
        $evenement = self::evenement('app_messages_clients_vocale', new AccessDeniedException('Jeton de sécurité invalide.'));
        $this->abonne()->surErreur($evenement);
        self::assertStringContainsString('rechargez la page', (string) $evenement->getResponse()?->getContent());

        $evenement = self::evenement('app_messages_clients_vocale', new AccessDeniedException());
        $this->abonne(false)->surErreur($evenement);
        self::assertSame(401, $evenement->getResponse()?->getStatusCode());
    }

    public function testIntrouvableEtAutresRoutesIgnorees(): void
    {
        $evenement = self::evenement('app_espace_message_vocale', new NotFoundHttpException());
        $this->abonne()->surErreur($evenement);
        self::assertSame(404, $evenement->getResponse()?->getStatusCode());

        $evenement = self::evenement('app_commandes_show', new \RuntimeException('x'));
        $this->abonne()->surErreur($evenement);
        self::assertNull($evenement->getResponse());
    }
}
