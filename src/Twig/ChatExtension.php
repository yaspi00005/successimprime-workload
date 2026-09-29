<?php

namespace App\Twig;

use App\Entity\Conversation;
use App\Entity\User;
use App\Service\ChatService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Alimente l'icône de messagerie interne du gabarit de base avec le
 * nombre de conversations contenant un message non lu.
 */
class ChatExtension extends AbstractExtension
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mes_conversations_non_lues', [$this, 'mesConversationsNonLues']),
            new TwigFunction('mes_conversations_non_lues_apercu', [$this, 'mesConversationsNonLuesApercu']),
        ];
    }

    public function mesConversationsNonLues(): int
    {
        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof User) {
            return 0;
        }

        return $this->chatService->compterConversationsNonLues($utilisateur);
    }

    /**
     * @return Conversation[]
     */
    public function mesConversationsNonLuesApercu(): array
    {
        $utilisateur = $this->security->getUser();

        if (!$utilisateur instanceof User) {
            return [];
        }

        return $this->chatService->conversationsNonLuesApercu($utilisateur);
    }
}
