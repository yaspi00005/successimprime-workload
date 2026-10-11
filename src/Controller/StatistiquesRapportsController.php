<?php

namespace App\Controller;

use App\Service\Statistiques\StatistiquesRapports;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accueil › Statistiques et rapports (maquette 36). Les montants de
 * vente demandent ROLE_STATS_VENTES, la marge ROLE_COUT_MARGE.
 */
#[Route('/statistiques', name: 'app_statistiques_')]
#[IsGranted('ROLE_STATS_GLOBAL')]
final class StatistiquesRapportsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, StatistiquesRapports $rapports): Response
    {
        $mois = $request->query->getInt('periode', 12);
        if (!isset(StatistiquesRapports::PERIODES[$mois])) {
            $mois = 12;
        }
        $voirMarge = $this->isGranted('ROLE_COUT_MARGE');

        return $this->render('statistiques/rapports.html.twig', $rapports->donnees(new \DateTimeImmutable(), $mois, $voirMarge) + [
            'periode' => $mois,
            'comparer' => $request->query->getBoolean('comparer'),
            'voir_ventes' => $this->isGranted('ROLE_STATS_VENTES'),
            'voir_marge' => $voirMarge,
        ]);
    }
}
