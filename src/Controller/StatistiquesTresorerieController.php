<?php

namespace App\Controller;

use App\Service\EvolutionFinanciereService;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\StatistiquesTresorerieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques detaillees de tresorerie : evolution des
 * encaissements/decaissements dans le temps, et repartition des
 * charges par categorie sur un intervalle de dates libre.
 */
#[Route('/statistiques/tresorerie', name: 'app_statistiques_tresorerie_')]
final class StatistiquesTresorerieController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        EvolutionTemporelleService $evolutionTemporelleService,
        EvolutionFinanciereService $evolutionFinanciereService,
        StatistiquesTresorerieService $statistiquesTresorerieService
    ): Response {
        $granularite = $evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite')
        );

        [$debut, $fin, $filtres] = $this->resoudreIntervalle($request);

        return $this->render('statistiques/tresorerie.html.twig', [
            'evolution' => $evolutionFinanciereService->calculer($granularite),
            'granulariteEvolution' => $granularite,
            'filtres' => $filtres,
            'totalEncaisse' => $statistiquesTresorerieService->totalEncaisse($debut, $fin),
            'totalDecaisse' => $statistiquesTresorerieService->totalDecaisse($debut, $fin),
            'chargesParCategorie' => $statistiquesTresorerieService->chargesParCategorie($debut, $fin),
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: array<string, string>}
     */
    private function resoudreIntervalle(Request $request): array
    {
        $dateDebutBrute = trim((string) $request->query->get('date_debut', ''));
        $dateFinBrute = trim((string) $request->query->get('date_fin', ''));

        try {
            $debut = $dateDebutBrute !== ''
                ? new \DateTimeImmutable($dateDebutBrute . ' 00:00:00')
                : new \DateTimeImmutable('first day of this month 00:00:00');
        } catch (\Exception) {
            $debut = new \DateTimeImmutable('first day of this month 00:00:00');
        }

        try {
            $fin = $dateFinBrute !== ''
                ? new \DateTimeImmutable($dateFinBrute . ' 23:59:59')
                : new \DateTimeImmutable('last day of this month 23:59:59');
        } catch (\Exception) {
            $fin = new \DateTimeImmutable('last day of this month 23:59:59');
        }

        if ($fin < $debut) {
            $fin = $debut;
        }

        return [
            $debut,
            $fin,
            [
                'date_debut' => $debut->format('Y-m-d'),
                'date_fin' => $fin->format('Y-m-d'),
            ],
        ];
    }
}
