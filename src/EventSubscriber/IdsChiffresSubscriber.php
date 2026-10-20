<?php

namespace App\EventSubscriber;

use App\Routing\CodeId;
use App\Routing\IdsChiffres;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Déchiffre les numéros des fiches à l'arrivée (docs/ids-chiffres.md) :
 * juste après le routeur, « k3XpQ9aZt » redevient 4217 dans la route et
 * dans « ?commande= », avant que le contrôleur ne charge sa fiche. Un code
 * faux ou fabriqué donne « page introuvable ».
 *
 * Les anciens liens à numéro (favoris, QR imprimés, messages déjà
 * envoyés) : une page ouverte avec un numéro est renvoyée vers la même
 * page avec le code, tant que ID_CHIFFRES_ANCIENS_LIENS vaut 1. À 0, le
 * numéro dans le chemin donne « page introuvable ».
 */
final class IdsChiffresSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CodeId $codes,
        private readonly UrlGeneratorInterface $routeur,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:ID_CHIFFRES_ANCIENS_LIENS)%')] private readonly bool $anciensLiens = true,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Le routeur (RouterListener) est à 32 : on passe juste après.
        return [KernelEvents::REQUEST => ['dechiffrer', 31]];
    }

    public function dechiffrer(RequestEvent $event): void
    {
        $requete = $event->getRequest();
        $route = $requete->attributes->get('_route');
        if (!IdsChiffres::routeConcernee(\is_string($route) ? $route : null)) {
            return;
        }
        $anciensLiens = $this->anciensLiens;
        $numerosVus = false;

        $parametres = (array) $requete->attributes->get('_route_params', []);
        foreach ($parametres as $cle => $valeur) {
            if (!isset(IdsChiffres::NOMS[$cle]) || !\is_string($valeur)) {
                continue;
            }
            if (CodeId::estCode($valeur)) {
                $id = $this->codes->decoder($valeur) ?? throw new NotFoundHttpException('Lien invalide.');
                $parametres[$cle] = (string) $id;
                $requete->attributes->set($cle, (string) $id);
            } elseif (null !== IdsChiffres::numero($valeur)) {
                if (!$anciensLiens) {
                    throw new NotFoundHttpException('Lien invalide.');
                }
                $numerosVus = true;
            }
        }
        $requete->attributes->set('_route_params', $parametres);

        foreach ($requete->query->all() as $cle => $valeur) {
            if (!isset(IdsChiffres::NOMS[$cle]) || !\is_string($valeur)) {
                continue;
            }
            if (CodeId::estCode($valeur)) {
                $id = $this->codes->decoder($valeur);
                $requete->query->set($cle, null !== $id ? (string) $id : '');
            } elseif (null !== IdsChiffres::numero($valeur)) {
                $numerosVus = true;
            }
        }

        // Une page ouverte avec un numéro lisible : la même page, avec le code.
        if ($numerosVus && $event->isMainRequest() && $requete->isMethodSafe() && !$requete->isXmlHttpRequest()) {
            $cible = $this->routeur->generate((string) $route, array_merge($requete->query->all(), array_filter($parametres, static fn ($cle): bool => !str_starts_with((string) $cle, '_'), \ARRAY_FILTER_USE_KEY)));
            $this->logger->info('Lien avec un numéro lisible renvoyé vers le code chiffré.', ['route' => $route, 'chemin' => $requete->getPathInfo()]);
            $event->setResponse(new RedirectResponse($cible, 302));
        }
    }
}
