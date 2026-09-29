<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;


final class ActiveUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }


    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                'onKernelRequest',
                0,
            ],
        ];
    }


    public function onKernelRequest(
        RequestEvent $event
    ): void {

        /*
         * ============================================================
         * REQUÊTE PRINCIPALE UNIQUEMENT
         * ============================================================
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
         * ============================================================
         * ROUTES TECHNIQUES
         * ============================================================
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
         * ============================================================
         * PAGE LOGIN / LOGOUT
         * ============================================================
         *
         * On ne veut surtout pas provoquer
         * une boucle de redirection.
         * ============================================================
         */
        if (
            in_array(
                $route,
                [
                    'app_login',
                    'app_logout',
                ],
                true
            )
        ) {
            return;
        }


        /*
         * ============================================================
         * UTILISATEUR DE LA SESSION
         * ============================================================
         */
        $sessionUser =
            $this->security->getUser();


        if (!$sessionUser instanceof User) {
            return;
        }


        /*
         * ============================================================
         * RECHARGEMENT DEPUIS LA BASE
         * ============================================================
         *
         * Important :
         *
         * on ne fait pas uniquement confiance
         * à l'objet User conservé dans la session.
         *
         * On vérifie l'état actuel du compte en BDD.
         * ============================================================
         */
        $user =
            $this->userRepository->find(
                $sessionUser->getId()
            );


        /*
         * Compte supprimé ou introuvable.
         */
        if (!$user instanceof User) {

            $this->deconnecter(
                $event,
                'Votre compte utilisateur est introuvable.'
            );

            return;
        }


        /*
         * ============================================================
         * COMPTE TOUJOURS ACTIF
         * ============================================================
         */
        if ($user->isActif()) {
            return;
        }


        /*
         * ============================================================
         * COMPTE DÉSACTIVÉ
         * ============================================================
         *
         * Déconnexion immédiate au prochain appel HTTP.
         * ============================================================
         */
        $this->deconnecter(
            $event,
            'Votre compte a été désactivé. Veuillez contacter l’administrateur.'
        );
    }


    /*
     * ================================================================
     * DÉCONNEXION FORCÉE
     * ================================================================
     */
    private function deconnecter(
    RequestEvent $event,
    string $message
): void {

    $request =
        $event->getRequest();


    /*
     * Supprime l'authentification.
     */
    $this->tokenStorage
        ->setToken(
            null
        );


    /*
     * Détruit l'ancienne session.
     */
    if ($request->hasSession()) {

        $request
            ->getSession()
            ->invalidate();
    }


    /*
     * Nouvelle session + message.
     */
    $request
        ->getSession()
        ->getFlashBag()
        ->add(
            'error',
            $message
        );


    $event->setResponse(
        new RedirectResponse(
            $this->urlGenerator
                ->generate(
                    'app_login'
                )
        )
    );
}
}