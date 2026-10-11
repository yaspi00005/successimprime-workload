<?php

namespace App\Controller;

use App\Service\TableauTresorerie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Trésorerie et dépenses (maquette 31). Le tableau montre
 * tous les comptes : l'administration seulement ; les autres personnes
 * autorisées gardent la liste de leurs mouvements.
 */
#[Route('/finances/tresorerie', name: 'app_tresorerie_')]
#[IsGranted('ROLE_TRESORERIE_VOIR')]
final class TresorerieController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(TableauTresorerie $tableau): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_mouvement_tresorerie_index');
        }

        $donnees = $tableau->donnees(new \DateTimeImmutable());

        return $this->render('tresorerie/index.html.twig', $donnees + [
            'trace' => TableauTresorerie::trace($donnees['courbe'], 600, 220),
        ]);
    }
}
