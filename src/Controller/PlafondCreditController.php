<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\NotificationsEquipe\AccordsPlafond;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Plafond de crédit dépassé (maquette NT02, docs/notifications-equipe.md) :
 * « Accord direction » depuis la fenêtre bloquante, et la page où la
 * direction décide.
 */
final class PlafondCreditController extends AbstractController
{
    public const CSRF = 'plafond-credit';

    public function __construct(private readonly AccordsPlafond $accords)
    {
    }

    /** « Accord direction » : la direction l'accorde tout de suite ; les autres le demandent. */
    #[Route('/commandes/{id}/plafond/accord', name: 'app_plafond_accord', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function accord(#[MapEntity(id: 'id')] Commandes $commande, Request $request, EntityManagerInterface $em): Response
    {
        $moi = $this->moi($request);
        $maintenant = new \DateTimeImmutable();
        if (!$this->accords->bloquee($commande)) {
            $this->addFlash('info', 'Cette commande ne dépasse plus le plafond du client.');
        } elseif ($this->isGranted('ROLE_ADMIN')) {
            $this->accords->decider($commande, true, $moi, null, $maintenant);
            $em->flush();
            $this->addFlash('success', 'Accord de la direction noté pour '.$commande->getNumero().' : vous pouvez confirmer la commande.');
        } elseif ($this->accords->demandeEnCours($commande)) {
            $this->addFlash('info', 'La demande est déjà chez la direction. Vous serez prévenu de sa réponse.');
        } else {
            $this->accords->demander($commande, $moi, $maintenant);
            $em->flush();
            $this->addFlash('success', 'Demande envoyée à la direction. Vous serez prévenu de sa réponse.');
        }

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()]);
    }

    /** Page de décision de la direction (bouton « Décider » de la notification). */
    #[Route('/commandes/{id}/plafond', name: 'app_plafond_decider', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function decider(#[MapEntity(id: 'id')] Commandes $commande, Request $request, UserRepository $utilisateurs): Response
    {
        $analyse = $this->accords->analyser($commande);
        $demandeur = $utilisateurs->find($request->query->getInt('par'));

        return $this->render('notification/plafond_decider.html.twig', [
            'commande' => $commande,
            'analyse' => $analyse,
            'texte' => AccordsPlafond::texte($analyse, $commande->getClients()?->getNomComplet() ?? 'Le client', true),
            'demandeur' => $demandeur,
            'enCours' => $this->accords->demandeEnCours($commande),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/commandes/{id}/plafond/decision', name: 'app_plafond_decision', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function decision(#[MapEntity(id: 'id')] Commandes $commande, Request $request, EntityManagerInterface $em, UserRepository $utilisateurs): Response
    {
        $moi = $this->moi($request);
        $accord = 'accorder' === $request->request->get('decision');
        $this->accords->decider($commande, $accord, $moi, $utilisateurs->find($request->request->getInt('par')), new \DateTimeImmutable());
        $em->flush();
        $this->addFlash($accord ? 'success' : 'warning', $accord ? 'Accord donné : la commande '.$commande->getNumero().' peut être confirmée.' : 'Refus noté : un acompte sera demandé au client.');

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()]);
    }

    private function moi(Request $request): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User || !$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }

        return $moi;
    }
}
