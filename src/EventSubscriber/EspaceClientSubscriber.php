<?php

namespace App\EventSubscriber;

use App\Entity\CompteClient;
use App\Security\DroitsEspace;
use App\Security\EspaceClientComptes;
use App\Service\EspaceClient\AppareilsClient;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * À chaque page de l'espace client connecté :
 *  - une session ouverte avant « Déconnecter les autres appareils » est
 *    fermée (version de session du compte) ;
 *  - tant que le mot de passe n'est pas choisi (ou l'e-mail donné après un
 *    code d'accès de la boutique), seule « Sécuriser mon compte » s'ouvre ;
 *  - un compte « Lecture · suivi » ne peut rien envoyer ni ouvrir de page
 *    qui écrit (DroitsEspace) ;
 *  - l'appareil est noté (Mon compte › Sécurité).
 */
final class EspaceClientSubscriber implements EventSubscriberInterface
{
    /** Pages ouvertes tant que le compte n'est pas sécurisé. */
    private const ROUTES_A_FINIR = ['app_espace_securiser', 'app_espace_deconnexion'];

    private ?Cookie $cookie = null;

    public function __construct(
        private readonly Security $security,
        private readonly AppareilsClient $appareils,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'connexion',
            // Après le pare-feu (8) : la personne est connue.
            KernelEvents::REQUEST => ['requete', 0],
            KernelEvents::RESPONSE => 'reponse',
        ];
    }

    /** Connexion (code, mot de passe ou « rester connecté ») : la session prend la version du compte. */
    public function connexion(LoginSuccessEvent $event): void
    {
        $compte = $event->getUser();
        if ($compte instanceof CompteClient && $event->getRequest()->hasSession()) {
            $event->getRequest()->getSession()->set(AppareilsClient::SESSION_VERSION, $compte->getVersionSession());
        }
    }

    public function requete(RequestEvent $event): void
    {
        $requete = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($requete->getPathInfo(), '/espace')) {
            return;
        }
        $compte = $this->security->getUser();
        if (!$compte instanceof CompteClient) {
            return;
        }
        // Sans version notée (session d'avant cette règle) : la première.
        $version = $requete->hasSession() ? (int) $requete->getSession()->get(AppareilsClient::SESSION_VERSION, 1) : 1;
        if (AppareilsClient::sessionPerimee($compte, $version)) {
            $this->security->logout(false);
            $session = $requete->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', 'Vous avez été déconnecté de cet appareil depuis un autre. Reconnectez-vous.');
            }
            $event->setResponse(new RedirectResponse($this->urls->generate('app_espace_connexion')));

            return;
        }

        $route = (string) $requete->attributes->get('_route', '');
        if (!\in_array($route, self::ROUTES_A_FINIR, true)) {
            $par = $requete->hasSession() ? $requete->getSession()->get(EspaceClientComptes::SESSION_A_FINIR) : null;
            if (EspaceClientComptes::etapes($compte, \is_string($par) ? $par : null)['bloquant']) {
                $event->setResponse(new RedirectResponse($this->urls->generate('app_espace_securiser')));

                return;
            }
        }

        if (!DroitsEspace::permis($compte, $requete->getMethod(), $route)) {
            if ($requete->isMethod('GET') && $requete->hasSession() && $requete->getSession() instanceof FlashBagAwareSessionInterface) {
                $requete->getSession()->getFlashBag()->add('error', 'Votre accès est en lecture seule : vous suivez les commandes, les livraisons et les fichiers.');
                $event->setResponse(new RedirectResponse($this->urls->generate('app_espace_accueil')));

                return;
            }
            throw new AccessDeniedHttpException('Accès en lecture seule.');
        }

        if ($requete->isMethod('GET') && !$requete->isXmlHttpRequest()) {
            try {
                $this->cookie = $this->appareils->vu($compte, $requete, new \DateTimeImmutable());
            } catch (\Throwable $e) {
                // Le suivi des appareils ne doit jamais empêcher d'ouvrir une page.
                $this->logger->warning('Appareil du client non noté : {erreur}', ['erreur' => $e->getMessage()]);
            }
        }
    }

    public function reponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && null !== $this->cookie) {
            $event->getResponse()->headers->setCookie($this->cookie);
            $this->cookie = null;
        }
    }
}
