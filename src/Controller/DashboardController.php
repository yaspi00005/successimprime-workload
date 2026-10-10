<?php

namespace App\Controller;

use App\Service\Statistiques\StatistiquesCommandesService;
use App\Service\TableauDeBordService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accueil de l'admin, d'apres la maquette 20 : indicateurs du mois,
 * production en cours, croissance du CA, encaissements du jour, charge
 * des machines et alertes. Le detail par periode est dans
 * « Statistiques et rapports ».
 */
final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function index(
        TableauDeBordService $tableauDeBordService,
        StatistiquesCommandesService $statistiquesCommandesService
    ): Response {
        /*
         * Un livreur n'a accès qu'aux livraisons : il n'a rien
         * à faire sur le tableau de bord général.
         */
        if ($this->isGranted('ROLE_LIVREUR') && !$this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_livraisons_index');
        }

        $maintenant = new \DateTimeImmutable();
        $peutVoirStatsGlobales = $this->isGranted('ROLE_STATS_GLOBAL');
        $peutVoirProduction = $this->isGranted('ROLE_PRODUCTION_VOIR') || $peutVoirStatsGlobales;
        $facturesEchues = 0;
        $donnees = [
            'peutVoirStatsGlobales' => $peutVoirStatsGlobales,
            'peutVoirProduction' => $peutVoirProduction,
        ];

        if ($peutVoirStatsGlobales) {
            [, $parTranche] = $statistiquesCommandesService->commandesNonSoldees(0);
            $creances = $tableauDeBordService->creances((int) array_sum(array_column($parTranche, 'montant')), $maintenant);
            $facturesEchues = $creances['facturesEchues'];

            $donnees += [
                'indicateurs' => $tableauDeBordService->indicateurs($maintenant),
                'creances' => $creances,
                'croissanceCa' => $tableauDeBordService->croissanceCa($maintenant),
                'encaissementsDuJour' => $tableauDeBordService->encaissementsDuJour($maintenant),
                'chargeMachines' => $tableauDeBordService->chargeMachines(),
            ];
        }

        $donnees += [
            'production' => $peutVoirProduction ? $tableauDeBordService->production() : [],
            'alertes' => $tableauDeBordService->alertes($facturesEchues, $this->isGranted('ROLE_ADMIN'), $peutVoirStatsGlobales),
        ];

        return $this->render('dashboard/index.html.twig', $donnees);
    }
}
