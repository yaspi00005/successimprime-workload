<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Achats\CalculsMarges;
use App\Service\Achats\CoutsMarges;
use App\Service\Http\Entree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Stock et achats › Coûts et marges (maquette SU04, docs/achats.md) :
 * pour chaque produit vendu dans le mois, le coût de revient (achat,
 * transport et transit, encre, gâche, machine et main-d'œuvre), le prix
 * de vente moyen, la marge et le bénéfice brut ; la cascade « où part
 * l'argent » d'un produit et ce qu'il faut retenir.
 *
 * Coûts et marges : à la direction seulement (access_control sur
 * /achats et ROLE_ADMIN ici), jamais aux clients.
 */
#[Route('/achats/couts-marges')]
#[IsGranted('ROLE_ADMIN')]
final class CoutsMargesController extends AbstractController
{
    public function __construct(
        private readonly CoutsMarges $couts,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    #[Route('', name: 'app_couts_marges_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $mois = CalculsMarges::mois($request->query->getString('mois') ?: null, $aujourdhui);
        $analyse = $this->couts->analyser($mois);
        $precedent = $this->couts->moisPrecedent($mois, $aujourdhui);

        // La cascade : le produit choisi (?produit=), sinon celui dont le transport pèse le plus dans le prix
        // (matière reçue d'un fournisseur, vendu avec bénéfice), sinon le plus gros bénéfice.
        $choisi = Entree::entier($request->query, 'produit');
        $detail = null;
        foreach ($analyse['produits'] as $p) {
            if ((int) $p['produit']->getId() === $choisi) {
                $detail = $p;
            }
        }
        if (null === $detail) {
            $poids = 0.0;
            foreach ($analyse['produits'] as $p) {
                $prix = (int) $p['bilan']['prixMoyen'];
                if ('receptions' === $p['source'] && $p['bilan']['benefice'] > 0 && $prix > 0 && $p['frais'] / $prix > $poids) {
                    [$detail, $poids] = [$p, $p['frais'] / $prix];
                }
            }
        }
        $detail ??= $analyse['produits'][0] ?? null;

        return $this->render('achats/couts_marges.html.twig', [
            'analyse' => $analyse,
            'mois' => $mois,
            'libelle_mois' => CalculsMarges::libelleMois($mois),
            'liste_mois' => self::listeMois($aujourdhui),
            'precedent' => $precedent,
            'evolution' => CalculsMarges::evolution($analyse['total']['benefice'], $precedent['benefice']),
            'libelle_precedent' => CalculsMarges::libelleMois($precedent['mois']),
            'detail' => $detail,
            'cascade' => null !== $detail ? CalculsMarges::cascade($detail['bilan']) : [],
            'a_retenir' => CalculsMarges::aRetenir($analyse['produits']),
            'gache_defaut' => $this->couts->gacheDefaut(),
            'seuil' => CalculsMarges::SEUIL_MARGE_FAIBLE,
        ]);
    }

    #[Route('/export', name: 'app_couts_marges_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $mois = CalculsMarges::mois($request->query->getString('mois') ?: null, new \DateTimeImmutable('today'));
        $analyse = $this->couts->analyser($mois);

        $reponse = new StreamedResponse(static function () use ($analyse): void {
            $sortie = fopen('php://output', 'wb');
            // BOM pour qu'Excel lise les accents.
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Produit', 'Fournisseur', 'Unité', 'Achat', 'Transport', 'Transit', 'Encre', 'Gâche', 'Machine et main-d\'œuvre', 'Finitions et accessoires', 'Coût de revient', 'Prix de vente moyen', 'Marge unitaire', 'Marge (%)', 'Vendu', 'Chiffre d\'affaires HT', 'Bénéfice brut', 'Coût incomplet'], ';', '"', '');
            foreach ($analyse['produits'] as $p) {
                $b = $p['bilan'];
                fputcsv($sortie, [
                    $p['nom'],
                    $p['fournisseur']?->getNomCourt(),
                    $p['unite'],
                    ...array_values($b['unitaires']),
                    $b['coutUnitaire'],
                    $b['prixMoyen'],
                    $b['margeUnitaire'],
                    null !== $b['taux'] ? str_replace('.', ',', (string) $b['taux']) : '',
                    str_replace('.', ',', (string) round($b['quantite'], 1)),
                    $b['ca'],
                    $b['benefice'],
                    implode(' ; ', $p['manquants']),
                ], ';', '"', '');
            }
            fputcsv($sortie, ['Total', '', '', '', '', '', '', '', '', '', '', '', '', null !== $analyse['total']['taux'] ? str_replace('.', ',', (string) $analyse['total']['taux']) : '', '', $analyse['total']['ca'], $analyse['total']['benefice'], 'avant loyer, salaires fixes et électricité'], ';', '"', '');
            fclose($sortie);
        });

        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="couts-marges-%s.csv"', $mois->format('Y-m')));

        return $reponse;
    }

    /**
     * « Régler les coûts » : gâche par défaut, gâche mesurée de chaque
     * article, coût machine et main-d'œuvre au m² de chaque machine. Les
     * valeurs vont dans la tuile « Achats et fournisseurs » ; depuis le site
     * en ligne, elles sont proposées à l'ERP.
     */
    #[Route('/reglages', name: 'app_couts_marges_reglages', methods: ['GET', 'POST'])]
    public function reglages(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('couts_marges_reglages', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }
            $utilisateur = $this->getUser();
            $resultat = $this->couts->enregistrer(
                $request->request->get('gache_defaut'),
                $request->request->all('machines'),
                $request->request->all('gaches'),
                $utilisateur instanceof User ? $utilisateur : null,
            );
            $this->addFlash('applique' === $resultat ? 'success' : 'info', match ($resultat) {
                'applique' => 'Coûts enregistrés : les marges sont recalculées.',
                'propose' => 'Changement proposé : il s\'appliquera quand la direction le validera sur l\'ERP.',
                default => 'Rien n\'a changé.',
            });

            return $this->redirectToRoute('app_couts_marges_reglages', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('achats/couts_marges_reglages.html.twig', $this->couts->reglages() + ['en_ligne' => $this->enLigne]);
    }

    /**
     * Les 12 derniers mois, le plus récent d'abord.
     *
     * @return list<array{valeur: string, libelle: string}>
     */
    private static function listeMois(\DateTimeImmutable $aujourdhui): array
    {
        $mois = $aujourdhui->modify('first day of this month');
        $liste = [];
        for ($i = 0; $i < 12; ++$i) {
            $m = $mois->modify(sprintf('-%d months', $i));
            $liste[] = ['valeur' => $m->format('Y-m'), 'libelle' => ucfirst(CalculsMarges::libelleMois($m))];
        }

        return $liste;
    }
}
