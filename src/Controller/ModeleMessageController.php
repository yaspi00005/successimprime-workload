<?php

namespace App\Controller;

use App\Entity\ModeleMessage;
use App\Form\ModeleMessageType;
use App\Repository\ModeleMessageRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Modèles de message (SMS/email/WhatsApp) réutilisés par les
 * campagnes et par les règles de rappel de paiement.
 */
#[Route('/communications/modeles', name: 'app_modele_message_')]
#[IsGranted('ROLE_ADMIN')]
final class ModeleMessageController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ModeleMessageRepository $modeleMessageRepository
    ): Response {
        return $this->render('modele_message/index.html.twig', [
            'modeles' => $modeleMessageRepository->findTous(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $modele = new ModeleMessage();

        $form = $this->createForm(ModeleMessageType::class, $modele);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($modele);
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf('Le modèle « %s » a été créé.', $modele->getNom())
            );

            return $this->redirectToRoute(
                'app_modele_message_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('modele_message/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ModeleMessage $modele,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(ModeleMessageType::class, $modele);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le modèle a été modifié.');

            return $this->redirectToRoute(
                'app_modele_message_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('modele_message/edit.html.twig', [
            'modele' => $modele,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        ModeleMessage $modele,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $modele->getId(),
            $request->getPayload()->getString('_token')
        )) {
            try {
                $entityManager->remove($modele);
                $entityManager->flush();

                $this->addFlash('success', 'Le modèle a été supprimé.');
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash(
                    'danger',
                    'Impossible de supprimer ce modèle : il est utilisé par au moins une règle de rappel de paiement. Désactivez-le plutôt, ou modifiez la règle concernée.'
                );
            }
        }

        return $this->redirectToRoute(
            'app_modele_message_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }
}
