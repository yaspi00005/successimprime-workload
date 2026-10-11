<?php

namespace App\Controller;

use App\Service\JournalComptable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Comptabilité SYSCOHADA (maquette 32) : journaux, balance,
 * grand livre et export pour le cabinet, déduits des opérations validées.
 */
#[Route('/finances/comptabilite', name: 'app_comptabilite_')]
#[IsGranted('ROLE_ADMIN')]
final class ComptabiliteController extends AbstractController
{
    public const VUES = ['journal', 'balance', 'grand_livre'];

    public function __construct(private readonly JournalComptable $comptabilite)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$debut, $fin] = self::periode((string) $request->query->get('mois'));
        $journaux = $this->comptabilite->ecritures($debut, $fin);
        $journal = (string) $request->query->get('journal', 'ventes');
        $journal = isset(JournalComptable::JOURNAUX[$journal]) ? $journal : 'ventes';
        $vue = \in_array($request->query->get('vue'), self::VUES, true) ? (string) $request->query->get('vue') : 'journal';

        $grandLivre = [];
        if ('grand_livre' === $vue) {
            foreach ($journaux as $code => $lignes) {
                foreach ($lignes as $l) {
                    $grandLivre[$l['compte']][] = $l + ['journal' => JournalComptable::CODES_JOURNAUX[$code]];
                }
            }
            ksort($grandLivre, \SORT_STRING);
            foreach ($grandLivre as &$lignes) {
                usort($lignes, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
            }
        }

        return $this->render('comptabilite/index.html.twig', [
            'debut' => $debut,
            'mois' => $debut->format('Y-m'),
            'mois_precedent' => $debut->modify('-1 month')->format('Y-m'),
            'mois_suivant' => $debut->modify('+1 month')->format('Y-m'),
            'vue' => $vue,
            'journal' => $journal,
            'journaux' => $journaux,
            'lignes' => $journaux[$journal],
            'balance' => 'balance' === $vue ? JournalComptable::balance($journaux) : [],
            'grand_livre' => $grandLivre,
        ]);
    }

    /** Toutes les écritures du mois en CSV (séparateur « ; », lisible par Excel). */
    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        [$debut, $fin] = self::periode((string) $request->query->get('mois'));
        $journaux = $this->comptabilite->ecritures($debut, $fin);

        $reponse = new StreamedResponse(static function () use ($journaux): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Journal', 'Date', 'Pièce', 'Compte', 'Libellé compte', 'Tiers', 'Libellé', 'Débit', 'Crédit', 'Lettrage'], ';', '"', '');
            foreach ($journaux as $code => $lignes) {
                foreach ($lignes as $l) {
                    fputcsv($sortie, [
                        JournalComptable::CODES_JOURNAUX[$code],
                        $l['date']->format('d/m/Y'),
                        $l['piece'],
                        $l['compte'],
                        JournalComptable::COMPTES[$l['compte']] ?? '',
                        $l['tiers'] ?? '',
                        $l['libelle'],
                        $l['debit'] ?: '',
                        $l['credit'] ?: '',
                        $l['lettrage'] ?? '',
                    ], ';', '"', '');
                }
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="ecritures-%s.csv"', $debut->format('Y-m')));

        return $reponse;
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable} */
    public static function periode(string $mois): array
    {
        $debut = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)
            ? new \DateTimeImmutable($mois.'-01')
            : (new \DateTimeImmutable())->modify('first day of this month')->setTime(0, 0);

        return [$debut, $debut->modify('last day of this month')->setTime(23, 59, 59)];
    }
}
