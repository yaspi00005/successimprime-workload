<?php

namespace App\Controller;

use App\Entity\Tache;
use App\Entity\User;
use App\Service\OperationsAValider;
use App\Service\Planning\Planning;
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
        OperationsAValider $operationsAValider,
        Planning $planning,
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
        $finances = null;
        $donnees = [
            'peutVoirStatsGlobales' => $peutVoirStatsGlobales,
            'peutVoirProduction' => $peutVoirProduction,
        ];

        if ($peutVoirStatsGlobales) {
            $finances = [
                'indicateurs' => $tableauDeBordService->indicateurs($maintenant),
                'creances' => $tableauDeBordService->creances($maintenant),
            ];

            $donnees += $finances + [
                'croissanceCa' => $tableauDeBordService->croissanceCa($maintenant),
                'encaissementsDuJour' => $tableauDeBordService->encaissementsDuJour($maintenant),
                'chargeMachines' => $tableauDeBordService->chargeMachines(),
            ];
        }

        // Opérations provisoires (saisies en ligne ou à contrôler), maquette 41.
        if ($this->isGranted('ROLE_ADMIN')) {
            $attente = $operationsAValider->enAttente($maintenant);
            $donnees['aValider'] = ['nombre' => \count($attente), 'montant' => array_sum(array_column($attente, 'montant'))];
        }

        $donnees += [
            'production' => $peutVoirProduction ? $tableauDeBordService->production() : [],
            'alertes' => $tableauDeBordService->alertes($finances, $this->isGranted('ROLE_ADMIN'), $maintenant),
        ];

        // Tâches du planning en retard (rappel « En retard » actif), visibles de l'utilisateur.
        $moi = $this->getUser();
        if ($moi instanceof User) {
            $retard = array_filter($planning->enRetard($moi, $maintenant), static fn (Tache $t): bool => $t->getEcheance()?->rappel('retard') ?? true);
            if ($retard !== []) {
                $premiere = reset($retard);
                array_unshift($donnees['alertes'], ['niveau' => 'danger', 'texte' => \sprintf('%d tâche%s du planning en retard, dont « %s » prévue le %s', \count($retard), \count($retard) > 1 ? 's' : '', $premiere->getTitre(), $premiere->getPrevueLe()->format('d/m')), 'route' => 'app_planning_index']);
            }
        }

        return $this->render('dashboard/index.html.twig', $donnees);
    }
}
