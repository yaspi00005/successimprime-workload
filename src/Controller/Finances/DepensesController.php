<?php

namespace App\Controller\Finances;

use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\User;
use App\Form\DepenseType;
use App\Repository\CompteTresorerieRepository;
use App\Service\Finances\Budgets;
use App\Service\Finances\Depenses;
use App\Service\Finances\Periode;
use App\Service\Finances\ReglagesFinances;
use App\Service\Http\Entree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Dépenses et budgets (maquette F03) et page « Approuver une
 * dépense » (F07). Voir : ROLE_TRESORERIE_VOIR (sinon seulement ses
 * propres dépenses) ; saisir et payer : ROLE_TRESORERIE_SAISIR ;
 * approuver, refuser et fixer les budgets : ROLE_ADMIN.
 */
#[Route('/finances/depenses', name: 'app_finances_depenses')]
final class DepensesController extends AbstractController
{
    use DroitsFinances;

    public function __construct(
        private readonly Depenses $depenses,
        private readonly Budgets $budgets,
        private readonly ReglagesFinances $reglages,
        private readonly CompteTresorerieRepository $comptes,
    ) {
    }

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $droits = $this->droits();
        if (!$droits['tresorerie'] && !$droits['saisie']) {
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        $mois = Periode::moisDemande((string) $request->query->get('periode', ''), $maintenant);
        [$debut, $fin] = Periode::bornes($mois);

        $formulaire = null;
        if ($droits['saisie']) {
            $depense = new Depense($this->utilisateur(), $maintenant);
            $formulaire = $this->createForm(DepenseType::class, $depense, [
                'comptes' => $this->comptes->trouverDisponiblesPour($this->utilisateur(), $droits['admin']),
                'action' => $this->generateUrl('app_finances_depenses', ['periode' => $mois->format('Y-m')]).'#saisir-depense',
            ]);
            $formulaire->handleRequest($request);
            if ($formulaire->isSubmitted() && $formulaire->isValid()) {
                /** @var UploadedFile $recu */
                $recu = $formulaire->get('justificatif')->getData();
                try {
                    $this->depenses->enregistrer($depense, $recu, $this->utilisateur(), $droits['admin'], $maintenant);
                    $this->addFlash('success', match ($depense->getStatut()) {
                        Depense::A_APPROUVER => \sprintf('Dépense enregistrée : au-delà de %s F, elle attend l\'accord de la direction.', number_format($this->reglages->seuilApprobation(), 0, ',', ' ')),
                        Depense::VALIDEE => 'Dépense enregistrée. Le décaissement attend sa validation dans « À valider ».',
                        default => 'Dépense enregistrée, à payer.',
                    });

                    return $this->redirectToRoute('app_finances_depenses', ['periode' => $depense->getDateDepense()->format('Y-m')]);
                } catch (\InvalidArgumentException|\LogicException $e) {
                    $this->addFlash('danger', $e->getMessage());
                }
            }
        } elseif ($request->isMethod('POST')) {
            throw $this->createAccessDeniedException();
        }

        $toutes = $this->depenses->duMois($debut, $fin, $droits['tresorerie'] ? null : $this->utilisateur());
        $tout = $request->query->getBoolean('tout');
        $lignes = null;
        if ($droits['tresorerie']) {
            $lignes = Budgets::lignes($this->budgets->montants(), $this->budgets->consommes($debut, $fin));
        }

        return $this->render('finances/depenses.html.twig', [
            'droits' => $droits,
            'budgets' => $lignes,
            'budget_total' => null === $lignes ? 0 : array_sum(array_column($lignes, 'budget')),
            'consomme_total' => null === $lignes ? 0 : array_sum(array_column($lignes, 'consomme')),
            'depenses' => $tout ? $toutes : \array_slice($toutes, 0, 8),
            'nombre' => \count($toutes),
            'tout' => $tout,
            'a_approuver' => $droits['admin'] ? $this->depenses->parStatut(Depense::A_APPROUVER) : [],
            'formulaire' => $formulaire?->createView(),
            'seuil' => $this->reglages->seuilApprobation(),
            'libelle_mois' => Periode::libelle($mois),
            'nom_mois' => Periode::MOIS[(int) $mois->format('n')],
            'periodes' => Periode::choix($maintenant),
            'periode' => $mois->format('Y-m'),
        ]);
    }

    #[Route('/budgets', name: '_budgets', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function budgets(Request $request): Response
    {
        $montants = $this->budgets->montants();
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('budgets-depenses', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $saisis = [];
            foreach (array_keys(Budgets::CATEGORIES) as $cle) {
                $valeur = preg_replace('/\D/', '', (string) $request->request->get($cle, ''));
                if ('' !== $valeur) {
                    $saisis[$cle] = min((int) $valeur, 1_000_000_000);
                }
            }
            $this->budgets->enregistrer($saisis, $this->utilisateur(), new \DateTimeImmutable());
            $this->addFlash('success', 'Budgets du mois enregistrés.');

            return $this->redirectToRoute('app_finances_depenses');
        }

        return $this->render('finances/depenses_budgets.html.twig', [
            'categories' => Budgets::CATEGORIES,
            'montants' => $montants,
            'seuil' => $this->reglages->seuilApprobation(),
        ]);
    }

