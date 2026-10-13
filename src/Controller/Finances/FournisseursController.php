<?php

namespace App\Controller\Finances;

use App\Service\Finances\Echeancier;
use App\Service\Finances\Periode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Fournisseurs et échéancier (maquette F04) : ce qui est dû
 * aux fournisseurs et tout ce qui doit sortir dans les 30 prochains jours
 * (charges récurrentes, impôts, salaires). Montants de trésorerie :
 * ROLE_TRESORERIE_VOIR.
 */
#[Route('/finances/fournisseurs')]
#[IsGranted('ROLE_TRESORERIE_VOIR')]
final class FournisseursController extends AbstractController
{
    use DroitsFinances;

    public const JOURS = 30;

    #[Route('', name: 'app_finances_fournisseurs', methods: ['GET'])]
    public function index(Echeancier $echeancier): Response
    {
        $maintenant = new \DateTimeImmutable();
        $aujourdhui = $maintenant->setTime(0, 0);
        $dettes = $echeancier->dettes($aujourdhui);
        $sorties = $echeancier->sorties($aujourdhui, $aujourdhui->modify('+'.self::JOURS.' days'));
        $jours = Echeancier::parJour($sorties);

        // « Cette semaine » : dettes fournisseurs à payer d'ici 7 jours (retards compris).
        $semaine = array_values(array_filter($sorties, static fn (array $s): bool => 'fournisseur' === $s['type'] && $s['date'] <= $aujourdhui->modify('+7 days')));
        usort($semaine, static fn (array $a, array $b): int => $b['montant'] <=> $a['montant']);
        [$debut, $fin] = Periode::bornes($aujourdhui);

        return $this->render('finances/fournisseurs.html.twig', [
            'droits' => $this->droits(),
            'dettes' => $dettes,
            'total_du' => array_sum(array_column($dettes, 'du')),
            'total_a_approuver' => array_sum(array_column($dettes, 'a_approuver')),
            'creanciers' => \count(array_filter($dettes, static fn (array $d): bool => $d['du'] > 0)),
            'semaine' => ['montant' => array_sum(array_column($semaine, 'montant')), 'plus_gros' => $semaine[0] ?? null],
            'gros_jour' => Echeancier::grosJour($jours),
            'achats' => $echeancier->achatsRecus($debut, $fin),
            'jours' => $jours,
            'aujourdhui' => $aujourdhui,
            'nombre_jours' => self::JOURS,
        ]);
    }
}
