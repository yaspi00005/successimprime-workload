<?php

namespace App\Controller;

use App\Entity\Employes;
use App\Entity\User;
use App\Form\EmployesType;
use App\Repository\EmployesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/employes')]
final class EmployesController extends AbstractController
{
    #[Route(name: 'app_employes_index', methods: ['GET', 'POST'])]
    public function index(EmployesRepository $employesRepository): Response
    {
        return $this->render('employes/index.html.twig', [
            'employes' => $employesRepository->findAll(),
            'rolesDisponibles' => User::getLibellesRoles(),
        ]);
    }

    #[Route('/new', name: 'app_employes_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $employe = new Employes();
        $form = $this->createForm(EmployesType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

         $photo = $form->get('photos')->getData();

        if ($photo) {

            $nomPhoto = uniqid('photo_').'.'.$photo->guessExtension();

            try {
                $photo->move(
                    $this->getParameter('photos_directory'),
                    $nomPhoto
                );

                $employe->setPhotos($nomPhoto);

            } catch (FileException $e) {
                // dd($e->getMessage());
            }
        }

       
        $cin = $form->get('cin')->getData();

        if ($cin) {

            $nomCin = uniqid('cin_').'.'.$cin->guessExtension();

            try {
                $cin->move(
                    $this->getParameter('cin_directory'),
                    $nomCin
                );

                $employe->setCin($nomCin);

            } catch (FileException $e) {
                // dd($e->getMessage());
            }
        }

            $entityManager->persist($employe);
            $entityManager->flush();
            $this->addFlash('success', 'Enregistrement effectué avec succès');

            return $this->redirectToRoute('app_employes_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('employes/new.html.twig', [
            'employe' => $employe,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_employes_show', methods: ['GET'])]
    public function show(Employes $employe): Response
    {
        return $this->render('employes/show.html.twig', [
            'employe' => $employe,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_employes_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Employes $employe, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(EmployesType::class, $employe);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_employes_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('employes/edit.html.twig', [
            'employe' => $employe,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_employes_delete', methods: ['POST'])]
    public function delete(Request $request, Employes $employe, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$employe->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($employe);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_employes_index', [], Response::HTTP_SEE_OTHER);
    }
}
