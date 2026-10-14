<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\StatistiquesArticlesService;
use App\Service\Statistiques\StatistiquesGlobalesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableaux de bord chiffrés pour l'administrateur : qui vend, qui
 * encaisse, qui produit, sur une période donnée. Les accès sont
 * définis dans security.yaml (ROLE_STATS_GLOBAL, réservé à
 * ROLE_ADMIN via la hiérarchie des rôles). Les montants de vente
 * demandent en plus ROLE_STATS_VENTES, les encaissements par caissière
 * ROLE_PAIEMENT_VOIR : sans le droit, ils ne sont ni calculés ni envoyés
 * à la page, aux graphiques ou aux exports (donnees()).
 */
#[Route('/statistiques/globales', name: 'app_statistiques_globales_')]
final class StatistiquesController extends AbstractController
{
    use ExporterStatistiques;

    public function __construct(
        private readonly StatistiquesGlobalesService $statistiquesGlobalesService,
        private readonly StatistiquesArticlesService $statistiquesArticlesService,
        private readonly EvolutionTemporelleService $evolutionTemporelleService,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function globales(Request $request): Response
    {
        return $this->render('statistiques/globales.html.twig', $this->donnees($request));
    }

    #[Route('/export.{format}', name: 'export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'])]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $d = $this->donnees($request);
        $periode = \sprintf('du %s au %s', (new \DateTimeImmutable($d['filtres']['date_debut']))->format('d/m/Y'), (new \DateTimeImmutable($d['filtres']['date_fin']))->format('d/m/Y'));

        return $this->reponseExport(ExportsStatistiques::globales($d, $d['droits'], $periode), $format, $excel, $pdf, $em);
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(Request $request): array
    {
        [$debut, $fin, $filtres] = $this->resoudrePeriode($request);
        $droits = $this->droitsStatistiques();
        $sansMontant = static fn (array $lignes): array => array_map(static function (array $l): array {
            unset($l['montant']);

            return $l;
        }, $lignes);

        $granulariteStock = $this->evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite_stock')
        );
        $parAgent = $this->statistiquesGlobalesService->ventesParAgent($debut, $fin);
        $ventesArticles = $this->statistiquesArticlesService->ventesArticles($debut, $fin);

        return [
            'parAgent' => $droits['ventes'] ? $parAgent : $sansMontant($parAgent),
            'parCaissiere' => $droits['paiements'] ? $this->statistiquesGlobalesService->encaissementsParCaissiere($debut, $fin) : null,
            'parProduction' => $this->statistiquesGlobalesService->productionParAgent($debut, $fin),
            'parMachine' => $this->statistiquesGlobalesService->rendementParMachine($debut, $fin),
            'topProduits' => $this->statistiquesGlobalesService->topProduits($debut, $fin),
            'ventesArticles' => $droits['ventes'] ? $ventesArticles : $sansMontant($ventesArticles),
            'evolutionStock' => $this->statistiquesArticlesService->evolutionStock($granulariteStock),
            'granulariteStock' => $granulariteStock,
            'filtres' => $filtres,
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
