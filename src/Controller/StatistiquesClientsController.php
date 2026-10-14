<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\StatistiquesClientsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques detaillees des clients : chiffre d'affaires genere
 * par client, et evolution du nombre de nouveaux clients enregistres
 * dans le temps. Le chiffre d'affaires par client demande
 * ROLE_STATS_VENTES : sans lui, la liste ne donne que le nombre de
 * commandes, triee sur ce nombre.
 */
#[Route('/statistiques/clients', name: 'app_statistiques_clients_')]
final class StatistiquesClientsController extends AbstractController
{
    use ExporterStatistiques;

    public function __construct(
        private readonly EvolutionTemporelleService $evolutionTemporelleService,
        private readonly StatistiquesClientsService $statistiquesClientsService,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('statistiques/clients.html.twig', $this->donnees($request));
    }

    #[Route('/export.{format}', name: 'export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'])]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $d = $this->donnees($request);
        $periode = \sprintf('du %s au %s', (new \DateTimeImmutable($d['filtres']['date_debut']))->format('d/m/Y'), (new \DateTimeImmutable($d['filtres']['date_fin']))->format('d/m/Y'));
        $granularite = StatistiquesCommandesController::GRANULARITES[$d['granulariteEvolution']] ?? $d['granulariteEvolution'];

        return $this->reponseExport(ExportsStatistiques::clients($d, $d['droits'], $periode, $granularite), $format, $excel, $pdf, $em);
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(Request $request): array
    {
        $droits = $this->droitsStatistiques();
        $granularite = $this->evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite')
        );

        [$debut, $fin, $filtres] = $this->resoudrePeriode($request);

        $caParClient = $this->statistiquesClientsService->caParClient($debut, $fin);
        if (!$droits['ventes']) {
            $caParClient = array_map(static function (array $l): array {
                unset($l['montant']);

                return $l;
            }, $caParClient);
            usort($caParClient, static fn (array $a, array $b): int => [$b['nombre'], $a['nom']] <=> [$a['nombre'], $b['nom']]);
        }

        return [
            'caParClient' => $caParClient,
            'filtres' => $filtres,
            'nouveauxClients' => $this->statistiquesClientsService->nouveauxClientsParPeriode($granularite),
            'granulariteEvolution' => $granularite,
            'droits' => $droits,
            'local' => !$this->acces->estEnLigne(),
        ];
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
