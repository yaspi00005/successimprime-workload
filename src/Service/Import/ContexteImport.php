<?php

namespace App\Service\Import;

use App\Entity\User;

/**
 * Qui importe, quand, avec quels droits et quels choix (date d'ouverture
 * des soldes, compléter les fiches existantes).
 */
final class ContexteImport
{
    /** @var \Closure(string): bool */
    private readonly \Closure $droit;

    /**
     * @param callable(string): bool $droit
     */
    public function __construct(
        public readonly ?User $par,
        public readonly string $parNom,
        public readonly \DateTimeImmutable $maintenant,
        callable $droit,
        public readonly ?\DateTimeImmutable $dateOuverture = null,
        public readonly bool $completer = false,
    ) {
        $this->droit = \Closure::fromCallable($droit);
    }

    public function peut(string $role): bool
    {
        return ($this->droit)($role);
    }

    /** Jour des soldes d'ouverture : celui choisi à l'envoi, sinon aujourd'hui. */
    public function jourOuverture(): \DateTimeImmutable
    {
        return ($this->dateOuverture ?? $this->maintenant)->setTime(0, 0);
    }
}
