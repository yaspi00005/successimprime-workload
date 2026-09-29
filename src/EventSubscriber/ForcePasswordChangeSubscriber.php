<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


final class ForcePasswordChangeSubscriber implements EventSubscriberInterface
{
    /*
     * ============================================================
     * ROUTES AUTORISÉES PENDANT LE CHANGEMENT OBLIGATOIRE
     * ============================================================
     */
    private const ROUTES_AUTORISEES = [
        'app_user_change_password',
        'app_logout',
    ];


    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }


    /*
     * ============================================================
     * ÉVÉNEMENTS
     * ============================================================
     */
   public static function getSubscribedEvents(): array
{
    return [
        /*
         * Priorité négative volontaire :
         *
         * - le routeur Symfony a déjà identifié la route ;
         * - le firewall a déjà chargé l'utilisateur ;
         * - nous pouvons donc contrôler mustChangePassword.
         */
        KernelEvents::REQUEST => [
            'onKernelRequest',
            -10,
        ],
    ];
}


    /*
     * ============================================================
     * CONTRÔLE DE CHAQUE REQUÊTE
     * ============================================================
     */
    public function onKernelRequest(
        RequestEvent $event
    ): void {

        /*
         * Requête principale uniquement.
         */
        if (!$event->isMainRequest()) {
            return;
        }


        $request =
            $event->getRequest();


        $route =
            (string) $request
                ->attributes
                ->get(
                    '_route',
                    ''
                );


        /*
         * ========================================================
         * ROUTES TECHNIQUES
         * ========================================================
         */
        if (
            $route === ''
            ||
            str_starts_with(
                $route,
                '_profiler'
            )
            ||
            str_starts_with(
                $route,
                '_wdt'
            )
        ) {
            return;
        }


        /*
         * ========================================================
         * UTILISATEUR DE LA SESSION
         * ========================================================
         */
        $sessionUser =
            $this->security->getUser();


        if (!$sessionUser instanceof User) {
            return;
        }


        /*
         * ========================================================
         * RECHARGEMENT DEPUIS LA BASE DE DONNÉES
         * ========================================================
         *
         * Très important :
         *
         * on vérifie la valeur actuelle de
         * mustChangePassword directement en BDD.
         * ========================================================
         */
        $user =
            $this->userRepository->find(
                $sessionUser->getId()
            );


        if (!$user instanceof User) {
            return;
        }


        /*
         * ========================================================
         * AUCUN CHANGEMENT OBLIGATOIRE
         * ========================================================
         */
        if (!$user->mustChangePassword()) {
            return;
        }


        /*
         * ========================================================
         * ROUTES AUTORISÉES
         * ========================================================
         */
        if (
            in_array(
                $route,
                self::ROUTES_AUTORISEES,
                true
            )
        ) {
            return;
        }


        /*
         * ========================================================
         * REDIRECTION OBLIGATOIRE
         * ========================================================
         */
        $event->setResponse(
            new RedirectResponse(
                $this->urlGenerator->generate(
                    'app_user_change_password'
                )
            )
        );
    }
}