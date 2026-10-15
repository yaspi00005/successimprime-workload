<?php

namespace App\Tests\Unit\Security;

use App\Controller\AideController;
use App\Entity\Employes;
use App\Entity\User;
use App\EventSubscriber\AideHorsLigneSubscriber;
use App\Repository\AppareilAutoriseRepository;
use App\Security\AccesEnLigne;
use App\Security\PersonnelVoter;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Guide utilisateur : réservé aux personnes connectées de l'ERP local,
 * fiches « Mon espace » seulement avec une fiche employé, Fiches du
 * formateur à la Direction.
 */
final class AideAccesTest extends TestCase
{
    private function acces(bool $enLigne): AccesEnLigne
    {
        return new AccesEnLigne(
            $enLigne,
            $this->createStub(AppareilAutoriseRepository::class),
            $this->createStub(EntityManagerInterface::class),
            (new FabriqueCodes())->codes('dev'),
            $this->createStub(NotificationService::class),
        );
    }

    private function evenement(string $route, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $requete = Request::create('/aide');
        $requete->attributes->set('_route', $route);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $requete, $type);
    }

    public function testEnLigneLeGuideNExistePas(): void
    {
        $abonne = new AideHorsLigneSubscriber($this->acces(true));

        foreach (['app_aide_index', 'app_aide_fiche', 'app_aide_capture', 'app_aide_imprimer'] as $route) {
            try {
                $abonne->fermer($this->evenement($route));
                self::fail($route.' répond en ligne');
            } catch (NotFoundHttpException) {
                self::addToAssertionCount(1);
            }
        }
        // Les autres pages ne sont pas touchées.
        $abonne->fermer($this->evenement('app_home'));
        $abonne->fermer($this->evenement('app_aide_index', HttpKernelInterface::SUB_REQUEST));
    }

    public function testEnLocalLeGuideRepond(): void
    {
        $abonne = new AideHorsLigneSubscriber($this->acces(false));
        $abonne->fermer($this->evenement('app_aide_fiche'));
        self::assertArrayHasKey('kernel.request', AideHorsLigneSubscriber::getSubscribedEvents());
        // Après le routeur (32), pour connaître la route, avant le pare-feu (8).
        self::assertSame(['fermer', 30], AideHorsLigneSubscriber::getSubscribedEvents()['kernel.request']);
    }

    public function testPersonnelDemandeUneFicheEmploye(): void
    {
        $voter = new PersonnelVoter();
        $avec = (new User())->setEmploye(new Employes());
        $sans = new User();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($avec, 'main', ['ROLE_USER']), null, [PersonnelVoter::PERSONNEL]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken($sans, 'main', ['ROLE_USER']), null, [PersonnelVoter::PERSONNEL]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($avec, 'main', ['ROLE_USER']), null, ['ROLE_RH']));
    }

    public function testDroitsDuControleur(): void
    {
        $classe = new \ReflectionClass(AideController::class);
        $droits = static fn (\ReflectionClass|\ReflectionMethod $r): array => array_map(static fn (\ReflectionAttribute $a): mixed => $a->newInstance()->attribute, $r->getAttributes(IsGranted::class));

        self::assertSame(['ROLE_USER'], $droits($classe));
        // Fiche, capture et impression : droits de chaque fiche, vérifiés dans la méthode.
        foreach (['fiche', 'capture', 'imprimer', 'index'] as $methode) {
            self::assertSame([], $droits($classe->getMethod($methode)), $methode);
        }
        $code = (string) file_get_contents((string) $classe->getFileName());
        self::assertStringContainsString('createAccessDeniedException(\'Cette fiche', $code);
        self::assertStringContainsString('createAccessDeniedException(\'Cette image', $code);
    }
}
