<?php

namespace App\Controller;

use App\Entity\DecaissementRecurrent;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Form\DecaissementRecurrentType;
use App\Repository\DecaissementRecurrentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Configuration des charges fixes décaissées automatiquement (frais
 * bancaires, remboursement de crédit...). La génération réelle des
 * mouvements se fait via DecaissementRecurrentService, appelé par la
 * commande app:executer-decaissements-recurrents (tâche planifiée) —
 * cet écran ne fait que définir QUOI et QUAND.
 */
#[Route('/decaissements-recurrents', name: 'app_decaissement_recurrent_')]
#[IsGranted('ROLE_ADMIN')]
final class DecaissementRecurrentController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        DecaissementRecurrentRepository $decaissementRecurrentRepository
    ): Response {
        return $this->render('decaissement_recurrent/index.html.twig', [
            'charges' => $decaissementRecurrentRepository->findToutes(),
            'categoriesLabels' => MouvementTresorerie::CATEGORIES_LABELS,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $charge = new DecaissementRecurrent();
        $charge->setDateDebut(new \DateTimeImmutable('today'));

        $form = $this->createForm(DecaissementRecurrentType::class, $charge);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $charge->setCreePar($this->utilisateurConnecte());

            $entityManager->persist($charge);
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'La charge « %s » a été créée. Prochaine échéance : %s.',
                    $charge->getLibelle(),
                    $charge->getProchaineDateExecution()?->format('d/m/Y')
                )
            );

            return $this->redirectToRoute(
                'app_decaissement_recurrent_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('decaissement_recurrent/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        DecaissementRecurrent $charge,
        EntityManagerInterface $entityManager
    ): Response {
        $ancienneFrequence = $charge->getFrequence();
        $ancienJour = $charge->getJourDuMois();
        $ancienMois = $charge->getMoisDeLAnnee();

        $form = $this->createForm(DecaissementRecurrentType::class, $charge);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /*
             * Si l'échéancier (fréquence/jour/mois) a changé, la
             * prochaine date calculée à la création n'est plus
             * valable : on la recalcule à partir d'aujourd'hui pour
             * ne pas déclencher un rattrapage inattendu.
             */
            if (
                $charge->getFrequence() !== $ancienneFrequence
                || $charge->getJourDuMois() !== $ancienJour
                || $charge->getMoisDeLAnnee() !== $ancienMois
            ) {
                $charge->setProchaineDateExecution(
                    $charge->calculerPremiereDateExecution(new \DateTimeImmutable('today'))
                );
            }

            $entityManager->flush();

            $this->addFlash('success', 'La charge a été modifiée.');

            return $this->redirectToRoute(
                'app_decaissement_recurrent_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('decaissement_recurrent/edit.html.twig', [
            'charge' => $charge,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle-actif', name: 'toggle_actif', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActif(
        Request $request,
        DecaissementRecurrent $charge,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'toggle_actif' . $charge->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $charge->setActif(!$charge->isActif());
            $entityManager->flush();

            $this->addFlash(
                'success',
                $charge->isActif()
                    ? 'La charge a été réactivée.'
                    : 'La charge a été suspendue.'
            );
        }

        return $this->redirectToRoute(
            'app_decaissement_recurrent_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        DecaissementRecurrent $charge,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $charge->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $entityManager->remove($charge);
            $entityManager->flush();

            $this->addFlash('success', 'La charge récurrente a été supprimée.');
        }

        return $this->redirectToRoute(
            'app_decaissement_recurrent_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    private function utilisateurConnecte(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        return $user;
    }
}
