<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\EspaceClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client (maquette EC01) : recherche de la barre du haut et
 * Livraisons (Messages : EspaceMessagesController). Toujours limité à la fiche de la personne
 * connectée et à ses droits.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceSuiviController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EspaceClient $espace,
    ) {
    }

    /** Recherche dans ses commandes, devis, fichiers et (payeur) factures. */
    #[Route('/recherche', name: 'recherche', methods: ['GET'])]
    public function recherche(Request $request): Response
    {
        $compte = $this->compte();
        $texte = trim(mb_substr($request->query->getString('q'), 0, 60));
        $resultats = $this->espace->rechercher($compte, $texte);

        return $this->render('espace/app/recherche.html.twig', [
            'texte' => $texte,
            'resultats' => $resultats,
            'total' => array_sum(array_map('count', $resultats)),
        ]);
    }

    /**
     * Livraisons : en route, à venir, livrées, d'après les bons de
     * livraison synchronisés (jour, créneau, heures ; ni livreur, ni
     * réceptionnaire, ni photos, ni signature).
     */
    #[Route('/livraisons', name: 'livraisons', methods: ['GET'])]
    public function livraisons(): Response
    {
        return $this->render('espace/app/livraisons.html.twig', [
            'livraisons' => $this->espace->livraisons($this->compte()->getClient()),
        ]);
    }

    /** Le compte frais de la base (celui de la session ne garde pas la fiche client). */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }
}
