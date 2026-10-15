<?php

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\EventSubscriber\LivreurSeulSubscriber;
use App\Repository\AppareilAutoriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Tests\Unit\Security\FabriqueCodes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Le livreur seul n'ouvre que sa tournée, ses livraisons et son espace,
 * même en tapant l'adresse ; en ligne, ces écrans n'existent pas.
 */
final class LivreurSeulSubscriberTest extends TestCase
{
    private function subscriber(?User $moi, bool $enLigne = false, array $rolesDeLaRegle = ['ROLE_USER']): LivreurSeulSubscriber
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($moi);
        $acces = new AccesEnLigne($enLigne, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), (new FabriqueCodes())->codes(), $this->createStub(NotificationService::class));
        $carte = $this->createStub(AccessMapInterface::class);
        $carte->method('getPatterns')->willReturn([$rolesDeLaRegle, null]);

        return new LivreurSeulSubscriber($security, $acces, $carte);
    }

    private function evenement(string $route, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $requete = Request::create('/x');
        $requete->attributes->set('_route', $route);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $requete, $type);
    }

    private static function utilisateur(array $droits): User
    {
        return (new User())->setProfil((new RoleUtilisateur())->setCode('ROLE_TEST')->setNom('Test')->setDroits($droits));
    }

    private function refuse(LivreurSeulSubscriber $s, string $route): bool
    {
        try {
            $s->limiter($this->evenement($route));

            return false;
        } catch (AccessDeniedHttpException) {
            return true;
        }
    }

    public function testLeLivreurSeulNOuvreQueSaTournee(): void
    {
        $s = $this->subscriber(self::utilisateur(['ROLE_LIVRAISON']));
        foreach (['app_livraisons_tournee', 'app_livraisons_tournee_echec', 'app_livraisons_remise', 'app_livraisons_mes_livraisons', 'app_mon_espace_index', 'app_pointer_index', 'app_home'] as $route) {
            self::assertFalse($this->refuse($s, $route), $route);
        }
        foreach (['app_clients_index', 'app_commandes_show', 'app_statistiques_index', 'app_recherche', 'app_livraisons_index', 'app_livraisons_a_retirer', 'app_livraisons_suivi', 'app_chat_index', 'app_mes_statistiques'] as $route) {
            self::assertTrue($this->refuse($s, $route), $route);
        }
    }

    public function testPagesPubliquesEtAutresPersonnes(): void
    {
        // Page publique (règle PUBLIC_ACCESS) : comme pour un visiteur.
        self::assertFalse($this->refuse($this->subscriber(self::utilisateur(['ROLE_LIVRAISON']), false, ['PUBLIC_ACCESS']), 'app_site_faq'));
        // Un livreur qui a un autre droit, la caisse, la Direction, un visiteur : rien ne change ici.
        self::assertFalse($this->refuse($this->subscriber(self::utilisateur(['ROLE_LIVRAISON', 'ROLE_CLIENT'])), 'app_clients_index'));
        self::assertFalse($this->refuse($this->subscriber(self::utilisateur(['ROLE_COMMANDE'])), 'app_commandes_show'));
        self::assertFalse($this->refuse($this->subscriber((new User())->setRoles(['ROLE_ADMIN'])), 'app_clients_index'));
        self::assertFalse($this->refuse($this->subscriber(null), 'app_clients_index'));
    }

    public function testSousRequeteIgnoree(): void
    {
        $this->subscriber(self::utilisateur(['ROLE_LIVRAISON']))->limiter($this->evenement('app_clients_index', HttpKernelInterface::SUB_REQUEST));
        $this->addToAssertionCount(1);
    }

    public function testEcransDuLivreurFermesEnLigne(): void
    {
        $enLigne = $this->subscriber(null, true);
        foreach (['app_livraisons_tournee', 'app_livraisons_tournee_echec', 'app_livraisons_remise', 'app_livraisons_mes_livraisons', 'app_livraisons_suivi'] as $route) {
            try {
                $enLigne->fermerEnLigne($this->evenement($route));
                self::fail($route.' doit répondre « introuvable » en ligne.');
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
        // Le reste des livraisons (bureau, appareil « Encaissement ») n'est pas touché ; au local, tout reste.
        $enLigne->fermerEnLigne($this->evenement('app_livraisons_bl'));
        $this->subscriber(null)->fermerEnLigne($this->evenement('app_livraisons_tournee'));
        self::assertFalse(LivreurSeulSubscriber::estLocale('app_livraisons_planning'));
    }
}
