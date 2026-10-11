<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use App\Service\Livraisons\Tournee;
use App\Service\SuiviMetiers\PointsLivreur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tournée du livreur et remise signée (L03, mobile), détail d'une
 * livraison pour le bureau (L02). Le livreur ne voit que ses livraisons
 * du jour : adresse, réceptionnaire, articles et montant à encaisser.
 * L'accès à /livraisons demande déjà le droit Livraisons.
 */
#[Route('/livraisons', name: 'app_livraisons_')]
final class TourneeController extends AbstractController
{
    private const CSRF = 'tournee';

    public function __construct(
        private readonly Tournee $tournee,
        private readonly PointsLivreur $points,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/tournee', name: 'tournee', methods: ['GET'])]
    public function tournee(Request $request): Response
    {
        $moi = $this->moi();
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('jour')) ?: new \DateTimeImmutable('today');
        $livreur = $moi;
        if ($this->bureau() && $request->query->get('livreur')) {
            $livreur = $this->em->getRepository(User::class)->find($request->query->getInt('livreur')) ?? $moi;
        }
        $journee = $this->points->journee($livreur, $jour);
        $bons = $journee['bons'];

        return $this->render('tournee/index.html.twig', [
            'bons' => $bons,
            'j' => $journee,
            'jour' => $jour,
            'livreur' => $livreur,
            'livreurs' => $this->bureau() ? $this->tournee->livreurs() : [],
            'faites' => \count(array_filter($bons, static fn (BonLivraison $b): bool => $b->getStatut() === BonLivraison::STATUT_LIVRE)),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/tournee/{id}/etape', name: 'tournee_etape', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function etape(BonLivraison $bon, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->livreurDe($bon);
        try {
            $bon->avancer((string) $request->request->get('etape'), new \DateTimeImmutable());
            $this->em->flush();
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_livraisons_tournee');
    }

    #[Route('/tournee/{id}/remise', name: 'remise', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function remise(BonLivraison $bon, Request $request): Response
    {
        $moi = $this->livreurDe($bon);
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);
            $montant = preg_replace('/\D/', '', (string) $request->request->get('montant'));
            $erreur = $this->tournee->remettre(
                $bon,
                $moi,
                (array) $request->request->all('quantites'),
                array_values(array_filter((array) $request->files->get('photos', []))),
                (string) $request->request->get('signature'),
                (string) $request->request->get('signataire'),
                (string) $request->request->get('mode'),
                $montant !== '' ? (int) $montant : null,
                new \DateTimeImmutable(),
                is_numeric($request->request->get('latitude')) ? (float) $request->request->get('latitude') : null,
                is_numeric($request->request->get('longitude')) ? (float) $request->request->get('longitude') : null,
            );
            if ($erreur === null) {
                return $this->redirectToRoute('app_livraisons_tournee_terminee', ['id' => $bon->getId()]);
            }
            $this->addFlash('danger', $erreur);
        }

        return $this->render('tournee/remise.html.twig', [
            'b' => $bon,
            'reste' => (int) $bon->getCommande()?->getResteAPayer(),
            'maxRemise' => array_combine(array_map(static fn ($l) => $l->getId(), $bon->getLignes()->toArray()), array_map(Tournee::maxRemise(...), $bon->getLignes()->toArray())),
            'jeton' => self::CSRF,
        ]);
    }

    /** Livraison terminée (W04) : heures, preuve et points gagnés. */
    #[Route('/tournee/{id}/terminee', name: 'tournee_terminee', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function terminee(BonLivraison $bon): Response
    {
        if (!$this->bureau()) {
            $this->livreurDe($bon);
        }
        if ($bon->getStatut() !== BonLivraison::STATUT_LIVRE) {
            return $this->redirectToRoute('app_livraisons_tournee');
        }
        $suivante = null;
        if ($bon->getLivreur() !== null && $bon->getPrevuLe() !== null) {
            foreach ($this->tournee->duJour($bon->getPrevuLe(), $bon->getLivreur()) as $b) {
                if ($b->getStatut() === BonLivraison::STATUT_VALIDE) {
                    $suivante = $b;
                    break;
                }
            }
        }

        return $this->render('tournee/terminee.html.twig', [
            'b' => $bon,
            'p' => $this->points->detail($bon),
            'retard' => PointsLivreur::retard($bon),
            'suivante' => $suivante,
        ]);
    }

    /** Planning du jour de toutes les tournées (L01), pour le bureau. */
    #[Route('/planning', name: 'planning', methods: ['GET'])]
    public function planning(Request $request): Response
    {
        if (!$this->bureau()) {
            throw $this->createAccessDeniedException('Le planning des livraisons est réservé au bureau.');
        }
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('jour')) ?: new \DateTimeImmutable('today');
        $planning = $this->tournee->planning($jour);
        $prevenir = [];
        foreach ($planning['tournees'] as $t) {
            foreach ($t['bons'] as $b) {
                $tel = $b->getTelephoneReceptionnaire() ?? $b->getCommande()?->getClients()?->getTelephone();
                if ($b->getStatut() === BonLivraison::STATUT_VALIDE && $tel) {
                    $prevenir[] = ['bon' => $b, 'numero' => UtilisateursController::numeroWhatsApp($tel), 'texte' => \sprintf('Bonjour, votre livraison %s arrive aujourd\'hui%s. Merci d\'être joignable. Succès Imprim', $b->getNumero(), $b->getCreneau() ? ' entre '.str_replace('-', ' et ', $b->getCreneau()) : '')];
                }
            }
        }

        return $this->render('tournee/planning.html.twig', [
            'jour' => $jour,
            'p' => $planning,
            'depot' => $this->tournee->depot(),
            'prevenir' => $prevenir,
            'voirMontants' => $this->isGranted('ROLE_PAIEMENT_VOIR'),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/planning/reordonner', name: 'planning_reordonner', methods: ['POST'])]
    public function reordonner(Request $request): Response
    {
        $this->verifierJeton($request);
        if (!$this->bureau()) {
            throw $this->createAccessDeniedException();
        }
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('jour')) ?: new \DateTimeImmutable('today');
        $livreur = $this->em->getRepository(User::class)->find($request->request->getInt('livreur'));
        if ($livreur !== null) {
            $n = $this->tournee->reordonner($jour, $livreur);
            $this->addFlash('success', \sprintf('Tournée de %s réordonnée au plus court (%d arrêt%s restant%3$s).', \App\Service\Pointage\Pointages::nom($livreur), $n, $n > 1 ? 's' : ''));
        }

        return $this->redirectToRoute('app_livraisons_planning', ['jour' => $jour->format('Y-m-d')]);
    }

    /** Détail d'une livraison pour le bureau (L02). */
    #[Route('/bl/{id}', name: 'bl', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(BonLivraison $bon, CompteTresorerieRepository $comptes): Response
    {
        if (!$this->bureau()) {
            throw $this->createAccessDeniedException('Le détail des livraisons est réservé au bureau.');
        }

        return $this->render('tournee/detail.html.twig', [
            'b' => $bon,
            'reste' => (int) $bon->getCommande()?->getResteAPayer(),
            'whatsapp' => ($tel = $bon->getTelephoneReceptionnaire() ?? $bon->getCommande()?->getClients()?->getTelephone()) ? UtilisateursController::numeroWhatsApp($tel) : null,
            'livreurs' => $this->tournee->livreurs(),
            'comptes' => $this->isGranted('ROLE_PAIEMENT_ENCAISSER') ? $comptes->findActifs() : [],
            'points' => $this->points->detail($bon),
            'retard' => PointsLivreur::retard($bon),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/bl/{id}/planifier', name: 'bl_planifier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function planifier(BonLivraison $bon, Request $request): Response
    {
        $this->verifierJeton($request);
        if (!$this->bureau()) {
            throw $this->createAccessDeniedException();
        }
        $livreur = $request->request->getInt('livreur') > 0 ? $this->em->getRepository(User::class)->find($request->request->getInt('livreur')) : null;
        $bon->setLivreur($livreur)
            ->setPrevuLe(\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('jour')) ?: null)
            ->setCreneau((string) $request->request->get('creneau'))
            ->setOrdreTournee($request->request->getInt('ordre') > 0 ? $request->request->getInt('ordre') : null);
        $this->em->flush();
        $this->addFlash('success', 'Livraison planifiée.');

        return $this->redirectToRoute('app_livraisons_bl', ['id' => $bon->getId()]);
    }

    /** Retard justifié ou erreur de livraison (bureau) : change les points du livreur. */
    #[Route('/bl/{id}/points/{action}', name: 'bl_points', requirements: ['id' => '\d+', 'action' => 'justifier|erreur|annuler_retard|annuler_erreur'], methods: ['POST'])]
    public function corrigerPoints(BonLivraison $bon, string $action, Request $request): Response
    {
        $this->verifierJeton($request);
        if (!$this->bureau()) {
            throw $this->createAccessDeniedException();
        }
        $motif = trim((string) $request->request->get('motif'));
        if (\in_array($action, ['justifier', 'erreur'], true) && $motif === '') {
            $this->addFlash('danger', 'Indiquez le motif.');

            return $this->redirectToRoute('app_livraisons_bl', ['id' => $bon->getId()]);
        }
        $signe = $motif !== '' ? \sprintf('%s (%s, %s)', $motif, \App\Service\Pointage\Pointages::nom($this->moi()), (new \DateTimeImmutable())->format('d/m H:i')) : null;
        match ($action) {
            'justifier' => $bon->setRetardJustifie($signe),
            'erreur' => $bon->setErreurLivraison($signe),
            'annuler_retard' => $bon->setRetardJustifie(null),
            'annuler_erreur' => $bon->setErreurLivraison(null),
        };
        $this->em->flush();
        $this->addFlash('success', 'Points du livreur mis à jour.');

        return $this->redirectToRoute('app_livraisons_bl', ['id' => $bon->getId()]);
    }

    #[Route('/bl/{id}/encaissement', name: 'bl_encaissement', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validerEncaissement(BonLivraison $bon, Request $request, CompteTresorerieRepository $comptes): Response
    {
        $this->verifierJeton($request);
        $this->denyAccessUnlessGranted('ROLE_PAIEMENT_ENCAISSER');
        $compte = $comptes->find($request->request->getInt('compte'));
        $erreur = $compte === null ? 'Choisissez le compte qui reçoit l\'argent.' : $this->tournee->validerEncaissement($bon, $compte, $this->moi());
        $erreur !== null ? $this->addFlash('danger', $erreur) : $this->addFlash('success', 'Encaissement validé : le paiement est entré en trésorerie.');

        return $this->redirectToRoute('app_livraisons_bl', ['id' => $bon->getId()]);
    }

    /** Photo ou signature : le livreur de la livraison ou le bureau. */
    #[Route('/bl/{id}/preuve', name: 'bl_preuve', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preuve(BonLivraison $bon, Request $request): Response
    {
        if (!$this->bureau() && $bon->getLivreur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $chemin = $this->tournee->cheminPreuve($bon, (string) $request->query->get('f'));
        if ($chemin === null) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setPrivate();

        return $reponse;
    }

    /** Le bureau : ceux qui gèrent les commandes. */
    private function bureau(): bool
    {
        return $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_COMMANDE');
    }

    private function livreurDe(BonLivraison $bon): User
    {
        $moi = $this->moi();
        if ($bon->getLivreur() !== $moi && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Cette livraison est confiée à un autre livreur.');
        }

        return $moi;
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        \assert($moi instanceof User);

        return $moi;
    }
}
