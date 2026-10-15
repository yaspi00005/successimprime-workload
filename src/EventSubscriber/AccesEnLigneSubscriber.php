<?php

namespace App\EventSubscriber;

use App\Entity\AppareilAutorise;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Security\UserAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Administration en ligne (maquettes 38 et CX03), seulement quand
 * APP_EN_LIGNE=1 :
 *  - le mot de passe ne suffit pas : l'appareil doit être autorisé ;
 *  - après le mot de passe, un code reçu par e-mail (SMS sans adresse) ;
 *  - session fermée après 15 minutes sans activité ;
 *  - le rôle en ligne de l'appareil limite ce qui peut être modifié.
 */
final class AccesEnLigneSubscriber implements EventSubscriberInterface
{
    /** Même réponse que le mot de passe soit juste ou faux. */
    public const MESSAGE_APPAREIL_INCONNU = 'Cet appareil n\'est pas autorisé pour l\'administration en ligne. Demandez à la direction de l\'autoriser.';

    /** Pages ouvertes pendant que le code est attendu. */
    private const ROUTES_PENDANT_CODE = ['app_verification', 'app_verification_renvoyer', 'app_logout'];

    /** Rôle « Encaissement » : seules ces actions peuvent enregistrer quelque chose. */
    private const PREFIXES_ENCAISSEMENT = [
        'app_commandes_paiement',
        'app_paiements_new',
        'app_livraisons_',
        'app_bons_livraison_livrer',
    ];

    /** Un encaissement saisi en ligne reste provisoire : il se valide sur l'ERP local. */
    private const ROUTES_LOCAL_SEULEMENT = ['app_paiements_valider'];

