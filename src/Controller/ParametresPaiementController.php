<?php

namespace App\Controller;

use App\Entity\ParametresPaiement;
use App\Form\ParametresPaiementType;
use App\Repository\ParametresPaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/parametres/paiement')]
#[IsGranted('ROLE_ADMIN')]
final class ParametresPaiementController extends AbstractController
{
    #[Route(name: 'app_parametres_paiement_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ParametresPaiementRepository $repository,
        EntityManagerInterface $entityManager
    ): Response {
        $parametres = $repository->recuperer();

        $form = $this->createForm(ParametresPaiementType::class, $parametres);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($parametres);
            $entityManager->flush();

            $this->addFlash('success', 'Les paramètres de paiement ont été enregistrés.');

            return $this->redirectToRoute('app_parametres_paiement_edit');
        }

        return $this->render('parametres_paiement/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
