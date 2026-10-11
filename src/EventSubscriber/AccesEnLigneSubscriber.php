<?php

namespace App\EventSubscriber;

use App\Entity\AppareilAutorise;
use App\Entity\User;
use App\Security\AccesEnLigne;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Administration en ligne (maquettes 38 et CX03), seulement quand
 * APP_EN_LIGNE=1 :
 *  - le mot de passe ne suffit pas : l'appareil doit être autorisé ;
 *  - après le mot de passe, un code reçu par SMS ;
 *  - session fermée après 15 minutes sans activité ;
 *  - le rôle en ligne de l'appareil limite ce qui peut être modifié.
 */
final class AccesEnLigneSubscriber implements EventSubscriberInterface
{
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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckPassportEvent::class => ['verifierAppareil', -10],
            LoginSuccessEvent::class => 'demanderCode',
            // Après le pare-feu (8), avant le changement de mot de passe obligatoire (-10).
            KernelEvents::REQUEST => ['controlerRequete', -5],
        ];
    }

    public function verifierAppareil(CheckPassportEvent $event): void
    {
        if (!$this->acces->estEnLigne()) {
            return;
        }
        $utilisateur = $event->getPassport()->getUser();
        $request = $this->requete();
        if (!$utilisateur instanceof User || null === $request) {
            return;
        }

        if (null === $this->acces->appareilAutorisePour($utilisateur, $request)) {
            $this->acces->noterRefus($utilisateur->getUserIdentifier(), $request);

            throw new CustomUserMessageAuthenticationException('Accès refusé depuis un appareil non enrôlé, même avec le bon mot de passe.');
        }
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
        $session->set('si_code_envoi', $this->acces->envoyerCode($utilisateur, $session));

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
                    $session->set('si_code_envoi', $this->acces->envoyerCode($utilisateur, $session));
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
