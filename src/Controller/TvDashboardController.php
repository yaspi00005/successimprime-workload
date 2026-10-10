<?php

namespace App\Controller;

use App\Service\EcranTv;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran TV public de l'accueil (maquette 42).
 *
 * Aucune authentification requise : voir security.yaml, section PUBLIC.
 * Seuls des volumes et des numéros de commande sont affichés.
 */
final class TvDashboardController extends AbstractController
{
    #[Route('/ecran-accueil', name: 'app_tv_dashboard', methods: ['GET'])]
    public function index(EcranTv $ecranTv): Response
    {
        $reglages = $ecranTv->reglages();

        return $this->render('tv_dashboard/index.html.twig', [
            'donnees' => $ecranTv->donnees(new \DateTimeImmutable()),
            'medias' => $ecranTv->medias(),
            'reglages' => $reglages,
            'messages' => array_map(EcranTv::morceauxMessage(...), $reglages['messages']),
        ]);
    }

    /**
     * Chiffres relus par la page toutes les minutes, sans recharger
     * les images et les vidéos.
     */
    #[Route('/ecran-accueil/donnees', name: 'app_tv_dashboard_donnees', methods: ['GET'])]
    public function donnees(EcranTv $ecranTv): JsonResponse
    {
        $reponse = $this->json($ecranTv->donnees(new \DateTimeImmutable()));
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
