<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Repository\AssistantConversationRepository;
use App\Service\Assistant\Assistant;
use App\Service\Assistant\ContexteAssistant;
use App\Service\Assistant\ReglagesAssistant;
use App\Service\Assistant\RequeteAssistant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Assistant du site public (docs/assistant-client.md) : questions des
 * visiteurs sans compte. Pas de session : la conversation se retrouve par
 * le jeton gardé dans le navigateur. Rien sur un client ici : les outils
 * du site ne lisent que le catalogue et les informations publiques.
 */
#[Route('/assistant', name: 'app_assistant_public_')]
final class AssistantController extends AbstractController
{
    public function __construct(
        private readonly Assistant $assistant,
        private readonly ReglagesAssistant $reglages,
        private readonly AssistantConversationRepository $conversations,
        #[Autowire(service: 'limiter.assistant_ip')] private readonly RateLimiterFactoryInterface $limiteIp,
        #[Autowire(service: 'limiter.assistant_conversation')] private readonly RateLimiterFactoryInterface $limiteConversation,
    ) {
    }

    #[Route('/message', name: 'message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        if (!$this->reglages->disponible()) {
            return RequeteAssistant::erreur('L\'assistant n\'est pas disponible.', 404);
        }
        $donnees = RequeteAssistant::lire($request) ?? [];
        if ([] === $donnees) {
            return RequeteAssistant::erreur('Requête refusée.', 400);
        }
        try {
            $question = Assistant::verifierQuestion(RequeteAssistant::chaine($donnees, 'texte', 4 * Assistant::QUESTION_MAX));
        } catch (\DomainException $e) {
            return RequeteAssistant::erreur($e->getMessage(), 422);
        }
        if (!$this->limiteIp->create($request->getClientIp() ?? 'inconnue')->consume()->isAccepted()) {
            return RequeteAssistant::erreur('Beaucoup de questions depuis cette connexion : réessayez plus tard, ou écrivez-nous sur WhatsApp.', 429);
        }

        $ctx = ContexteAssistant::site(new \DateTimeImmutable(), RequeteAssistant::page($donnees['page'] ?? null));
        $conversation = $this->conversations->parJeton(RequeteAssistant::chaine($donnees, 'conversation', 64), AssistantConversation::SITE, null);
        $jeton = null;
        if (null === $conversation) {
            [$conversation, $jeton] = $this->assistant->commencer($ctx);
        }
        if (!$this->limiteConversation->create((string) $conversation->getPublicId())->consume()->isAccepted()) {
            return RequeteAssistant::erreur('Beaucoup de questions en peu de temps : réessayez dans un moment.', 429);
        }

        return RequeteAssistant::json(['conversation' => $jeton, 'reponse' => $this->assistant->repondre($conversation, $ctx, $question)]);
    }

    #[Route('/note', name: 'note', methods: ['POST'])]
    public function note(Request $request): JsonResponse
    {
        $donnees = RequeteAssistant::lire($request) ?? [];
        $conversation = $this->conversations->parJeton(RequeteAssistant::chaine($donnees, 'conversation', 64), AssistantConversation::SITE, null);
        if (null === $conversation || !$this->assistant->noter($conversation, RequeteAssistant::chaine($donnees, 'message', 36), RequeteAssistant::note($donnees['note'] ?? null))) {
            return RequeteAssistant::erreur('Réponse introuvable.', 404);
        }

        return RequeteAssistant::json(['ok' => true]);
    }

    #[Route('/historique', name: 'historique', methods: ['POST'])]
    public function historique(Request $request): JsonResponse
    {
        $donnees = RequeteAssistant::lire($request) ?? [];
        $conversation = $this->conversations->parJeton(RequeteAssistant::chaine($donnees, 'conversation', 64), AssistantConversation::SITE, null);
        if (null === $conversation) {
            return RequeteAssistant::erreur('Conversation introuvable.', 404);
        }

        return RequeteAssistant::json(['messages' => $this->assistant->historique($conversation)]);
    }
}
