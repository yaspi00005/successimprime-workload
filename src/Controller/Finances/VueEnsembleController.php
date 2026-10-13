<?php

namespace App\Controller\Finances;

use App\Entity\User;
use App\Service\Finances\FinancesDuJour;
use App\Service\Finances\Periode;
use App\Service\Finances\VueEnsemble;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Finances › Vue d'ensemble (maquette F01) et « Finances du jour », sa
 * version téléphone (maquette F07). Les écrans s'ouvrent à qui voit au
 * moins un de leurs chiffres ; chaque carte reste soumise à son droit :
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
        $droits = $this->droitsVerifies();
        $maintenant = new \DateTimeImmutable();
        $mois = Periode::moisDemande((string) $request->query->get('periode', ''), $maintenant);

        return $this->render('finances/vue_ensemble.html.twig', $vue->donnees($maintenant, $mois, $droits) + [
            'droits' => $droits,
            'periodes' => Periode::choix($maintenant),
            'periode' => $mois->format('Y-m'),
        ]);
    }

    #[Route('/jour', name: 'du_jour', methods: ['GET'])]
    public function duJour(FinancesDuJour $jour): Response
    {
        $droits = $this->droitsVerifies();

        return $this->render('finances/du_jour.html.twig', $jour->donnees(new \DateTimeImmutable(), $droits, $this->getUser() instanceof User ? $this->getUser() : null) + ['droits' => $droits]);
    }

    /**
     * Droits de la personne, si elle voit au moins un chiffre des écrans :
     * soldes, chiffre d'affaires, paiements ou marges.
     *
     * @return array{tresorerie: bool, ca: bool, paiements: bool, marges: bool, admin: bool, saisie: bool, rh: bool, encaisser: bool}
     */
    private function droitsVerifies(): array
    {
        $droits = $this->droits();
        if (!$droits['tresorerie'] && !$droits['ca'] && !$droits['paiements'] && !$droits['marges']) {
            throw $this->createAccessDeniedException();
        }

        return $droits;
    }
}
