<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Repository\UserRepository;
use App\Service\ChatService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Asset\Packages;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/chat', name: 'app_chat_')]
final class ChatController extends AbstractController
{
    private const REGEX_JETON = '[0-9a-f]{32}';

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ConversationRepository $conversationRepository): Response
    {
        $utilisateur = $this->utilisateurConnecte();

        return $this->render('chat/chat.html.twig', [
            'conversations' => $conversationRepository->findPourUtilisateur($utilisateur),
            'conversationActive' => null,
            'utilisateur' => $utilisateur,
        ]);
    }

    #[Route('/nouvelle', name: 'nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(
        Request $request,
        UserRepository $userRepository,
        ChatService $chatService
    ): Response {
        $utilisateur = $this->utilisateurConnecte();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('chat_nouvelle_conversation', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');

                return $this->redirectToRoute('app_chat_nouvelle');
            }

            $destinataireId = $request->request->getInt('destinataire');
            $destinataire = $userRepository->find($destinataireId);

            if (!$destinataire instanceof User || !$destinataire->isActif() || $destinataire === $utilisateur) {
                $this->addFlash('error', 'Destinataire invalide.');

                return $this->redirectToRoute('app_chat_nouvelle');
            }

            $conversation = $chatService->demarrerConversationDirecte($utilisateur, $destinataire);

            return $this->redirectToRoute('app_chat_show', ['jeton' => $conversation->getJeton()]);
        }

        $utilisateurs = array_values(array_filter(
            $userRepository->findBy(['actif' => true], ['username' => 'ASC']),
            static fn (User $candidat): bool => $candidat !== $utilisateur
        ));

        $rolesDisponibles = [];

        foreach ($utilisateurs as $candidat) {
            $rolesDisponibles = array_merge($rolesDisponibles, $candidat->getRoles());
        }

        $rolesDisponibles = array_values(array_unique($rolesDisponibles));
        sort($rolesDisponibles);

        return $this->render('chat/nouvelle.html.twig', [
            'utilisateurs' => $utilisateurs,
            'rolesDisponibles' => $rolesDisponibles,
        ]);
    }

    #[Route('/{jeton}', name: 'show', requirements: ['jeton' => self::REGEX_JETON], methods: ['GET'])]
    public function show(
        #[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $conversation,
        ChatService $chatService,
        ConversationRepository $conversationRepository
    ): Response {
        $utilisateur = $this->utilisateurConnecte();
        $this->verifierAcces($conversation, $utilisateur);

        $chatService->marquerConversationLue($conversation, $utilisateur);

        return $this->render('chat/chat.html.twig', [
            'conversations' => $conversationRepository->findPourUtilisateur($utilisateur),
            'conversationActive' => $conversation,
            'utilisateur' => $utilisateur,
        ]);
    }

    /**
     * Nouveaux messages depuis le dernier vu (utilisé pour le
     * rafraîchissement automatique en AJAX, sans recharger la page).
     */
    #[Route('/{jeton}/messages', name: 'messages', requirements: ['jeton' => self::REGEX_JETON], methods: ['GET'])]
    public function messages(
        #[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $conversation,
        Request $request,
        ChatService $chatService,
        Packages $assets
    ): JsonResponse {
        $utilisateur = $this->utilisateurConnecte();
        $this->verifierAcces($conversation, $utilisateur);

        $depuisId = $request->query->getInt('depuis');

        $nouveaux = array_values(array_filter(
            $conversation->getMessages()->toArray(),
            static fn (Message $message): bool => $message->getId() > $depuisId
        ));

        if ($nouveaux !== []) {
            $chatService->marquerConversationLue($conversation, $utilisateur);
        }

        return $this->json([
            'messages' => array_map(
                fn (Message $message): array => $this->serialiserMessage($message, $utilisateur, $assets),
                $nouveaux
            ),
        ]);
    }

    #[Route('/{jeton}/envoyer', name: 'envoyer', requirements: ['jeton' => self::REGEX_JETON], methods: ['POST'])]
    public function envoyer(
        #[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $conversation,
        Request $request,
        ChatService $chatService,
        Packages $assets
    ): Response {
        $utilisateur = $this->utilisateurConnecte();
        $this->verifierAcces($conversation, $utilisateur);

        $estAjax = $request->isXmlHttpRequest();

        if (!$this->isCsrfTokenValid('chat_envoyer_' . $conversation->getJeton(), (string) $request->request->get('_token'))) {
            if ($estAjax) {
                return $this->json(['erreur' => 'Jeton de sécurité invalide.'], Response::HTTP_FORBIDDEN);
            }

            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_chat_show', ['jeton' => $conversation->getJeton()]);
        }

        $contenu = trim((string) $request->request->get('contenu'));

        /** @var UploadedFile|null $fichier */
        $fichier = $request->files->get('fichier');

        $pieceJointeFichier = null;
        $pieceJointeNomOriginal = null;

        if ($fichier instanceof UploadedFile) {
            $dossier = $this->getParameter('chat_directory');
            $nomOriginal = $fichier->getClientOriginalName();
            $nomStocke = uniqid('chat_') . '.' . $fichier->guessExtension();

            try {
                if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
                    throw new FileException(sprintf('Impossible de créer le dossier "%s".', $dossier));
                }

                $fichier->move($dossier, $nomStocke);

                $pieceJointeFichier = $nomStocke;
                $pieceJointeNomOriginal = $nomOriginal;
            } catch (FileException $exception) {
                if ($estAjax) {
                    return $this->json(['erreur' => 'Le fichier n’a pas pu être envoyé : ' . $exception->getMessage()], Response::HTTP_BAD_REQUEST);
                }

                $this->addFlash('error', 'Le fichier n’a pas pu être envoyé : ' . $exception->getMessage());

                return $this->redirectToRoute('app_chat_show', ['jeton' => $conversation->getJeton()]);
            }
        }

        $message = null;

        if ($contenu !== '' || $pieceJointeFichier !== null) {
            $message = $chatService->envoyerMessage(
                $conversation,
                $utilisateur,
                $contenu,
                $pieceJointeFichier,
                $pieceJointeNomOriginal
            );
        }

        if ($estAjax) {
            return $this->json([
                'message' => $message !== null ? $this->serialiserMessage($message, $utilisateur, $assets) : null,
            ]);
        }

        return $this->redirectToRoute('app_chat_show', ['jeton' => $conversation->getJeton()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialiserMessage(Message $message, User $utilisateur, Packages $assets): array
    {
        $auteur = $message->getAuteur();

        $pieceJointe = null;

        if ($message->aUnePieceJointe()) {
            $pieceJointe = [
                'url' => $assets->getUrl('uploads/chat/' . $message->getPieceJointeFichier()),
                'nomOriginal' => $message->getPieceJointeNomOriginal(),
                'estImage' => $message->pieceJointeEstUneImage(),
            ];
        }

        return [
            'id' => $message->getId(),
            'contenu' => $message->getContenu(),
            'estMoi' => $auteur === $utilisateur,
            'auteurUsername' => $auteur?->getUsername(),
            'dateEnvoi' => $message->getDateEnvoi()?->format('d/m/Y H:i'),
            'pieceJointe' => $pieceJointe,
        ];
    }

    private function utilisateurConnecte(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Utilisateur non authentifié.');
        }

        return $user;
    }

    private function verifierAcces(Conversation $conversation, User $utilisateur): void
    {
        if (!$conversation->aPourParticipant($utilisateur)) {
            throw $this->createAccessDeniedException('Vous ne participez pas à cette conversation.');
        }
    }
}
