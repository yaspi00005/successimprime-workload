<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\User;
use App\Service\Commandes\DuplicationCommande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * « Dupliquer » une commande (DuplicationCommande) : même client, mêmes
 * lignes, mêmes prix et mêmes fichiers ; la copie part « à confirmer ».
 */
final class DuplicationCommandeController extends AbstractController
{
    #[Route('/commandes/{id}/dupliquer', name: 'app_commandes_dupliquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_COMMANDE')]
    public function dupliquer(Commandes $commande, Request $request, DuplicationCommande $duplication): Response
    {
        if (!$this->isCsrfTokenValid('dupliquer-commande-'.$commande->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $par = $this->getUser();
        try {
            $copie = $duplication->dupliquer($commande, $par instanceof User ? $par : null, new \DateTimeImmutable());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()]);
        }
        $this->addFlash('success', \sprintf('Commande %s créée : copie de %s, mêmes prix et mêmes fichiers. Vérifiez-la puis confirmez-la.', $copie->getNumero(), $commande->getNumero()));

        return $this->redirectToRoute('app_commandes_show', ['id' => $copie->getId()]);
    }
}
