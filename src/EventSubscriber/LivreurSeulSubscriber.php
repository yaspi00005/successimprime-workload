<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Security\LivreurSeul;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\AccessMapInterface;

/**
 * Livreurs (docs/documents-livraisons.md, « Écrans du livreur ») :
 *
 * - un livreur seul (LivreurSeul) n'ouvre que sa tournée, ses livraisons
 *   et ce que tout le personnel a pour lui-même ; toute autre page de
 *   l'admin lui répond « accès refusé », avant le contrôleur ;
 * - sur le site en ligne (site public et espace client), les écrans du
 *   livreur et le suivi des livraisons n'existent pas : ERP local seulement.
 */
final class LivreurSeulSubscriber implements EventSubscriberInterface
{
    /** Écrans du livreur et suivi des livraisons, fermés en ligne. */
    public const ROUTES_LOCALES = ['app_livraisons_tournee', 'app_livraisons_remise', 'app_livraisons_mes_livraisons', 'app_livraisons_suivi'];

    public function __construct(
        private readonly Security $security,
        private readonly AccesEnLigne $acces,
        #[Autowire(service: 'security.access_map')] private readonly AccessMapInterface $carte,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [
            // Après le routeur (32), avant le pare-feu (8) : en ligne, ces adresses n'existent pas.
            ['fermerEnLigne', 30],
            // Après le pare-feu (8) : la personne est connue.
            ['limiter', 0],
        ]];
    }

    public function fermerEnLigne(RequestEvent $event): void
    {
        if ($event->isMainRequest() && $this->acces->estEnLigne() && self::estLocale((string) $event->getRequest()->attributes->get('_route', ''))) {
            throw new NotFoundHttpException();
        }
    }

    public function limiter(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $requete = $event->getRequest();
        $route = (string) $requete->attributes->get('_route', '');
        $moi = $this->security->getUser();
        if ('' === $route || !$moi instanceof User || !LivreurSeul::est($moi->getRoles()) || LivreurSeul::routePermise($route)) {
            return;
        }
        // Pages publiques (site, liens signés, écran de l'accueil) : comme pour un visiteur.
        [$roles] = $this->carte->getPatterns($requete);
        if (\in_array('PUBLIC_ACCESS', (array) $roles, true)) {
            return;
        }

        throw new AccessDeniedHttpException('Votre accès est limité à votre tournée et à vos livraisons.');
    }

    public static function estLocale(string $route): bool
    {
        foreach (self::ROUTES_LOCALES as $prefixe) {
            if (str_starts_with($route, $prefixe)) {
                return true;
            }
        }

        return false;
    }
}
