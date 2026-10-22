<?php

namespace App\Controller;

use App\Entity\Employes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anciennes adresses des fiches employés : elles mènent aux nouveaux
 * écrans (maquettes E01 à E03). Un employé ne se supprime plus : son
 * accès se retire, son dossier et son historique restent.
 */
#[Route('/employes')]
final class EmployesController extends AbstractController
{
    #[Route(name: 'app_employes_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_equipe_employes_index', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/new', name: 'app_employes_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->redirectToRoute('app_equipe_employes_nouveau', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{id}', name: 'app_employes_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Employes $employe): Response
    {
        return $this->redirectToRoute('app_equipe_employes_dossier', ['id' => $employe->getId()], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/{id}/edit', name: 'app_employes_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(Employes $employe): Response
    {
        return $this->redirectToRoute('app_equipe_employes_modifier', ['id' => $employe->getId()], Response::HTTP_MOVED_PERMANENTLY);
    }
}
