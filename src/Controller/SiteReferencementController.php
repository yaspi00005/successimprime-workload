<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\Site\AdresseSite;
use App\Service\Site\PlanDuSite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Référencement du site vitrine (docs/referencement.md) : plan du site et
 * consignes aux robots. L'ERP local ne doit pas être indexé : tout y est
 * interdit aux robots.
 */
final class SiteReferencementController extends AbstractController
{
    /**
     * Espaces privés du serveur en ligne, fermés aux robots : espace client,
     * connexions, API, liens privés (factures, avis, Orange Money), écrans de
     * l'atelier, administration, recherche, réponses en JSON du calculateur
     * et du studio textile. Préfixes : « /atelier/ » (administration) ne
     * ferme pas « /atelier-3d » (studio textile). Les autres pages de
     * l'administration portent « noindex » (ReferencementSubscriber).
     */
    public const INTERDITS = [
        '/espace', '/login', '/api', '/verification', '/appareil', '/acces', '/f/', '/om/',
        '/avis/', '/assistant/', '/tablette', '/ecran-accueil', '/recherche', '/reglages',
        '/atelier/', '/calculateur/estimation', '/atelier-3d/', '/changer-utilisateur', '/lockscreen',
    ];

    public function __construct(
        private readonly AccesEnLigne $acces,
        private readonly AdresseSite $adresse,
    ) {
    }

    #[Route('/robots.txt', name: 'app_site_robots', methods: ['GET'])]
    public function robots(): Response
    {
        if (!$this->acces->estEnLigne()) {
            return $this->texte("User-agent: *\nDisallow: /\n", 'text/plain');
        }
        $lignes = ['User-agent: *', 'Allow: /'];
        foreach (self::INTERDITS as $chemin) {
            $lignes[] = 'Disallow: '.$chemin;
        }
        $lignes[] = '';
        $lignes[] = 'Sitemap: '.$this->adresse->url($this->generateUrl('app_site_plan'));

        return $this->texte(implode("\n", $lignes)."\n", 'text/plain');
    }

    #[Route('/sitemap.xml', name: 'app_site_plan', methods: ['GET'])]
    public function plan(PlanDuSite $plan): Response
    {
        if (!$this->acces->estEnLigne()) {
            throw new NotFoundHttpException();
        }

        return $this->texte($this->renderView('site/sitemap.xml.twig', ['pages' => $plan->pages()]), 'application/xml');
    }

    private function texte(string $contenu, string $type): Response
    {
        $reponse = new Response($contenu, 200, ['Content-Type' => $type.'; charset=UTF-8']);
        $reponse->setPublic()->setMaxAge(3600);
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $reponse;
    }
}
