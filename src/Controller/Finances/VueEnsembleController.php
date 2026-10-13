<?php

namespace App\Controller\Finances;

use App\Service\Finances\Periode;
use App\Service\Finances\VueEnsemble;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finances › Vue d'ensemble (maquette F01). L'écran s'ouvre à qui voit au
 * moins un de ses chiffres ; chaque carte reste soumise à son droit :
 * soldes (trésorerie), facturé (CA global), encaissé (paiements), marge
 * (coûts et marges).
 */
#[Route('/finances', name: 'app_finances_')]
final class VueEnsembleController extends AbstractController
{
    use DroitsFinances;

    #[Route('', name: 'vue_ensemble', methods: ['GET'])]
    public function index(Request $request, VueEnsemble $vue): Response
    {
        $droits = $this->droits();
        if (!\in_array(true, \array_slice($droits, 0, 4, true), true)) {
            throw $this->createAccessDeniedException();
        }

        $maintenant = new \DateTimeImmutable();
        $mois = Periode::moisDemande((string) $request->query->get('periode', ''), $maintenant);

        return $this->render('finances/vue_ensemble.html.twig', $vue->donnees($maintenant, $mois, $droits) + [
            'droits' => $droits,
            'periodes' => Periode::choix($maintenant),
            'periode' => $mois->format('Y-m'),
        ]);
    }
}
