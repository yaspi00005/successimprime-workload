<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Entity\OrdreProduction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * QR des étiquettes de colis (poste machiniste, OP05) : le livreur le
 * scanne et arrive sur la remise de sa livraison ; le bureau, sur la
 * livraison de la commande. Réservé au droit Livraisons (security.yaml,
 * ^/livraisons), fermé sur le site en ligne comme la tournée.
 */
final class ColisController extends AbstractController
{
    #[Route('/livraisons/tournee/colis/{numero}', name: 'app_livraisons_tournee_colis', requirements: ['numero' => '[A-Za-z0-9\-]{3,50}'], methods: ['GET'])]
    public function colis(string $numero, EntityManagerInterface $em): Response
    {
        $ordre = $em->getRepository(OrdreProduction::class)->findOneBy(['numeroEtiquette' => strtoupper($numero)]);
        $commande = $ordre?->getCommandeDetail()?->getCommande();
        if ($commande === null) {
            throw $this->createNotFoundException('Colis inconnu.');
        }
        $bon = $em->getRepository(BonLivraison::class)->findOneBy(['commande' => $commande, 'statut' => BonLivraison::STATUT_VALIDE], ['id' => 'DESC']);
        if ($bon !== null && $bon->getLivreur() === $this->getUser()) {
            return $this->redirectToRoute('app_livraisons_remise', ['id' => $bon->getId()]);
        }
        if ($this->isGranted('ROLE_COMMANDE')) {
            return $this->redirectToRoute('app_livraisons_commande', ['id' => $commande->getId()]);
        }
        $this->addFlash('warning', \sprintf('Colis de la commande %s : il n\'est pas dans votre tournée.', $commande->getNumero()));

        return $this->redirectToRoute('app_livraisons_tournee');
    }
}