    #[Route('/{id}', name: '_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Depense $depense, Request $request): Response
    {
        $droits = $this->droitsSur($depense);
        $mois = $depense->getDateDepense();
        [$debut, $fin] = Periode::bornes($mois);
        $budget = null;
        // Dette fournisseur reprise au démarrage : hors budgets (docs/import-donnees.md).
        if ($droits['tresorerie'] && !$depense->isSoldeOuverture()) {
            $ligne = array_values(array_filter(
                Budgets::lignes($this->budgets->montants(), $this->budgets->consommes($debut, $fin)),
                static fn (array $l): bool => $l['cle'] === $depense->getCategorie(),
            ))[0] ?? null;
            if (null !== $ligne) {
                // Avant accord, la dépense n'est pas encore comptée : on montre le budget « après ».
                $apres = $ligne['consomme'] + ($depense->estEngagee() ? 0 : $depense->getMontant());
                $part = $ligne['budget'] > 0 ? (int) round($apres * 100 / $ligne['budget']) : 100;
                $budget = $ligne + ['apres' => $apres, 'part_apres' => $part, 'etat_apres' => Budgets::etat($part), 'compte_deja' => $depense->estEngagee()];
            }
        }

        return $this->render('finances/depense.html.twig', [
            'd' => $depense,
            'etat' => Depenses::etat($depense),
            'droits' => $droits,
            'budget' => $budget,
            'achat' => $this->depenses->infoAchat($depense),
            'type_recu' => Depenses::typeJustificatif($depense->getJustificatif()),
            'categorie' => Budgets::CATEGORIES[$depense->getCategorie()]['libelle'] ?? $depense->getCategorie(),
            'payable' => $droits['saisie'] && Depenses::payable($depense) && !Depenses::bloquee($depense),
            'bloquee' => Depenses::payable($depense) && Depenses::bloquee($depense),
            'a_decider' => $droits['admin'] && Depense::A_APPROUVER === $depense->getStatut(),
            'comptes' => $droits['saisie'] ? $this->comptes->trouverDisponiblesPour($this->utilisateur(), $droits['admin']) : [],
            'seuil' => $this->reglages->seuilApprobation(),
            'depuis' => 'jour' === $request->query->get('depuis') ? 'jour' : null,
        ]);
    }

    #[Route('/{id}/approuver', name: '_approuver', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function approuver(Depense $depense, Request $request): Response
    {
        $this->verifierJeton($request, 'approuver-depense-'.$depense->getId());
        try {
            $this->depenses->approuver($depense, $this->utilisateur(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Dépense approuvée : %s, à payer.', $depense->getObjet()));
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->apresDecision($depense, $request);
    }

    #[Route('/{id}/refuser', name: '_refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function refuser(Depense $depense, Request $request): Response
    {
        $this->verifierJeton($request, 'refuser-depense-'.$depense->getId());
        try {
            $this->depenses->refuser($depense, $this->utilisateur(), (string) $request->request->get('motif', ''), new \DateTimeImmutable());
            $this->addFlash('success', 'Dépense refusée. La personne qui l\'a saisie est prévenue.');
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->apresDecision($depense, $request);
    }

    #[Route('/{id}/payer', name: '_payer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TRESORERIE_SAISIR')]
    public function payer(Depense $depense, Request $request): Response
    {
        $this->verifierJeton($request, 'payer-depense-'.$depense->getId());
        $id = Entree::entier($request->request, 'compte');
        $compte = array_values(array_filter(
            $this->comptes->trouverDisponiblesPour($this->utilisateur(), $this->isGranted('ROLE_ADMIN')),
            static fn (CompteTresorerie $c): bool => $c->getId() === $id,
        ))[0] ?? null;
        if (null === $compte) {
            $this->addFlash('danger', 'Choisissez le compte d\'où part l\'argent.');
        } else {
            try {
                $this->depenses->payer($depense, $compte, $this->utilisateur(), new \DateTimeImmutable());
                $this->addFlash('success', 'Paiement enregistré. Le décaissement attend sa validation dans « À valider ».');
            } catch (\InvalidArgumentException|\LogicException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_finances_depenses_voir', ['id' => $depense->getId()]);
    }

    /** Reçu de la dépense, servi hors du dossier public après contrôle des droits. */
    #[Route('/{id}/recu', name: '_recu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function recu(Depense $depense): Response
    {
        $this->droitsSur($depense);
        $chemin = $this->depenses->cheminJustificatif($depense) ?? throw $this->createNotFoundException('Reçu introuvable.');
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', Depenses::typeJustificatif($chemin));
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'recu-depense-'.$depense->getId().'.'.pathinfo($chemin, \PATHINFO_EXTENSION));
        $reponse->setPrivate();

        return $reponse;
    }

    /**
     * Une dépense se consulte avec le droit de voir la trésorerie, ou par
     * la personne qui l'a saisie.
     *
     * @return array{tresorerie: bool, ca: bool, paiements: bool, marges: bool, admin: bool, saisie: bool}
     */
    private function droitsSur(Depense $depense): array
    {
        $droits = $this->droits();
        if (!$droits['tresorerie'] && $depense->getCreePar() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $droits;
    }

    /**
     * Après une décision prise depuis « Finances du jour » (téléphone), on
     * y revient pour la décision suivante ; sinon on reste sur la dépense.
     */
    private function apresDecision(Depense $depense, Request $request): Response
    {
        return 'jour' === $request->request->get('depuis')
            ? $this->redirectToRoute('app_finances_du_jour')
            : $this->redirectToRoute('app_finances_depenses_voir', ['id' => $depense->getId()]);
    }

    private function verifierJeton(Request $request, string $intention): void
    {
        if (!$this->isCsrfTokenValid($intention, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide : rechargez la page.');
        }
    }

    private function utilisateur(): User
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $utilisateur;
    }
}
