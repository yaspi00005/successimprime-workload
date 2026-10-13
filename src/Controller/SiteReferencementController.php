<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\Site\PlanDuSite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Référencement du site vitrine : plan du site et consignes aux robots.
 * L'ERP local ne doit pas être indexé : tout y est interdit aux robots.
 */
final class SiteReferencementController extends AbstractController
{
    /** Espaces privés du serveur en ligne, fermés aux robots. */
    public const INTERDITS = ['/espace', '/login', '/api', '/verification', '/appareil', '/acces', '/f/', '/om/'];

    public function __construct(private readonly AccesEnLigne $acces)
    {
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
        $lignes[] = 'Sitemap: '.$this->generateUrl('app_site_plan', [], UrlGeneratorInterface::ABSOLUTE_URL);

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
