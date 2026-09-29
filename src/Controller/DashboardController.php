<?php

namespace App\Controller;

use App\Repository\ClientsRepository;
use App\Repository\CommandesRepository;
use App\Service\EvolutionFinanciereService;
use App\Service\EvolutionTemporelleService;
use App\Service\Statistiques\StatistiquesArticlesService;
use App\Service\Statistiques\StatistiquesClientsService;
use App\Service\Statistiques\StatistiquesCommandesService;
use App\Service\Statistiques\StatistiquesGlobalesService;
use App\Service\Statistiques\StatistiquesTresorerieService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tableau de bord "Accueil" : toutes les statistiques de l'entreprise
 * réunies sur une seule page (ventes, encaissements/décaissements,
 * impayés, clients, production, machines) pour que l'administrateur
 * ait une vue globale sans naviguer dans le menu Gestion. Chaque
 * section renvoie vers sa page dédiée (/statistiques/...) pour le
 * détail complet.
 */
final class DashboardController extends AbstractController
{
    private const PERIODES_VALIDES = ['jour', 'semaine', 'mois', 'annee', 'tout'];

    #[Route('/', name: 'app_home')]
    public function index(
        Request $request,
        CommandesRepository $commandesRepository,
        ClientsRepository $clientsRepository,
        EvolutionTemporelleService $evolutionTemporelleService,
        EvolutionFinanciereService $evolutionFinanciereService,
        StatistiquesGlobalesService $statistiquesGlobalesService,
        StatistiquesCommandesService $statistiquesCommandesService,
        StatistiquesTresorerieService $statistiquesTresorerieService,
        StatistiquesClientsService $statistiquesClientsService,
        StatistiquesArticlesService $statistiquesArticlesService
    ): Response {
        /*
         * Un livreur n'a accès qu'aux livraisons : il n'a rien
         * à faire sur le tableau de bord général.
         */
        if ($this->isGranted('ROLE_LIVREUR') && !$this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_livraisons_index');
        }

        $periode = $request->query->get('periode', 'mois');

        if (!\in_array($periode, self::PERIODES_VALIDES, true)) {
            $periode = 'mois';
        }

        $granulariteEvolution = $evolutionTemporelleService->normaliserGranularite(
            $request->query->get('granularite')
        );

        [$debut, $fin] = $this->calculerPeriode($periode);
        $debutEffectif = $debut ?? new \DateTimeImmutable('2000-01-01 00:00:00');

        $peutVoirStatsGlobales = $this->isGranted('ROLE_STATS_GLOBAL');

        $statsParAgent = $commandesRepository->statistiquesParAgent($debut, $fin);

        $totalCommandes = array_sum(array_column($statsParAgent, 'nbCommandes'));
        $totalCa = array_sum(array_column($statsParAgent, 'caGenere'));
        $meilleurCa = $statsParAgent === [] ? 0 : max(array_column($statsParAgent, 'caGenere'));

        $donnees = [
            'statsParAgent' => $statsParAgent,
            'totalCommandes' => $totalCommandes,
            'totalCa' => $totalCa,
            'meilleurCa' => $meilleurCa,
            'totalClients' => $clientsRepository->compterClients(),
            'periode' => $periode,
            'granulariteEvolution' => $granulariteEvolution,
            'evolution' => $evolutionFinanciereService->calculer($granulariteEvolution),
            'peutVoirStatsGlobales' => $peutVoirStatsGlobales,
        ];

        if ($peutVoirStatsGlobales) {
            [$commandesNonSoldees, $parTranche] = $statistiquesCommandesService->commandesNonSoldees(10);

            $donnees += [
                'parCaissiere' => $statistiquesGlobalesService->encaissementsParCaissiere($debutEffectif, $fin),
                'parMachine' => array_slice($statistiquesGlobalesService->rendementParMachine($debutEffectif, $fin), 0, 8),
                'topProduits' => array_slice($statistiquesGlobalesService->topProduits($debutEffectif, $fin), 0, 8),
                'commandesNonSoldees' => $commandesNonSoldees,
                'parTranche' => $parTranche,
                'totalReste' => array_sum(array_column($commandesNonSoldees, 'reste')),
                'totalEncaisse' => $statistiquesTresorerieService->totalEncaisse($debutEffectif, $fin),
                'totalDecaisse' => $statistiquesTresorerieService->totalDecaisse($debutEffectif, $fin),
                'chargesParCategorie' => array_slice($statistiquesTresorerieService->chargesParCategorie($debutEffectif, $fin), 0, 8),
                'caParClient' => array_slice($statistiquesClientsService->caParClient($debutEffectif, $fin), 0, 10),
                'nouveauxClients' => $statistiquesClientsService->nouveauxClientsParPeriode($granulariteEvolution),
                'ventesArticles' => array_slice($statistiquesArticlesService->ventesArticles($debutEffectif, $fin), 0, 8),
                'evolutionStock' => $statistiquesArticlesService->evolutionStock($granulariteEvolution),
            ];
        }

        return $this->render('dashboard/index.html.twig', $donnees);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function calculerPeriode(string $periode): array
    {
        $fin = new \DateTimeImmutable('now');

        $debut = match ($periode) {
            'jour' => $fin->setTime(0, 0),
            'semaine' => $fin->modify('monday this week')->setTime(0, 0),
            'annee' => $fin->modify('first day of january this year')->setTime(0, 0),
            'tout' => null,
            default => $fin->modify('first day of this month')->setTime(0, 0),
        };

        return [$debut, $fin];
    }
}
