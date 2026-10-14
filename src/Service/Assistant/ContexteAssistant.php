<?php

namespace App\Service\Assistant;

use App\Entity\AssistantConversation;
use App\Entity\Clients;
use App\Entity\CompteClient;

/**
 * Qui pose la question, et où. Le compte vient toujours de la session de
 * l'espace client, jamais de ce que le modèle écrit : c'est lui qui borne
 * ce que les outils renvoient.
 */
final class ContexteAssistant
{
    private function __construct(
        public readonly string $contexte,
        public readonly ?CompteClient $compte,
        public readonly \DateTimeImmutable $maintenant,
        public readonly ?string $page,
    ) {
    }

    /** Visiteur du site, sans compte. */
    public static function site(\DateTimeImmutable $maintenant, ?string $page = null): self
    {
        return new self(AssistantConversation::SITE, null, $maintenant, $page);
    }

    /** Personne connectée à son espace client. */
    public static function espace(CompteClient $compte, \DateTimeImmutable $maintenant, ?string $page = null): self
    {
        if (null === $compte->getClient()) {
            throw new \LogicException('Compte sans fiche client.');
        }

        return new self(AssistantConversation::ESPACE, $compte, $maintenant, $page);
    }

    public function estEspace(): bool
    {
        return AssistantConversation::ESPACE === $this->contexte && null !== $this->compte;
    }

    public function client(): ?Clients
    {
        return $this->compte?->getClient();
    }

    /** Montants, factures et solde : seulement pour qui paie (un particulier fait tout). */
    public function voitLesMontants(): bool
    {
        return null !== $this->compte && $this->compte->peut(CompteClient::DROIT_PAYEUR);
    }

    /** Commander, demander un devis : tout le monde sauf « Lecture · suivi ». */
    public function peutEcrire(): bool
    {
        return null !== $this->compte && $this->compte->peutEcrire();
    }
}
