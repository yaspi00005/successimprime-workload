<?php

namespace App\Controller\Finances;

use App\Service\Finances\PrevisionTresorerie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Trésorerie : prévision à 90 jours (maquette F05), avec trois
 * scénarios et l'export jour par jour. Soldes et prévisions :
 * ROLE_TRESORERIE_VOIR. L'ancien tableau des comptes et mouvements
 * (/finances/tresorerie) reste accessible depuis l'en-tête.
 */
#[Route('/finances/prevision')]
#[IsGranted('ROLE_TRESORERIE_VOIR')]
final class PrevisionController extends AbstractController
{
    use DroitsFinances;

    #[Route('', name: 'app_finances_tresorerie', methods: ['GET'])]
    public function index(Request $request, PrevisionTresorerie $prevision): Response
    {
        $donnees = $prevision->donnees(new \DateTimeImmutable(), (string) $request->query->get('scenario', 'prudent'));

        return $this->render('finances/prevision.html.twig', $donnees + [
            'droits' => $this->droits(),
            'scenarios' => array_map(static fn (array $s): string => $s['libelle'], PrevisionTresorerie::SCENARIOS),
        ]);
    }

    /** La prévision jour par jour en CSV (séparateur « ; », lisible par Excel). */
    #[Route('/export', name: 'app_finances_tresorerie_export', methods: ['GET'])]
    public function export(Request $request, PrevisionTresorerie $prevision): Response
    {
        $donnees = $prevision->donnees(new \DateTimeImmutable(), (string) $request->query->get('scenario', 'prudent'));
        $points = $donnees['points'];
        $seuil = $donnees['seuil'];

        $reponse = new StreamedResponse(static function () use ($points, $seuil): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Date', 'Entrées prévues', 'Sorties prévues', 'Solde prévu', 'Sous le seuil'], ';', '"', '');
            foreach ($points as $p) {
                fputcsv($sortie, [$p['date']->format('d/m/Y'), $p['entrees'], $p['sorties'], $p['solde'], $p['solde'] < $seuil ? 'oui' : ''], ';', '"', '');
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', \sprintf('attachment; filename="prevision-tresorerie-%s-%s.csv"', $donnees['scenario'], $donnees['aujourdhui']->format('Y-m-d')));

        return $reponse;
    }
}
