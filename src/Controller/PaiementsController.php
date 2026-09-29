<?php

namespace App\Controller;

use App\Entity\Paiements;
use App\Entity\User;
use App\Form\PaiementsType;
use App\Repository\PaiementsRepository;
use App\Service\PaiementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/paiements')]
final class PaiementsController extends AbstractController
{
    private const LIMITE_PAR_DEFAUT = 100;
    private const LIMITE_RECHERCHE = 500;

    #[Route(name: 'app_paiements_index', methods: ['GET'])]
    public function index(Request $request, PaiementsRepository $paiementsRepository): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        $limite = $q !== ''
            ? self::LIMITE_RECHERCHE
            : self::LIMITE_PAR_DEFAUT;

        return $this->render('paiements/index.html.twig', [
            'paiements' => $paiementsRepository->rechercher($q, $limite),
            'q' => $q,
            'limite' => $limite,
        ]);
    }

    #[Route('/new', name: 'app_paiements_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $paiement = new Paiements();
        $form = $this->createForm(PaiementsType::class, $paiement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($paiement);
            $entityManager->flush();

            return $this->redirectToRoute('app_paiements_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('paiements/new.html.twig', [
            'paiement' => $paiement,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_paiements_show', methods: ['GET'])]
    public function show(Paiements $paiement): Response
    {
        return $this->render('paiements/show.html.twig', [
            'paiement' => $paiement,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_paiements_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Paiements $paiement, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(PaiementsType::class, $paiement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_paiements_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('paiements/edit.html.twig', [
            'paiement' => $paiement,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_paiements_delete', methods: ['POST'])]
    public function delete(Request $request, Paiements $paiement, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$paiement->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($paiement);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_paiements_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/valider', name: 'app_paiements_valider', methods: ['POST'])]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function valider(Request $request, Paiements $paiement, PaiementService $paiementService): Response
    {
        if ($this->isCsrfTokenValid('paiement_valider' . $paiement->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $paiementService->valider($paiement, $this->utilisateurConnecte());

                $this->addFlash('success', 'Le paiement a été validé et le compte crédité.');
            } catch (\Throwable $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_paiements_show', ['id' => $paiement->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/rejeter', name: 'app_paiements_rejeter', methods: ['POST'])]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function rejeter(Request $request, Paiements $paiement, PaiementService $paiementService): Response
    {
        if ($this->isCsrfTokenValid('paiement_rejeter' . $paiement->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $motif = trim((string) $request->getPayload()->getString('motif'));

                $paiementService->rejeter($paiement, $motif, $this->utilisateurConnecte());

                $this->addFlash('success', 'Le paiement a été rejeté.');
            } catch (\Throwable $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_paiements_show', ['id' => $paiement->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/annuler', name: 'app_paiements_annuler', methods: ['POST'])]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function annuler(Request $request, Paiements $paiement, PaiementService $paiementService): Response
    {
        if ($this->isCsrfTokenValid('paiement_annuler' . $paiement->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $motif = trim((string) $request->getPayload()->getString('motif'));

                $paiementService->annuler($paiement, $motif, $this->utilisateurConnecte());

                $this->addFlash('success', 'Le paiement a été annulé et le compte débité.');
            } catch (\Throwable $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('app_paiements_show', ['id' => $paiement->getId()], Response::HTTP_SEE_OTHER);
    }

    private function utilisateurConnecte(): User
    {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        return $utilisateur;
    }
}
