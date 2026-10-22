<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\ModulesRoles;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Module en « Voir » (Reglages › Roles et menus, maquette R04) : la personne
 * ouvre les listes et les fiches du module, mais tout envoi de formulaire
 * et tout ecran de creation ou de modification lui est refuse.
 */
final class LectureSeuleSubscriber implements EventSubscriberInterface
{
    /** Ecrans qui ne servent qu'a changer quelque chose, meme en GET. */
    private const ECRANS_ECRITURE = '/_(new|nouveau|nouvelle|edit|edit_form|formulaire|modifier|supprimer|delete|annuler|dupliquer|creer|ajouter|import|importer)$/';

    public function __construct(private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Apres le pare-feu (8) : l'utilisateur est connu.
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $requete = $event->getRequest();
        $route = (string) $requete->attributes->get('_route', '');
        $utilisateur = $this->security->getUser();
        if ('' === $route || !$utilisateur instanceof User || $this->security->isGranted(User::ROLE_ADMIN)) {
            return;
        }

        $module = ModulesRoles::moduleEnLecture($route, $utilisateur->getRoles());
        if (null === $module || !self::ecrit($requete->getMethod(), $route)) {
            return;
        }

        throw new AccessDeniedHttpException(\sprintf('Le module « %s » est en lecture seule pour votre rôle.', ModulesRoles::catalogue()[$module]['libelle']));
    }

    public static function ecrit(string $methode, string $route): bool
    {
        return !\in_array(strtoupper($methode), ['GET', 'HEAD', 'OPTIONS'], true) || 1 === preg_match(self::ECRANS_ECRITURE, $route);
    }
}
