<?php

namespace App\Controller;

use App\Entity\AssistantConversation;
use App\Entity\CompteClient;
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
 * Assistant de l'espace client (docs/assistant-client.md). Le compte vient
 * de la session, jamais de la requête : les outils ne lisent que la fiche
 * de ce compte, et les montants seulement s'il paie. Une conversation
 * n'est rouverte que par le compte qui l'a commencée.
 */
#[Route('/espace/assistant', name: 'app_espace_assistant_')]
final class EspaceAssistantController extends AbstractController
{
    public function __construct(
        private readonly Assistant $assistant,
        private readonly ReglagesAssistant $reglages,
        private readonly AssistantConversationRepository $conversations,
        #[Autowire(service: 'limiter.assistant_compte')] private readonly RateLimiterFactoryInterface $limiteCompte,
        #[Autowire(service: 'limiter.assistant_conversation')] private readonly RateLimiterFactoryInterface $limiteConversation,
    ) {
    }

    #[Route('/message', name: 'message', methods: ['POST'])]
    public function message(Request $request): JsonResponse
    {
        if (!$this->reglages->disponible()) {
            return RequeteAssistant::erreur('L\'assistant n\'est pas disponible.', 404);
        }
        [$compte, $donnees] = $this->lire($request);
        if (null === $compte) {
            return RequeteAssistant::erreur('Requête refusée.', 403);
        }
        try {
            $question = Assistant::verifierQuestion(RequeteAssistant::chaine($donnees, 'texte', 4 * Assistant::QUESTION_MAX));
        } catch (\DomainException $e) {
            return RequeteAssistant::erreur($e->getMessage(), 422);
        }
        if (!$this->limiteCompte->create('compte-'.$compte->getId())->consume()->isAccepted()) {
            return RequeteAssistant::erreur('Beaucoup de questions en une heure : réessayez plus tard, ou écrivez à votre conseiller.', 429);
        }

        $ctx = ContexteAssistant::espace($compte, new \DateTimeImmutable(), RequeteAssistant::page($donnees['page'] ?? null));
        $conversation = $this->conversations->parJeton(RequeteAssistant::chaine($donnees, 'conversation', 64), AssistantConversation::ESPACE, $compte);
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
        [$compte, $donnees] = $this->lire($request);
        $conversation = null === $compte ? null : $this->conversations->parJeton(RequeteAssistant::chaine($donnees, 'conversation', 64), AssistantConversation::ESPACE, $compte);
        if (null === $conversation || !$this->assistant->noter($conversation, RequeteAssistant::chaine($donnees, 'message', 36), RequeteAssistant::note($donnees['note'] ?? null))) {
            return RequeteAssistant::erreur('Réponse introuvable.', 404);
        }

        return RequeteAssistant::json(['ok' => true]);
    }

    #[Route('/historique', name: 'historique', methods: ['POST'])]
    public function historique(Request $request): JsonResponse
    {
        [$compte, $donnees] = $this->lire($request);
        $conversation = null === $compte ? null : $this->conversations->parJeton(RequeteAssistant::chaine($donnees, 'conversation', 64), AssistantConversation::ESPACE, $compte);
        if (null === $conversation) {
            return RequeteAssistant::erreur('Conversation introuvable.', 404);
        }

        return RequeteAssistant::json(['messages' => $this->assistant->historique($conversation)]);
    }

    /**
     * Le compte connecté et le corps, si le jeton CSRF de l'espace est bon.
     *
     * @return array{0: ?CompteClient, 1: array<string, mixed>}
     */
    private function lire(Request $request): array
    {
        $compte = $this->getUser();
        $donnees = RequeteAssistant::lire($request);
        if (!$compte instanceof CompteClient || null === $compte->getClient() || null === $donnees
            || !$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return [null, []];
        }

        return [$compte, $donnees];
    }
}
