<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\LastUserCookieService;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;


final class LastUserCookieSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly LastUserCookieService $lastUserCookieService
    ) {
    }


    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => [
                'onKernelResponse',
                0,
            ],
        ];
    }


    public function onKernelResponse(
        ResponseEvent $event
    ): void {

        /*
         * ============================================================
         * REQUÊTE PRINCIPALE UNIQUEMENT
         * ============================================================
         */
        if (!$event->isMainRequest()) {
            return;
        }


        /*
         * ============================================================
         * UTILISATEUR CONNECTÉ
         * ============================================================
         */
        $user =
            $this->security->getUser();


        if (!$user instanceof User) {
            return;
        }


        /*
         * Ne pas mémoriser un compte désactivé.
         */
        if (!$user->isActif()) {
            return;
        }


        $request =
            $event->getRequest();


        /*
         * ============================================================
         * VALEUR DU COOKIE SIGNÉ
         * ============================================================
         */
        $cookieValue =
            $this
                ->lastUserCookieService
                ->createValue(
                    $user
                );


        /*
         * ============================================================
         * COOKIE DÉJÀ CORRECT
         * ============================================================
         *
         * Évite de renvoyer Set-Cookie à chaque requête.
         * ============================================================
         */
        if (
            $request
                ->cookies
                ->get(
                    LastUserCookieService::COOKIE_NAME
                )
            ===
            $cookieValue
        ) {
            return;
        }


        /*
         * ============================================================
         * CRÉATION COOKIE
         * ============================================================
         *
         * Le cookie contient uniquement :
         *
         * - l'identifiant signé du User ;
         * - jamais son password ;
         * - jamais son hash ;
         * - jamais ses rôles.
         * ============================================================
         */
        $cookie =
            Cookie::create(
                LastUserCookieService::COOKIE_NAME
            )
                ->withValue(
                    $cookieValue
                )
                ->withExpires(
                    new \DateTimeImmutable(
                        '+90 days'
                    )
                )
                ->withPath(
                    '/'
                )
                ->withHttpOnly(
                    true
                )
                ->withSecure(
                    $request->isSecure()
                )
                ->withSameSite(
                    Cookie::SAMESITE_LAX
                );


        /*
         * ============================================================
         * ENVOI DU COOKIE
         * ============================================================
         */
        $event
            ->getResponse()
            ->headers
            ->setCookie(
                $cookie
            );
    }
}