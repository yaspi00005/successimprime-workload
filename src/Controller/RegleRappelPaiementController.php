<?php

namespace App\Controller;

use App\Entity\RegleRappelPaiement;
use App\Form\RegleRappelPaiementType;
use App\Repository\RappelPaiementEnvoyeRepository;
use App\Repository\RegleRappelPaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Configuration des règles de rappel de paiement (à J+X jours après
 * la commande). L'envoi réel se fait via RappelPaiementService,
 * appelé par la commande app:executer-rappels-paiement (tâche
 * planifiée) — cet écran ne fait que définir QUOI et QUAND.
 */
#[Route('/communications/rappels-paiement', name: 'app_regle_rappel_paiement_')]
#[IsGranted('ROLE_ADMIN')]
final class RegleRappelPaiementController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        RegleRappelPaiementRepository $regleRepository,
        RappelPaiementEnvoyeRepository $rappelRepository
    ): Response {
        return $this->render('regle_rappel_paiement/index.html.twig', [
            'regles' => $regleRepository->findToutes(),
            'rappelsRecents' => $rappelRepository->findRecents(50),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $regle = new RegleRappelPaiement();

        $form = $this->createForm(RegleRappelPaiementType::class, $regle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($regle);
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf('La règle « %s » a été créée.', $regle->getNom())
            );

            return $this->redirectToRoute(
                'app_regle_rappel_paiement_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('regle_rappel_paiement/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        RegleRappelPaiement $regle,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(RegleRappelPaiementType::class, $regle);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'La règle a été modifiée.');

            return $this->redirectToRoute(
                'app_regle_rappel_paiement_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('regle_rappel_paiement/edit.html.twig', [
            'regle' => $regle,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/toggle-actif', name: 'toggle_actif', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleActif(
        Request $request,
        RegleRappelPaiement $regle,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'toggle_actif' . $regle->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $regle->setActif(!$regle->isActif());
            $entityManager->flush();

            $this->addFlash(
                'success',
                $regle->isActif()
                    ? 'La règle a été réactivée.'
                    : 'La règle a été suspendue.'
            );
        }

        return $this->redirectToRoute(
            'app_regle_rappel_paiement_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        RegleRappelPaiement $regle,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $regle->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $entityManager->remove($regle);
            $entityManager->flush();

            $this->addFlash('success', 'La règle de rappel a été supprimée.');
        }

        return $this->redirectToRoute(
            'app_regle_rappel_paiement_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }
}
