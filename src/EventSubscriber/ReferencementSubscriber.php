<?php

namespace App\EventSubscriber;

use App\Security\AccesEnLigne;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pages que les moteurs de recherche ne doivent pas garder
 * (docs/referencement.md), par l'en-tête X-Robots-Tag, qui vaut aussi
 * pour un PDF, une image ou une réponse JSON :
 *  - tout l'ERP local ;
 *  - en ligne, tout ce qui n'est pas une page publique du site vitrine :
 *    espace client, connexion, administration, recherche, API, réponses
 *    des formulaires, suivi de commande.
 *
 * Une page qui a déjà posé son en-tête le garde. Ce n'est pas un en-tête
 * de sécurité : rien n'est fermé, la page dit seulement « ne m'indexe pas ».
 */
final class ReferencementSubscriber implements EventSubscriberInterface
{
    public const EN_TETE = 'X-Robots-Tag';
    public const VALEUR = 'noindex, nofollow';

    /** Pages du site vitrine faites pour être trouvées (le reste du site en ligne ne l'est pas). */
    public const INDEXABLES = [
        'app_home', 'app_site_produits', 'app_site_calculateur', 'app_site_atelier', 'app_site_realisations',
        'app_site_equipements', 'app_site_guide', 'app_site_a_propos', 'app_site_engagements', 'app_site_faq',
        'app_site_conseils', 'app_site_conseil', 'app_site_contact', 'app_site_devis', 'app_site_venir',
        // Introuvable tant qu'aucune action n'est montrée (ActionsSociales).
        'app_site_actions_sociales',
        // Fichiers pour les robots eux-mêmes.
        'app_site_robots', 'app_site_plan',
    ];

    public function __construct(private readonly AccesEnLigne $acces)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['marquer', -10]];
    }

    public function marquer(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $reponse = $event->getResponse();
        if ($reponse->headers->has(self::EN_TETE)) {
            return;
        }
        $route = (string) $event->getRequest()->attributes->get('_route', '');
        if (!self::indexable($this->acces->estEnLigne(), $route)) {
            $reponse->headers->set(self::EN_TETE, self::VALEUR);
        }
    }

    /** Une réponse que les moteurs peuvent garder : en ligne, une page publique du site vitrine. */
    public static function indexable(bool $enLigne, string $route): bool
    {
        return $enLigne && \in_array($route, self::INDEXABLES, true);
    }
}
