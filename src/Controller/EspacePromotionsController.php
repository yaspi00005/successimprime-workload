<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\Promotions\Promotions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, Mes promotions (docs/promotions.md) : les promotions en
 * cours qui concernent ce client, et elles seules (son segment, sa liste,
 * son anniversaire). Nom, articles, période et avantage ; ni code promo ni
 * note interne (ils ne descendent pas en ligne). Lecture seule : l'atelier
 * pose la remise en confirmant le prix de la commande.
 */
final class EspacePromotionsController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly Promotions $promotions,
    ) {
    }

    #[Route('/espace/promotions', name: 'app_espace_promotions', methods: ['GET'])]
    public function index(): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();

        return $this->render('espace/app/promotions.html.twig', [
            'onglet' => 'compte',
            'moi' => $client,
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'b2b' => $client->isB2B(),
            'promotions' => $this->promotions->pourLeClient($client, new \DateTimeImmutable('today')),
        ]);
    }

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
