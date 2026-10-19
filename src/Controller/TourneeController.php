<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use App\Security\LivreurVoter;
use App\Service\Http\Entree;
use App\Service\Livraisons\SuiviLivraisons;
use App\Service\Livraisons\Tournee;
use App\Service\NotificationService;
use App\Service\SuiviMetiers\PointsLivreur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tournée du livreur et remise signée (L03, mobile), échec avec sa raison,
 * « Mes livraisons » ; détail d'une livraison (L02), planning (L01) et
 * « Suivi des livraisons » pour le bureau. Le livreur ne voit que ses
 * livraisons : adresse, téléphone, articles et montant à encaisser
 * (LivreurSeulSubscriber lui ferme le reste). L'accès à /livraisons
 * demande déjà le droit Livraisons (le suivi : commandes ou prépresse).
 */
#[Route('/livraisons', name: 'app_livraisons_')]
final class TourneeController extends AbstractController
{
    public const CSRF = 'tournee';

    public function __construct(
        private readonly Tournee $tournee,
        private readonly PointsLivreur $points,
        private readonly EntityManagerInterface $em,
        private readonly SuiviLivraisons $suivi,
        private readonly NotificationService $notifications,
    ) {
    }

    #[Route('/tournee', name: 'tournee', methods: ['GET'])]
    public function tournee(Request $request): Response
    {
        $moi = $this->moi();
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('jour')) ?: new \DateTimeImmutable('today');
        $livreur = $moi;
        if ($this->bureau() && $request->query->get('livreur')) {
            $livreur = $this->em->getRepository(User::class)->find((int) $request->query->get('livreur')) ?? $moi;
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
            'echecs' => \count(array_filter($bons, static fn (BonLivraison $b): bool => $b->etatSuivi() === BonLivraison::ETAT_ECHEC)),
            'jeton' => self::CSRF,
        ]);
    }

    /**
     * Échec : le livreur n'a pas pu remettre. La raison est obligatoire ;
     * le bureau est prévenu pour replanifier.
     */
    #[Route('/tournee/{id}/echec', name: 'tournee_echec', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function echec(BonLivraison $bon, Request $request): Response
    {
        $this->verifierJeton($request);
        $moi = $this->livreurDe($bon);
        $motif = trim((string) $request->request->get('motif'));
        $precision = trim(mb_substr((string) $request->request->get('precision'), 0, 200));
        if (!\in_array($motif, BonLivraison::MOTIFS_ECHEC, true) || ('Autre' === $motif && '' === $precision)) {
            $this->addFlash('danger', 'Choisissez la raison de l\'échec (avec une précision pour « Autre »).');

            return $this->redirectToRoute('app_livraisons_tournee');
        }
        try {
            $bon->echouer($moi, 'Autre' === $motif ? $precision : $motif.('' !== $precision ? ' : '.$precision : ''), new \DateTimeImmutable());
            $client = $bon->getCommande()?->getClients()?->getNomComplet();
            $this->notifications->notifierRoles(['ROLE_COMMANDE', 'ROLE_ADMIN'], \sprintf('Livraison %s%s non aboutie (%s) : à replanifier.', $bon->getNumero(), $client ? ' ('.$client.')' : '', $bon->getEchecMotif()), 'app_livraisons_bl', ['id' => $bon->getId()], $moi);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Échec noté pour %s : le bureau est prévenu.', $bon->getNumero()));
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_livraisons_tournee');
    }

    /** Nouveau passage après un échec, le même jour (le client rappelle). */
    #[Route('/tournee/{id}/reessayer', name: 'tournee_reessayer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reessayer(BonLivraison $bon, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->livreurDe($bon);
        if ($bon->etatSuivi() === BonLivraison::ETAT_ECHEC) {
            $bon->reprendre();
            $this->em->flush();
        }

        return $this->redirectToRoute('app_livraisons_tournee');
    }

    /** Mes livraisons : celles que le livreur a remises ou manquées, sans montant. */
    #[Route('/mes-livraisons', name: 'mes_livraisons', methods: ['GET'])]
    public function mesLivraisons(Request $request): Response
    {
        $this->denyAccessUnlessGranted(LivreurVoter::LIVREUR);
        $moi = $this->moi();
        $jours = 90 === Entree::entier($request->query, 'jours') ? 90 : 30;
        $depuis = (new \DateTimeImmutable('today'))->modify(\sprintf('-%d days', $jours - 1));
        $vues = SuiviLivraisons::vuesDuLivreur($this->suivi->duLivreur($moi, $depuis), $moi);
        $parJour = [];
        foreach ($vues as $v) {
            $parJour[$v['le']->format('Y-m-d')][] = $v;
        }

        return $this->render('tournee/mes_livraisons.html.twig', [
            'parJour' => $parJour,
            'jours' => $jours,
            'livrees' => \count(array_filter($vues, static fn (array $v): bool => $v['etat'] === BonLivraison::ETAT_LIVREE)),
            'echecs' => \count(array_filter($vues, static fn (array $v): bool => $v['etat'] === BonLivraison::ETAT_ECHEC)),
        ]);
    }

    /**
     * Suivi des livraisons (Direction, caisse, prépresse) : livrée ou pas,
     * à quel moment, par qui, reçue par qui ; filtres jour, livreur, état.
     */
    #[Route('/suivi', name: 'suivi', methods: ['GET'])]
    public function suiviLivraisons(Request $request): Response
    {
        if (!$this->isGranted('ROLE_COMMANDE') && !$this->isGranted('ROLE_PREPRESSE')) {
            throw $this->createAccessDeniedException('Le suivi des livraisons est réservé au bureau, à la caisse et à la prépresse.');
        }
        $aujourdhui = new \DateTimeImmutable('today');
        $du = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('du')) ?: $aujourdhui->modify('-6 days');
        $au = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('au')) ?: $aujourdhui;
        if ($au < $du) {
            [$du, $au] = [$au, $du];
        }
        $livreurs = $this->tournee->livreurs();
        $livreur = null;
        foreach ($livreurs as $l) {
            if ($l->getId() === Entree::entier($request->query, 'livreur')) {
                $livreur = $l;
            }
        }
        $etat = (string) $request->query->get('etat');
        $etat = isset(BonLivraison::ETATS_SUIVI[$etat]) && BonLivraison::ETAT_ANNULEE !== $etat ? $etat : null;
        $bons = $this->suivi->rechercher($du, $au, $livreur, $etat);

        return $this->render('tournee/suivi.html.twig', [
            'bons' => $bons,
            'du' => $du,
            'au' => $au,
            'aujourdhui' => $aujourdhui,
            'livreur' => $livreur,
            'livreurs' => $livreurs,
            'etat' => $etat,
            'compteurs' => $this->suivi->compter($du, $au, $livreur),
            'limite' => \count($bons) >= SuiviLivraisons::LIMITE,
            'voirDetail' => $this->bureau(),
        ]);
    }

    #[Route('/tournee/{id}/etape', name: 'tournee_etape', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function etape(BonLivraison $bon, Request $request): Response
    {
        $this->verifierJeton($request);
        $moi = $this->livreurDe($bon);
        try {
            $maintenant = new \DateTimeImmutable();
            $bon->avancer((string) $request->request->get('etape'), $maintenant);
            // Le client suit son livreur (maquette DB10) : prénom, téléphone et, si le téléphone la donne, sa position.
            Tournee::suiviLivreur($bon, $bon->getLivreur() ?? $moi);
            if (is_numeric($request->request->get('latitude')) && is_numeric($request->request->get('longitude'))) {
                $bon->noterPositionLivreur((float) $request->request->get('latitude'), (float) $request->request->get('longitude'), $maintenant);
            }
            $this->em->flush();
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_livraisons_tournee');
    }

    /**
     * Code de réception tapé par le livreur (maquettes DB10, DB11) : juste
     * ou faux, et les essais qui restent. Le code lui-même n'est jamais
     * montré au livreur.
     */
    #[Route('/tournee/{id}/code', name: 'tournee_code', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function code(BonLivraison $bon, Request $request): JsonResponse
    {
        $this->verifierJeton($request);
        $this->livreurDe($bon);
        if ($bon->getStatut() !== BonLivraison::STATUT_VALIDE) {
            return new JsonResponse(['ok' => false, 'message' => 'Cette livraison n\'est pas en cours.'], 409);
        }
        $ok = $bon->verifierCode((string) $request->request->get('code'), new \DateTimeImmutable());
        $this->em->flush();
        $restants = $bon->essaisCodeRestants();

        return new JsonResponse(['ok' => $ok, 'restants' => $restants, 'message' => $ok
            ? 'Code correct : c\'est bien le client.'
            : (0 === $restants ? 'Trop d\'essais : faites signer le client.' : \sprintf('Code faux. Encore %d essai%s.', $restants, $restants > 1 ? 's' : ''))]);
    }

    /** Position du livreur pendant la tournée, envoyée par sa page « Ma tournée » (suivi du client, maquette DB10). */
    #[Route('/tournee/position', name: 'tournee_position', methods: ['POST'])]
    public function position(Request $request): JsonResponse
    {
        $this->verifierJeton($request);
        if (!is_numeric($request->request->get('latitude')) || !is_numeric($request->request->get('longitude'))) {
            return new JsonResponse(['ok' => false], 400);
        }
        $n = $this->tournee->noterPosition($this->moi(), (float) $request->request->get('latitude'), (float) $request->request->get('longitude'), new \DateTimeImmutable());

        return new JsonResponse(['ok' => true, 'livraisons' => $n]);
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
        $livreur = $this->em->getRepository(User::class)->find((int) $request->request->get('livreur'));
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
        $livreur = (int) $request->request->get('livreur') > 0 ? $this->em->getRepository(User::class)->find((int) $request->request->get('livreur')) : null;
        $bon->setLivreur($livreur)
            ->setPrevuLe(\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('jour')) ?: null)
            ->setCreneau((string) $request->request->get('creneau'))
            ->setOrdreTournee((int) $request->request->get('ordre') > 0 ? (int) $request->request->get('ordre') : null);
        // Après un échec, la nouvelle planification remet la livraison dans la tournée.
        $replanifiee = $bon->etatSuivi() === BonLivraison::ETAT_ECHEC && $bon->getPrevuLe() !== null;
        if ($replanifiee) {
            $bon->reprendre();
        }
        $this->em->flush();
        $this->addFlash('success', $replanifiee ? 'Livraison replanifiée : elle revient dans la tournée.' : 'Livraison planifiée.');

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
        $compte = $comptes->find((int) $request->request->get('compte'));
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
