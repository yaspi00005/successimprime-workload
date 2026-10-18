<?php

namespace App\Controller;

use App\Security\AccesEnLigne;
use App\Service\Http\Entree;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\Objectifs;
use App\Service\Statistiques\ReglagesRapport;
use App\Service\Statistiques\StatistiquesRapports;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accueil › Statistiques et rapports (maquette 36). Les montants de
 * vente demandent ROLE_STATS_VENTES, la marge ROLE_COUT_MARGE, le taux
 * d'encaissement ROLE_PAIEMENT_VOIR. La page et ses exports passent par
 * donnees() : un chiffre masqué n'est ni à l'écran ni dans le fichier.
 */
#[Route('/statistiques', name: 'app_statistiques_')]
#[IsGranted('ROLE_STATS_GLOBAL')]
final class StatistiquesRapportsController extends AbstractController
{
    use ExporterStatistiques;

    public function __construct(
        private readonly StatistiquesRapports $rapports,
        private readonly Objectifs $objectifs,
        private readonly AccesEnLigne $acces,
        private readonly ReglagesRapport $reglagesRapport,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('statistiques/rapports.html.twig', $this->donnees($request));
    }

    #[Route('/export.{format}', name: 'export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'])]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $d = $this->donnees($request);
        $periode = StatistiquesRapports::PERIODES[$d['periode']].' depuis le '.$d['debut']->format('d/m/Y');

        return $this->reponseExport(ExportsStatistiques::rapports($d, $d['droits'], $periode), $format, $excel, $pdf, $em);
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(Request $request): array
    {
        $mois = Entree::entier($request->query, 'periode', 12);
        if (!isset(StatistiquesRapports::PERIODES[$mois])) {
            $mois = 12;
        }
        $droits = $this->droitsStatistiques();
        $maintenant = new \DateTimeImmutable();
        $d = $this->rapports->donnees($maintenant, $mois, $droits['marges']);

        if (!$droits['ventes']) {
            unset($d['kpi']['ca'], $d['kpi']['ca_evolution'], $d['kpi']['panier'], $d['kpi']['factures'], $d['mois'], $d['top_client'], $d['top_agent']);
        }

        // Objectifs : tables locales, pas sur le site en ligne.
        $local = !$this->acces->estEnLigne();
        $objectifs = $local ? array_values(array_filter($this->objectifs->tableau($maintenant, $droits, $maintenant), static fn (array $l): bool => 'global' === $l['axe'])) : [];
        $objectifsMois = $local && $droits['ventes'] ? $this->objectifs->parMois('ca:global:0', $d['debut'], $maintenant->modify('first day of next month')->setTime(0, 0)) : null;

        return $d + [
            'periode' => $mois,
            'comparer' => $request->query->getBoolean('comparer'),
            'voir_ventes' => $droits['ventes'],
            'voir_marge' => $droits['marges'],
            'droits' => $droits,
            'objectifs' => $objectifs,
            'objectifs_mois' => $objectifsMois,
            'local' => $local,
            'rapport_envoi' => $this->reglagesRapport->libelle(),
        ];
    }
}
