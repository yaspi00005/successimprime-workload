<?php

namespace App\Twig;

use App\Entity\AssistantConversation;
use App\Service\Assistant\ReglagesAssistant;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Bouton de l'assistant (templates/assistant/_widget.html.twig) : rien tant
 * que la clé de l'API n'est pas posée ou que la tuile est éteinte.
 */
final class AssistantExtension extends AbstractExtension
{
    /** Questions proposées à l'ouverture d'une conversation. */
    public const SUGGESTIONS = [
        AssistantConversation::SITE => ['Combien coûte une bâche ?', 'Quels sont vos horaires ?', 'Quels fichiers acceptez-vous ?'],
        AssistantConversation::ESPACE => ['Où en est ma commande ?', 'Que me reste-t-il à payer ?', 'Parler à un conseiller'],
    ];

    public function __construct(
        private readonly ReglagesAssistant $reglages,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('assistant_widget', $this->widget(...))];
    }

    /** @return array{contexte: string, accueil: string, suggestions: list<string>, urls: array{message: string, note: string, historique: string}}|null */
    public function widget(string $contexte): ?array
    {
        if (!isset(AssistantConversation::CONTEXTES[$contexte]) || !$this->reglages->disponible()) {
            return null;
        }
        $prefixe = AssistantConversation::ESPACE === $contexte ? 'app_espace_assistant_' : 'app_assistant_public_';

        return [
            'contexte' => $contexte,
            'accueil' => $this->reglages->messageAccueil(),
            'suggestions' => self::SUGGESTIONS[$contexte],
            'urls' => [
                'message' => $this->urls->generate($prefixe.'message'),
                'note' => $this->urls->generate($prefixe.'note'),
                'historique' => $this->urls->generate($prefixe.'historique'),
            ],
        ];
    }
}
