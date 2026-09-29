<?php

namespace App\Controller;

use App\Entity\Fournisseurs;
use App\Form\FournisseursType;
use App\Repository\FournisseursRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fournisseurs')]
final class FournisseursController extends AbstractController
{
    #[Route('', name: 'app_fournisseur_index', methods: ['GET'])]
    public function index(
        FournisseursRepository $fournisseursRepository
    ): Response {
        return $this->render('fournisseurs/index.html.twig', [
            'fournisseurs' => $fournisseursRepository->findBy(
                [],
                ['nom' => 'ASC']
            ),
        ]);
    }

    #[Route('/new', name: 'app_fournisseur_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $fournisseur = new Fournisseurs();
        $form = $this->createForm(FournisseursType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->persist($fournisseur);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash(
                    'error',
                    'Un fournisseur portant ce nom existe déjà.'
                );

                return $this->render('fournisseurs/new.html.twig', [
                    'form' => $form,
                ]);
            }

            $this->addFlash(
                'success',
                sprintf(
                    'Le fournisseur « %s » a été ajouté avec succès.',
                    $fournisseur->getNom()
                )
            );

            return $this->redirectToRoute(
                'app_fournisseur_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('fournisseurs/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_fournisseur_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(Fournisseurs $fournisseur): Response
    {
        return $this->render('fournisseurs/show.html.twig', [
            'fournisseur' => $fournisseur,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'app_fournisseur_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function edit(
        Request $request,
        Fournisseurs $fournisseur,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(FournisseursType::class, $fournisseur);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash(
                    'error',
                    'Un autre fournisseur porte déjà ce nom.'
                );

                return $this->render('fournisseurs/edit.html.twig', [
                    'fournisseur' => $fournisseur,
                    'form' => $form,
                ]);
            }

            $this->addFlash(
                'success',
                'Le fournisseur a été modifié avec succès.'
            );

            return $this->redirectToRoute(
                'app_fournisseur_show',
                ['id' => $fournisseur->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('fournisseurs/edit.html.twig', [
            'fournisseur' => $fournisseur,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}/delete',
        name: 'app_fournisseur_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        Request $request,
        Fournisseurs $fournisseur,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $fournisseur->getId(),
            $request->getPayload()->getString('_token')
        )) {
            if (!$fournisseur->getAchats()->isEmpty()) {
                $this->addFlash(
                    'error',
                    'Ce fournisseur a des achats liés et ne peut pas être supprimé. Désactivez-le à la place.'
                );

                return $this->redirectToRoute(
                    'app_fournisseur_show',
                    ['id' => $fournisseur->getId()],
                    Response::HTTP_SEE_OTHER
                );
            }

            $entityManager->remove($fournisseur);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'Le fournisseur a été supprimé.'
            );
        }

        return $this->redirectToRoute(
            'app_fournisseur_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route(
        '/{id}/toggle-actif',
        name: 'app_fournisseur_toggle_actif',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function toggleActif(
        Request $request,
        Fournisseurs $fournisseur,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'toggle_actif' . $fournisseur->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $fournisseur->setActif(!$fournisseur->isActif());
            $entityManager->flush();

            $this->addFlash(
                'success',
                $fournisseur->isActif()
                    ? 'Le fournisseur a été réactivé.'
                    : 'Le fournisseur a été désactivé.'
            );
        }

        return $this->redirectToRoute(
            'app_fournisseur_show',
            ['id' => $fournisseur->getId()],
            Response::HTTP_SEE_OTHER
        );
    }
}
