<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\LastUserCookieService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


final class SecurityController extends AbstractController
{
    /*
     * ============================================================
     * LOGIN CLASSIQUE
     * ============================================================
     *
     * Premier accès :
     *
     * username + password
     *
     * Si le navigateur connaît déjà un utilisateur :
     *
     * → lockscreen
     * ============================================================
     */
    #[Route(
        path: '/login',
        name: 'app_login',
        methods: ['GET', 'POST']
    )]
    public function login(
        Request $request,
        AuthenticationUtils $authenticationUtils,
        UserRepository $userRepository,
        LastUserCookieService $lastUserCookieService
    ): Response {

        /*
         * ========================================================
         * UTILISATEUR DÉJÀ CONNECTÉ
         * ========================================================
         */
        if (
            $this->getUser()
            instanceof User
        ) {

            return $this->redirectionApresConnexion();
        }


        /*
         * ========================================================
         * GET UNIQUEMENT
         * ========================================================
         *
         * Le POST /login est normalement intercepté
         * par le FormLoginAuthenticator de Symfony.
         * ========================================================
         */
        if (
            $request->isMethod(
                'GET'
            )
        ) {

            $cookie =
                $request
                    ->cookies
                    ->get(
                        LastUserCookieService::COOKIE_NAME
                    );


            $userId =
                $lastUserCookieService
                    ->getUserId(
                        $cookie
                    );


            if ($userId !== null) {

                $knownUser =
                    $userRepository->find(
                        $userId
                    );


                /*
                 * Utilisateur connu ET actif :
                 *
                 * on ne demande plus son username.
                 */
                if (
                    $knownUser instanceof User
                    &&
                    $knownUser->isActif()
                ) {

                    return $this->redirectToRoute(
                        'app_lockscreen'
                    );
                }
            }
        }


        /*
         * ========================================================
         * LOGIN CLASSIQUE
         * ========================================================
         */

        $error =
            $authenticationUtils
                ->getLastAuthenticationError();


        $lastUsername =
            $authenticationUtils
                ->getLastUsername();


        $response =
            $this->render(
                'security/login.html.twig',
                [
                    'last_username' =>
                        $lastUsername,

                    'error' =>
                        $error,
                ]
            );


        /*
         * Cookie invalide / utilisateur supprimé :
         * nettoyage.
         */
        $cookie =
            $request
                ->cookies
                ->get(
                    LastUserCookieService::COOKIE_NAME
                );


        if (
            $cookie !== null
            &&
            $lastUserCookieService
                ->getUserId(
                    $cookie
                )
            === null
        ) {

            $response
                ->headers
                ->clearCookie(
                    LastUserCookieService::COOKIE_NAME,
                    '/'
                );
        }


        return $response;
    }


    /*
     * ============================================================
     * LOCKSCREEN
     * ============================================================
     */
    #[Route(
        path: '/lockscreen',
        name: 'app_lockscreen',
        methods: ['GET']
    )]
    public function lockscreen(
        Request $request,
        UserRepository $userRepository,
        LastUserCookieService $lastUserCookieService,
        AuthenticationUtils $authenticationUtils
    ): Response {

        /*
         * Déjà authentifié.
         */
        if (
            $this->getUser()
            instanceof User
        ) {

            return $this->redirectionApresConnexion();
        }


        /*
         * ========================================================
         * COOKIE
         * ========================================================
         */

        $cookie =
            $request
                ->cookies
                ->get(
                    LastUserCookieService::COOKIE_NAME
                );


        $userId =
            $lastUserCookieService
                ->getUserId(
                    $cookie
                );


        /*
         * Cookie absent ou invalide :
         * login complet.
         */
        if ($userId === null) {

            return $this->redirectToRoute(
                'app_login'
            );
        }


        $user =
            $userRepository->find(
                $userId
            );


        /*
         * Utilisateur supprimé/introuvable.
         */
        if (!$user instanceof User) {

            $response =
                $this->redirectToRoute(
                    'app_login'
                );


            $response
                ->headers
                ->clearCookie(
                    LastUserCookieService::COOKIE_NAME,
                    '/'
                );


            return $response;
        }


        /*
         * ========================================================
         * COMPTE DÉSACTIVÉ
         * ========================================================
         */

        if (!$user->isActif()) {

            $response =
                $this->redirectToRoute(
                    'app_login'
                );


            $response
                ->headers
                ->clearCookie(
                    LastUserCookieService::COOKIE_NAME,
                    '/'
                );


            $this->addFlash(
                'error',
                'Ce compte est désactivé. Veuillez contacter l’administrateur.'
            );


            return $response;
        }


        /*
         * ========================================================
         * ERREUR D'AUTHENTIFICATION
         * ========================================================
         *
         * Exemple :
         * mauvais mot de passe envoyé depuis le lockscreen.
         * ========================================================
         */

        $authenticationError =
            $authenticationUtils
                ->getLastAuthenticationError();


        return $this->render(
            'security/lockscreen.html.twig',
            [
                'user' =>
                    $user,

                'error' =>
                    $authenticationError
                        ? $authenticationError
                            ->getMessageKey()
                        : null,
            ]
        );
    }


    /*
     * ============================================================
     * CHANGER D'UTILISATEUR
     * ============================================================
     *
     * Supprime UNIQUEMENT la mémoire du dernier utilisateur.
     *
     * Aucun compte n'est supprimé.
     * ============================================================
     */
    #[Route(
        path: '/changer-utilisateur',
        name: 'app_switch_user_login',
        methods: ['GET']
    )]
    public function switchUser(): Response
    {
        $response =
            $this->redirectToRoute(
                'app_login'
            );


        $response
            ->headers
            ->clearCookie(
                LastUserCookieService::COOKIE_NAME,
                '/'
            );


        return $response;
    }


    /*
     * ============================================================
     * LOGOUT
     * ============================================================
     *
     * Le cookie "dernier utilisateur" n'est volontairement
     * PAS supprimé.
     *
     * Symfony détruit la session d'authentification,
     * mais le navigateur se souvient de l'identité.
     * ============================================================
     */
    #[Route(
        path: '/logout',
        name: 'app_logout'
    )]
    public function logout(): void
    {
        throw new \LogicException(
            'Cette méthode est interceptée par le firewall Symfony.'
        );
    }

    /*
     * ============================================================
     * REDIRECTION APRÈS CONNEXION (UTILISATEUR DÉJÀ AUTHENTIFIÉ)
     * ============================================================
     *
     * Un livreur revenant sur /login ou /lockscreen une fois
     * connecté doit atterrir directement sur les livraisons,
     * comme lors d'une connexion normale.
     * ============================================================
     */
    private function redirectionApresConnexion(): Response
    {
        $utilisateur = $this->getUser();

        if (
            $utilisateur instanceof User
            && $utilisateur->isLivreur()
            && !$utilisateur->isAdmin()
        ) {
            return $this->redirectToRoute('app_livraisons_index');
        }

        return $this->redirect('/');
    }
}