    public function __construct(
        private readonly AccesEnLigne $acces,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requetes,
        private readonly TokenStorageInterface $jetons,
        private readonly UserPasswordHasherInterface $hacheur,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Après le jeton du formulaire (512), avant le compte (256) et le mot de passe (0).
            CheckPassportEvent::class => ['verifierAppareil', 300],
            LoginSuccessEvent::class => 'demanderCode',
            // Après le pare-feu (8), avant le changement de mot de passe obligatoire (-10).
            KernelEvents::REQUEST => ['controlerRequete', -5],
        ];
    }

    /**
     * Appareil inconnu : refus avant la vérification du mot de passe, avec le
     * même message que le mot de passe soit juste ou faux. Sinon, depuis
     * n'importe quel ordinateur, la réponse dirait si le mot de passe essayé
     * est le bon (et l'identifiant celui d'un compte) sans jamais ouvrir de
     * session. Les administrateurs sont prévenus seulement quand le mot de
     * passe était juste, comme avant.
     */
    public function verifierAppareil(CheckPassportEvent $event): void
    {
        if (!$this->acces->estEnLigne()) {
            return;
        }
        $authentificateur = $event->getAuthenticator();
        if ($authentificateur instanceof TraceableAuthenticator) {
            $authentificateur = $authentificateur->getAuthenticator();
        }
        $request = $this->requete();
        if (!$authentificateur instanceof UserAuthenticator || null === $request) {
            return;
        }

        $passport = $event->getPassport();
        try {
            $utilisateur = $passport->getUser();
        } catch (AuthenticationException) {
            $utilisateur = null;
        }
        if ($utilisateur instanceof User && null !== $this->acces->appareilAutorisePour($utilisateur, $request)) {
            return;
        }

        if ($utilisateur instanceof User && $passport->hasBadge(PasswordCredentials::class)) {
            /** @var PasswordCredentials $motDePasse */
            $motDePasse = $passport->getBadge(PasswordCredentials::class);
            if ($this->hacheur->isPasswordValid($utilisateur, $motDePasse->getPassword())) {
                $this->acces->noterRefus($utilisateur->getUserIdentifier(), $request);
            }
        }

        throw new CustomUserMessageAuthenticationException(self::MESSAGE_APPAREIL_INCONNU);
    }

    public function demanderCode(LoginSuccessEvent $event): void
    {
        if (!$this->acces->estEnLigne()) {
            return;
        }
        $utilisateur = $event->getUser();
        $request = $event->getRequest();
        $appareil = $utilisateur instanceof User ? $this->acces->appareilAutorisePour($utilisateur, $request) : null;
        if (!$utilisateur instanceof User || null === $appareil) {
            return;
        }

        $session = $request->getSession();
        $session->set(AccesEnLigne::SESSION_APPAREIL, $appareil->getId());
        $session->remove(AccesEnLigne::SESSION_ACTIVITE);
        $session->set('si_code_envoi', $this->acces->envoyerCode($utilisateur, $session, (string) $request->getClientIp()));

        $appareil->setDerniereConnexion(new \DateTimeImmutable())->setIp($request->getClientIp());
        $this->em->flush();

        $event->setResponse(new RedirectResponse($this->urls->generate('app_verification')));
    }

    public function controlerRequete(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->acces->estEnLigne()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        $utilisateur = $this->security->getUser();
        if ('' === $route || str_starts_with($route, '_') || !$utilisateur instanceof User || !$request->hasSession()) {
            return;
        }
        $session = $request->getSession();

        // Appareil révoqué ou bloqué entre-temps : la session s'arrête tout de suite.
        $appareil = $this->acces->appareilAutorisePour($utilisateur, $request);
        if (null === $appareil || $appareil->getId() !== $session->get(AccesEnLigne::SESSION_APPAREIL)) {
            $event->setResponse($this->fermer($request, 'Cet appareil n\'est plus autorisé pour l\'administration en ligne.'));

            return;
        }

        $derniere = $session->get(AccesEnLigne::SESSION_ACTIVITE);
        if (!\is_int($derniere)) {
            // Code pas encore saisi (ou session ouverte avant la mise en ligne).
            if (!\in_array($route, self::ROUTES_PENDANT_CODE, true)) {
                if (!$this->acces->codeEnAttente($session)) {
                    $session->set('si_code_envoi', $this->acces->envoyerCode($utilisateur, $session, (string) $request->getClientIp()));
                }
                $event->setResponse(new RedirectResponse($this->urls->generate('app_verification')));
            }

            return;
        }

        if (time() - $derniere > AccesEnLigne::INACTIVITE_SECONDES) {
            $event->setResponse($this->fermer($request, 'Session fermée après 15 minutes sans activité. Reconnectez-vous.'));

            return;
        }
        // Les vérifications automatiques (notifications) ne comptent pas comme une activité.
        if ('app_notification_etat' !== $route) {
            $session->set(AccesEnLigne::SESSION_ACTIVITE, time());
        }

        self::controlerRole($appareil->getRoleEnLigne(), $route, $request);
    }

    /** @throws AccessDeniedHttpException */
    public static function controlerRole(string $role, string $route, Request $request): void
    {
        if ($request->isMethodSafe() || 'app_logout' === $route) {
            return;
        }
        if (\in_array($route, self::ROUTES_LOCAL_SEULEMENT, true)) {
            throw new AccessDeniedHttpException('Cette validation se fait sur l\'ERP local : en ligne, les encaissements restent provisoires.');
        }
        if (AppareilAutorise::ROLE_ADMIN === $role) {
            return;
        }
        if (AppareilAutorise::ROLE_ENCAISSEMENT === $role) {
            foreach (self::PREFIXES_ENCAISSEMENT as $prefixe) {
                if (str_starts_with($route, $prefixe)) {
                    return;
                }
            }
        }

        throw new AccessDeniedHttpException('Cet appareil est en « '.(AppareilAutorise::ROLES[$role] ?? $role).' » : cette modification n\'est pas permise en ligne.');
    }

    private function fermer(Request $request, string $message): RedirectResponse
    {
        // Sans retirer le jeton, le pare-feu le réécrirait dans la nouvelle session.
        $this->jetons->setToken(null);
        $session = $request->getSession();
        $session->invalidate();
        $session->getFlashBag()->add('error', $message);

        return new RedirectResponse($this->urls->generate('app_login'));
    }

    private function requete(): ?Request
    {
        return $this->requetes->getMainRequest();
    }
}
