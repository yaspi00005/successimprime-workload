<?php

namespace App\Controller;

use App\Service\VentesService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ventes · Devis et commandes (maquette 21).
 */
#[Route('/ventes', name: 'app_ventes_')]
final class VentesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, VentesService $ventesService): Response
    {
        $maintenant = new \DateTimeImmutable();
        $onglets = $this->ongletsAutorises();
        $onglet = $this->onglet($request, $onglets);
        $nombres = $ventesService->nombresParOnglet($maintenant);
        $pages = max(1, (int) ceil($nombres[$onglet] / VentesService::PAR_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('ventes/index.html.twig', [
            'indicateurs' => $ventesService->indicateurs($maintenant),
            'onglet' => $onglet,
            'onglets' => $onglets,
            'voirDevis' => $this->isGranted('ROLE_DEVIS'),
            'voirStatsVentes' => $this->isGranted('ROLE_STATS_VENTES'),
            'nombres' => $nombres,
            'lignes' => $ventesService->lignes($onglet, $page, $maintenant),
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request, VentesService $ventesService): StreamedResponse
    {
        $onglet = $this->onglet($request, $this->ongletsAutorises());
        $lignes = $ventesService->lignes($onglet, 1, new \DateTimeImmutable(), null);

        $reponse = new StreamedResponse(static function () use ($lignes): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['N°', 'Type', 'Date', 'Client', 'Objet', 'Agent', 'Montant TTC', 'Statut'], ';', '"', '');

            foreach ($lignes as $ligne) {
                fputcsv($sortie, [
                    $ligne['numero'],
                    'devis' === $ligne['type'] ? 'Devis' : 'Commande',
                    $ligne['date']?->format('d/m/Y'),
                    $ligne['client'],
                    $ligne['objet'],
                    $ligne['agent'],
                    $ligne['montant'],
                    $ligne['statut']['libelle'],
                ], ';', '"', '');
            }

            fclose($sortie);
        });

        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="ventes-%s-%s.csv"', $onglet, date('Y-m-d')));

        return $reponse;
    }

    /**
     * Un vendeur qui n'a que les devis (ou que les commandes) ne voit que
     * les onglets qui les concernent.
     *
     * @return array<string, string>
     */
    private function ongletsAutorises(): array
    {
        $voirDevis = $this->isGranted('ROLE_DEVIS');
        $voirCommandes = $this->isGranted('ROLE_COMMANDE');

        if (!$voirDevis && !$voirCommandes) {
            throw $this->createAccessDeniedException();
        }

        return array_filter(
            VentesService::ONGLETS,
            static fn (string $cle): bool => match ($cle) {
                'tous' => $voirDevis && $voirCommandes,
                'commandes' => $voirCommandes,
                default => $voirDevis,
            },
            \ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param array<string, string> $onglets
     */
    private function onglet(Request $request, array $onglets): string
    {
        $onglet = (string) $request->query->get('onglet', '');

        return \array_key_exists($onglet, $onglets) ? $onglet : (string) array_key_first($onglets);
    }
}
