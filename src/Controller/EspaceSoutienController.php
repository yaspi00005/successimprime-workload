<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client : fermer la carte « Nous soutenir » du tableau de bord
 * (SoutienSite). Propre à la personne connectée : les autres personnes
 * de l'entreprise la gardent. Permis aussi à un compte « Lecture ·
 * suivi » (DroitsEspace::ENVOIS_LECTURE), puisque cela ne regarde que lui.
 */
final class EspaceSoutienController extends AbstractController
{
    #[Route('/espace/nous-soutenir/masquer', name: 'app_espace_soutien_masquer', methods: ['POST'])]
    public function masquer(Request $request, CompteClientRepository $comptes, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient) {
            throw $this->createAccessDeniedException();
        }
        $compte->masquerSoutien(new \DateTimeImmutable());
        $em->flush();

        if ('fetch' === $request->headers->get('X-Requested-With')) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirectToRoute('app_espace_accueil');
    }
}
