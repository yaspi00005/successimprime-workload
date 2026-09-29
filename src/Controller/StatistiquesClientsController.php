<?php

namespace App\Controller;

use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\StatistiquesClientsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques detaillees des clients : chiffre d'affaires genere
 * par client, et evolution du nombre de nouveaux clients enregistres
 * dans le temps.
 */
#[Route('/statistiques/clients', name: 'app_statistiques_clients_')]
final class StatistiquesClientsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        EvolutionTemporelleService $evolutionTemporelleService,
        StatistiquesClientsService $statistiquesClientsService
    ): Response {
        $granularite = $evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite')
        );

        [$debut, $fin, $filtres] = $this->resoudrePeriode($request);

        return $this->render('statistiques/clients.html.twig', [
            'caParClient' => $statistiquesClientsService->caParClient($debut, $fin),
            'filtres' => $filtres,
            'nouveauxClients' => $statistiquesClientsService->nouveauxClientsParPeriode($granularite),
            'granulariteEvolution' => $granularite,
        ]);
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: array<string, string>}
     */
    private function resoudrePeriode(Request $request): array
    {
        $dateDebutBrute = trim((string) $request->query->get('date_debut', ''));
        $dateFinBrute = trim((string) $request->query->get('date_fin', ''));

        try {
            $debut = $dateDebutBrute !== ''
                ? new \DateTimeImmutable($dateDebutBrute . ' 00:00:00')
                : new \DateTimeImmutable('first day of january this year 00:00:00');
        } catch (\Exception) {
            $debut = new \DateTimeImmutable('first day of january this year 00:00:00');
        }

        try {
            $fin = $dateFinBrute !== ''
                ? new \DateTimeImmutable($dateFinBrute . ' 23:59:59')
                : new \DateTimeImmutable('now');
        } catch (\Exception) {
            $fin = new \DateTimeImmutable('now');
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
