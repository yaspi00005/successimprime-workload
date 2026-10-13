<?php

namespace App\Controller;

use App\Entity\ReclamationClient;
use App\Entity\ReclamationClientPhoto;
use App\Entity\User;
use App\Service\EspaceClient\ReclamationsClients;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Réclamations : ce que les clients ont signalé dans leur espace
 * (maquette 18), avec leurs photos ; l'atelier répond et la clôt.
 */
#[Route('/clients/reclamations', name: 'app_reclamations_clients_')]
#[IsGranted('ROLE_CLIENT')]
final class ReclamationsClientsController extends AbstractController
{
    private const CSRF = 'reclamations-clients';

    public function __construct(private readonly ReclamationsClients $reclamations)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $closes = $request->query->getBoolean('closes');

        return $this->render('reclamations_clients/index.html.twig', [
            'liste' => $this->reclamations->liste($closes),
            'closes' => $closes,
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(ReclamationClient $reclamation): Response
    {
        return $this->render('reclamations_clients/voir.html.twig', [
            'r' => $reclamation,
            'statuts' => array_diff_key(ReclamationClient::STATUTS, [ReclamationClient::OUVERTE => true]),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/repondre', name: 'repondre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function repondre(ReclamationClient $reclamation, Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $moi = $this->getUser();
        $par = $moi instanceof User ? ($moi->getEmploye()?->getPrenom() ?? $moi->getUsername()) : 'Atelier';
        try {
            $this->reclamations->repondre($reclamation, $request->request->getString('reponse'), $request->request->getString('statut'), (string) $par, new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Réponse envoyée : %s la lit dans son espace client.', $reclamation->getClient()?->getNomComplet() ?? 'le client'));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_reclamations_clients_voir', ['id' => $reclamation->getId()]);
    }

    #[Route('/photo/{id}', name: 'photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function photo(ReclamationClientPhoto $photo): Response
    {
        return new Response($photo->getOctets(), 200, [
            'Content-Type' => $photo->getType(),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
