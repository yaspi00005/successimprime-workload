<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\EvolutionFinanciereService;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\StatistiquesTresorerieService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Statistiques detaillees de tresorerie : evolution des
 * encaissements/decaissements dans le temps, et repartition des
 * charges par categorie sur un intervalle de dates libre. Les
 * mouvements des comptes demandent ROLE_TRESORERIE_VOIR, comme
 * Finances > Tresorerie ; le chiffre d'affaires de l'evolution n'est
 * pas envoye a la page.
 */
#[Route('/statistiques/tresorerie', name: 'app_statistiques_tresorerie_')]
#[IsGranted('ROLE_TRESORERIE_VOIR')]
final class StatistiquesTresorerieController extends AbstractController
{
    use ExporterStatistiques;

    public function __construct(
        private readonly EvolutionTemporelleService $evolutionTemporelleService,
        private readonly EvolutionFinanciereService $evolutionFinanciereService,
        private readonly StatistiquesTresorerieService $statistiquesTresorerieService,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('statistiques/tresorerie.html.twig', $this->donnees($request));
    }

    #[Route('/export.{format}', name: 'export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'])]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $d = $this->donnees($request);
        $periode = \sprintf('du %s au %s', (new \DateTimeImmutable($d['filtres']['date_debut']))->format('d/m/Y'), (new \DateTimeImmutable($d['filtres']['date_fin']))->format('d/m/Y'));

        return $this->reponseExport(ExportsStatistiques::tresorerie($d, $periode), $format, $excel, $pdf, $em);
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(Request $request): array
    {
        $granularite = $this->evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite')
        );

        [$debut, $fin, $filtres] = $this->resoudreIntervalle($request);
        $calcul = $this->evolutionFinanciereService->calculer($granularite);

        return [
            'evolution' => ['labels' => $calcul['labels'], 'encaissements' => $calcul['encaissements'], 'decaissements' => $calcul['decaissements']],
            'granulariteEvolution' => $granularite,
            'filtres' => $filtres,
            'totalEncaisse' => $this->statistiquesTresorerieService->totalEncaisse($debut, $fin),
            'totalDecaisse' => $this->statistiquesTresorerieService->totalDecaisse($debut, $fin),
            'chargesParCategorie' => $this->statistiquesTresorerieService->chargesParCategorie($debut, $fin),
            'local' => !$this->acces->estEnLigne(),
        ];
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
