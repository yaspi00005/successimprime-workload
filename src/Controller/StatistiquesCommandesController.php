<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\EvolutionFinanciereService;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\StatistiquesCommandesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Statistiques detaillees des commandes : evolution dans le temps
 * (jour/semaine/mois/annee) et suivi des commandes non soldees
 * (impayees ou partiellement payees), classees par anciennete.
 * Le chiffre d'affaires demande ROLE_STATS_VENTES, les impayes
 * ROLE_PAIEMENT_VOIR ; les encaissements et decaissements de
 * l'evolution ne sont pas envoyes a la page (Tresorerie).
 */
#[Route('/statistiques/commandes', name: 'app_statistiques_commandes_')]
final class StatistiquesCommandesController extends AbstractController
{
    use ExporterStatistiques;

    public const GRANULARITES = ['jour' => 'jour', 'semaine' => 'semaine', 'mois' => 'mois', 'annee' => 'année'];

    public function __construct(
        private readonly EvolutionTemporelleService $evolutionTemporelleService,
        private readonly EvolutionFinanciereService $evolutionFinanciereService,
        private readonly StatistiquesCommandesService $statistiquesCommandesService,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('statistiques/commandes.html.twig', $this->donnees($request));
    }

    #[Route('/export.{format}', name: 'export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'])]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $d = $this->donnees($request);
        $periode = \sprintf('Évolution par %s · impayés au %s', self::GRANULARITES[$d['granulariteEvolution']] ?? $d['granulariteEvolution'], (new \DateTimeImmutable())->format('d/m/Y'));

        return $this->reponseExport(ExportsStatistiques::commandes($d, $d['droits'], $periode), $format, $excel, $pdf, $em);
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

        $calcul = $this->evolutionFinanciereService->calculer($granularite);
        $evolution = ['labels' => $calcul['labels'], 'commandes' => $calcul['commandes']];
        if ($droits['ventes']) {
            $evolution['ca'] = $calcul['ca'];
        }

        $commandesNonSoldees = $parTranche = null;
        if ($droits['paiements']) {
            [$lignes, $parTranche] = $this->statistiquesCommandesService->commandesNonSoldees();
            $commandesNonSoldees = array_map(static fn (array $l): array => [
                'id' => $l['commande']->getId(),
                'numero' => $l['commande']->getNumero() ?: '#'.$l['commande']->getId(),
                'client' => null !== $l['commande']->getClients() ? $l['commande']->getClients()->getNomComplet() : 'Client supprimé',
                'date' => \DateTimeImmutable::createFromInterface($l['commande']->getDateCommande()),
                'jours' => $l['jours'],
                'reste' => $l['reste'],
            ], $lignes);
        }

        return [
            'evolution' => $evolution,
            'granulariteEvolution' => $granularite,
            'commandesNonSoldees' => $commandesNonSoldees,
            'parTranche' => $parTranche,
            'totalReste' => null !== $commandesNonSoldees ? array_sum(array_column($commandesNonSoldees, 'reste')) : null,
            'droits' => $droits,
            'local' => !$this->acces->estEnLigne(),
        ];
    }
}
