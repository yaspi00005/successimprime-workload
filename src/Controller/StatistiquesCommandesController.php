<?php

namespace App\Controller;

use App\Service\EvolutionFinanciereService;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\StatistiquesCommandesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques detaillees des commandes : evolution dans le temps
 * (jour/semaine/mois/annee) et suivi des commandes non soldees
 * (impayees ou partiellement payees), classees par anciennete.
 */
#[Route('/statistiques/commandes', name: 'app_statistiques_commandes_')]
final class StatistiquesCommandesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        EvolutionTemporelleService $evolutionTemporelleService,
        EvolutionFinanciereService $evolutionFinanciereService,
        StatistiquesCommandesService $statistiquesCommandesService
    ): Response {
        $granularite = $evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite')
        );

        [$commandesNonSoldees, $parTranche] = $statistiquesCommandesService->commandesNonSoldees();

        return $this->render('statistiques/commandes.html.twig', [
            'evolution' => $evolutionFinanciereService->calculer($granularite),
            'granulariteEvolution' => $granularite,
            'commandesNonSoldees' => $commandesNonSoldees,
            'parTranche' => $parTranche,
            'totalReste' => array_sum(array_column($commandesNonSoldees, 'reste')),
        ]);
    }
}
