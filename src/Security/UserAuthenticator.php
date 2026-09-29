<?php

namespace App\Security;

use App\Entity\User;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;

use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

use Symfony\Component\Security\Http\SecurityRequestAttributes;

use Symfony\Component\Security\Http\Util\TargetPathTrait;


final class UserAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;


    public const LOGIN_ROUTE =
        'app_login';


    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }


    /*
     * ============================================================
     * AUTHENTIFICATION
     * ============================================================
     *
     * Fonctionne aussi bien pour :
     *
     * - /login
     *   username + password
     *
     * - /lockscreen
     *   username caché + password
     *
     * Dans les deux cas, le formulaire POST est envoyé vers /login.
     * ============================================================
     */
    public function authenticate(
        Request $request
    ): Passport {

        /*
         * ========================================================
         * USERNAME
         * ========================================================
         */

        $username =
            trim(
                mb_strtolower(
                    $request
                        ->getPayload()
                        ->getString(
                            'username'
                        )
                )
            );


        /*
         * Symfony conserve le dernier username
         * pour le formulaire de connexion classique.
         */
        $request
            ->getSession()
            ->set(
                SecurityRequestAttributes::LAST_USERNAME,
                $username
            );


        /*
         * ========================================================
         * PASSWORD
         * ========================================================
         */

        $password =
            $request
                ->getPayload()
                ->getString(
                    'password'
                );


        /*
         * ========================================================
         * CSRF
         * ========================================================
         */

        $csrfToken =
            $request
                ->getPayload()
                ->getString(
                    '_csrf_token'
                );


        /*
         * ========================================================
         * PASSPORT
         * ========================================================
         */

        return new Passport(

            new UserBadge(
                $username
            ),

            new PasswordCredentials(
                $password
            ),

            [
                new CsrfTokenBadge(
                    'authenticate',
                    $csrfToken
                ),
            ]
        );
    }


    /*
     * ============================================================
     * CONNEXION RÉUSSIE
     * ============================================================
     */
    public function onAuthenticationSuccess(
        Request $request,
        TokenInterface $token,
        string $firewallName
    ): ?Response {

        /*
         * ========================================================
         * LIVREUR
         * ========================================================
         *
         * Un livreur n'a accès qu'à la livraison : il arrive
         * toujours directement sur cette page, sans tenir compte
         * d'une éventuelle page demandée avant la connexion.
         * ========================================================
         */

        $utilisateur = $token->getUser();

        if (
            $utilisateur instanceof User
            && $utilisateur->isLivreur()
            && !$utilisateur->isAdmin()
        ) {
            return new RedirectResponse(
                $this->urlGenerator->generate('app_livraisons_index')
            );
        }

        /*
         * ========================================================
         * PAGE DEMANDÉE AVANT LOGIN
         * ========================================================
         *
         * Exemple :
         *
         * utilisateur tente /commandes
         * → redirection login
         * → connexion réussie
         * → retour /commandes
         * ========================================================
         */

        $targetPath =
            $this->getTargetPath(
                $request->getSession(),
                $firewallName
            );


        if ($targetPath) {

            return new RedirectResponse(
                $targetPath
            );
        }


        /*
         * ========================================================
         * FALLBACK
         * ========================================================
         *
         * On évite ici de rediriger vers app_login,
         * sinon l'utilisateur authentifié revient sur /login
         * inutilement.
         *
         * "/" permet de revenir à l'accueil de l'ERP
         * sans dépendre d'un nom de route particulier.
         * ========================================================
         */

        return new RedirectResponse(
            '/'
        );
    }


    /*
     * ============================================================
     * URL DE CONNEXION
     * ============================================================
     */
    protected function getLoginUrl(
        Request $request
    ): string {

        return $this
            ->urlGenerator
            ->generate(
                self::LOGIN_ROUTE
            );
    }
}