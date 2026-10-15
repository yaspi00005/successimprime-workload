<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\EntetesSecurite;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pose les en-têtes de sécurité (EntetesSecurite, docs/securite.md) sur
 * chaque réponse, au local comme en ligne : politique de contenu, cadre,
 * référent, fonctions du navigateur, type de contenu et, en https
 * seulement, HSTS. Un en-tête déjà posé par la page est gardé (par
 * exemple « Referrer-Policy: no-referrer » du suivi de facture).
 */
final class EntetesSecuriteSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Après les pages (0), avant la barre de débogage (-128), qui lit la politique.
        return [KernelEvents::RESPONSE => ['poser', -64]];
    }

    public function poser(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $requete = $event->getRequest();
        $reponse = $event->getResponse();
        $zone = EntetesSecurite::zone((string) $requete->attributes->get('_route', ''), $this->security->getUser() instanceof User);

        foreach (EntetesSecurite::entetes($reponse->headers->get('Content-Type'), $zone, $requete->isSecure()) as $nom => $valeur) {
            if (!$reponse->headers->has($nom)) {
                $reponse->headers->set($nom, $valeur);
            }
        }

        // La version de PHP n'a pas à être annoncée (expose_php du serveur).
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
