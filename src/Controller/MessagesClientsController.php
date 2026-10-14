<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\MessageClient;
use App\Entity\PropositionClient;
use App\Entity\User;
use App\Service\EspaceClient\MessagesClient;
use App\Service\EspaceClient\PropositionsClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Messages : ce que les clients écrivent à l'atelier depuis leur
 * espace (Messages, maquette EC01). L'équipe répond (le client est prévenu,
 * sans montant) ou classe. Les réponses partent de l'ERP de la boutique :
 * sur le site en ligne, la page se lit seulement.
 *
 * Aussi, depuis la fiche client : appliquer ou refuser un changement de
 * raison sociale ou de NIF demandé en ligne (Mon compte, maquette EC04).
 */
#[Route('/clients', name: 'app_messages_clients_')]
#[IsGranted('ROLE_CLIENT')]
final class MessagesClientsController extends AbstractController
{
    private const CSRF = 'messages-clients';

    public function __construct(
        private readonly MessagesClient $messages,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    #[Route('/messages', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('messages_clients/index.html.twig', [
            'conversations' => $this->messages->conversations(),
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/messages/{publicId}', name: 'voir', methods: ['GET'])]
    public function voir(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, EntityManagerInterface $em): Response
    {
        return $this->render('messages_clients/voir.html.twig', [
            'client' => $client,
            'fil' => $this->messages->fil($client),
            'commandes' => $em->getRepository(Commandes::class)->findBy(['clients' => $client, 'deleted' => false], ['id' => 'DESC'], 15),
            'jeton' => self::CSRF,
            'enLigne' => $this->enLigne,
            'max' => MessageClient::TEXTE_MAX,
        ]);
    }

    #[Route('/messages/{publicId}/repondre', name: 'repondre', methods: ['POST'])]
    public function repondre(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request);
        $moi = $this->getUser();
        if ($this->enLigne || !$moi instanceof User) {
            throw $this->createAccessDeniedException('Les réponses partent de l\'ERP de la boutique.');
        }
        $commande = null;
        if ('' !== $request->request->getString('commande')) {
            $commande = $em->getRepository(Commandes::class)->find($request->request->getInt('commande'));
            if (null === $commande || $commande->getClients() !== $client) {
                $commande = null;
            }
        }
        try {
            $this->messages->repondre($client, $request->request->getString('texte'), $moi, $commande, new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Réponse envoyée : %s la lit dans son espace et en est prévenu.', $client->getNomComplet()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_messages_clients_voir', ['publicId' => $client->getPublicId()]);
    }

    /** Classer sans répondre : les messages ne comptent plus dans la pastille. */
    #[Route('/messages/{publicId}/classer', name: 'classer', methods: ['POST'])]
    public function classer(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request): Response
    {
        $this->jeton($request);
        $this->messages->marquerTraites($client, new \DateTimeImmutable());
        $this->addFlash('success', 'Conversation classée.');

        return $this->redirectToRoute('app_messages_clients_index');
    }

    #[Route('/propositions/{id}/{decision}', name: 'proposition', requirements: ['id' => '\d+', 'decision' => 'appliquer|refuser'], methods: ['POST'])]
    public function proposition(PropositionClient $proposition, string $decision, Request $request, PropositionsClient $propositions): Response
    {
        $this->jeton($request);
        $moi = $this->getUser();
        if (!$moi instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            if ('appliquer' === $decision) {
                $propositions->appliquer($proposition, $moi, new \DateTimeImmutable());
                $this->addFlash('success', 'Changement appliqué à la fiche : le client le voit dans son espace.');
            } else {
                $propositions->refuser($proposition, $moi, $request->request->getString('motif'), new \DateTimeImmutable());
                $this->addFlash('success', 'Demande refusée : le client voit le motif dans son espace.');
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_clients_show', ['publicId' => $proposition->getClient()?->getPublicId()]);
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
