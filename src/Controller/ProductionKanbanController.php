<?php

namespace App\Controller;

use App\Entity\Machines;
use App\Repository\MachinesRepository;
use App\Service\PlanningMachines;
use App\Service\TableauProduction;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Atelier › Production en vue Kanban (maquette 23) et planning des
 * machines (maquette 24). Lecture seule :
 * chaque carte ouvre l'écran où l'étape se fait (prépresse, ordre de
 * production ou commande).
 *
 * Hors du préfixe app_production_ : les écrans de production restent
 * réservés aux postes machines (ProductionMachineSubscriber), alors que
 * ce tableau se consulte depuis n'importe quel poste du personnel.
 */
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class ProductionKanbanController extends AbstractController
{
    #[Route('/atelier/production', name: 'app_atelier_production', methods: ['GET'])]
    public function index(Request $request, TableauProduction $tableau, MachinesRepository $machines): Response
    {
        $machine = $request->query->getInt('machine') ?: null;
        $recherche = mb_substr(trim((string) $request->query->get('q', '')), 0, 100);

        return $this->render('production/kanban.html.twig', $tableau->donnees(new \DateTimeImmutable(), $machine, $recherche) + [
            'machine_choisie' => $machine,
            'recherche' => $recherche,
            // Les pages d'ordres de production ne s'ouvrent que depuis un poste machine.
            'poste_production' => null !== $request->getClientIp() && $machines->findOneByAdresseIp($request->getClientIp()) instanceof Machines,
        ]);
    }

    #[Route('/atelier/planning', name: 'app_atelier_planning', methods: ['GET'])]
    public function planning(Request $request, PlanningMachines $planning): Response
    {
        $maintenant = new \DateTimeImmutable();
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('jour')) ?: $maintenant;

        return $this->render('production/planning.html.twig', $planning->donnees($jour, $maintenant) + [
            'aujourdhui' => $jour->format('Y-m-d') === $maintenant->format('Y-m-d'),
        ]);
    }
}
