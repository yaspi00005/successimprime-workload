<?php

namespace App\Controller;

use App\Entity\CommandesDetails;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anciennes adresses des lignes de commande. Une ligne se crée, se modifie
 * et se retire toujours dans sa commande (recalcul des montants, du coût de
 * revient et des contrôles) : ces adresses renvoient vers la commande.
 */
#[Route('/commandes/details')]
final class CommandesDetailsController extends AbstractController
{
    #[Route('/new', name: 'app_commandes_details_new', methods: ['GET', 'POST'])]
    public function new(): Response
    {
        return $this->redirectToRoute('app_commandes_new', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'app_commandes_details_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(CommandesDetails $ligne): Response
    {
        return $this->versCommande($ligne, 'app_commandes_show');
    }

    #[Route('/{id}/edit', name: 'app_commandes_details_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(CommandesDetails $ligne): Response
    {
        return $this->versCommande($ligne, 'app_commandes_edit');
    }

    private function versCommande(CommandesDetails $ligne, string $route): Response
    {
        $commande = $ligne->getCommande();
        if (null === $commande) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute($route, ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
    }
}
