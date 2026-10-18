<?php

namespace App\Controller;

use App\Repository\ActionSocialeRepository;
use App\Service\Site\ActionsSociales;
use App\Service\Site\SoutienSite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Nos actions sociales » et le QR code de « Nous soutenir » (docs/site-vitrine.md).
 *
 * La page des actions sociales répond « introuvable » tant qu'aucune action
 * publiée n'a un texte et une photo (ActionsSociales::visibles) ; le QR
 * code, tant que le lien de l'avis Google ou le QR est coupé.
 */
final class SiteSocialController extends AbstractController
{
    #[Route('/nos-actions-sociales', name: ActionsSociales::ROUTE, methods: ['GET'])]
    public function actionsSociales(Request $request, ActionsSociales $partie, ActionSocialeRepository $actions, SiteController $site): Response
    {
        if (!$partie->visibles()) {
            throw $this->createNotFoundException();
        }

        return $site->cache($this->render('site/actions_sociales.html.twig', [
            'actions' => $actions->visibles(),
        ]), $request);
    }

    /**
     * QR code du lien « Laisser un avis » (SVG fait ici, sans appel
     * extérieur), chargé par l'accueil et l'espace client sur ordinateur.
     * Gardé un jour par le navigateur : son adresse change avec le lien.
     */
    #[Route('/nous-soutenir/qr-code.svg', name: 'app_site_soutien_qr', methods: ['GET'])]
    public function qrCode(SoutienSite $soutien): Response
    {
        $svg = $soutien->qrSvg() ?? throw $this->createNotFoundException();
        $reponse = new Response($svg, 200, ['Content-Type' => 'image/svg+xml; charset=UTF-8']);
        $reponse->setPublic();
        $reponse->setMaxAge(86400);
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $reponse;
    }
}
