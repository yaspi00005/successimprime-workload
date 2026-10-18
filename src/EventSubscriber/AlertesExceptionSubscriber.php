<?php

namespace App\EventSubscriber;

use App\Entity\AlerteSysteme;
use App\Service\Alertes\AlertesSysteme;
use App\Service\Alertes\NettoyageAlerte;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Erreur 500 sur le site public, l'espace client, l'administration ou
 * l'API (docs/alertes-systeme.md) : une alerte système « Erreur du site »,
 * une par panne (même erreur au même endroit = le compteur monte).
 *
 * Les pages introuvables, refus d'accès et autres erreurs 4xx ne sont pas
 * des pannes. Le titre ne dit que la zone, la route et la classe de
 * l'erreur ; le détail (message nettoyé, fichier, pile d'appels, adresse
 * sans ses paramètres) reste sur l'écran. La page d'erreur est servie
 * comme avant : rien ici ne change la réponse.
 */
final class AlertesExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AlertesSysteme $alertes,
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Avant l'écouteur d'erreur de Symfony (-128), après ceux qui changent l'exception.
        return [KernelEvents::EXCEPTION => ['signaler', -64]];
    }

    public function signaler(ExceptionEvent $event): void
    {
        $erreur = $event->getThrowable();
        if (!self::estUnePanne($erreur) || !$this->alertes->estActif()) {
            return;
        }
        $requete = $event->getRequest();
        $chemin = $requete->getPathInfo();
        $route = (string) ($requete->attributes->get('_route') ?? '');
        $zone = NettoyageAlerte::zone($chemin);
        $statut = $erreur instanceof HttpExceptionInterface ? $erreur->getStatusCode() : 500;
        $source = $erreur;
        while (null !== $source->getPrevious()) {
            $source = $source->getPrevious();
        }

        $this->alertes->signaler(
            AlerteSysteme::ERREUR_SERVEUR,
            \sprintf('Erreur %d · %s · %s · %s', $statut, $zone, '' !== $route ? $route : $chemin, (new \ReflectionClass($source))->getShortName()),
            NettoyageAlerte::detailErreur($erreur, $this->dossierProjet, [
                'Zone' => $zone,
                'Page' => $requete->getMethod().' '.$chemin,
                'Route' => $route,
                'Serveur' => $this->alertes->estEnLigne() ? 'site en ligne' : 'ERP de l\'imprimerie',
                'Navigateur' => mb_substr((string) $requete->headers->get('User-Agent', ''), 0, 160),
            ]),
            [$source::class, NettoyageAlerte::chemin($source->getFile(), $this->dossierProjet), (string) $source->getLine(), $route],
        );
    }

    /** Erreur 500 (ou plus), pas une page introuvable ni un refus d'accès. */
    public static function estUnePanne(\Throwable $erreur): bool
    {
        if ($erreur instanceof AccessDeniedException || $erreur instanceof AuthenticationException) {
            return false;
        }

        return !$erreur instanceof HttpExceptionInterface || $erreur->getStatusCode() >= 500;
    }
}
