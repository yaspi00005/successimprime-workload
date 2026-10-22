<?php

namespace App\Service\Bascule;

/**
 * Comptes d'une conversion des remises : chaque ligne recalculée avec le
 * nouveau calcul est comparée à son total enregistré. Seulement des nombres.
 */
final class BilanRemises
{
    /** @var array<string, int> */
    private array $comptes = [Remises::IDENTIQUE => 0, Remises::ARRONDI => 0, Remises::DEJA_DIFFERENTE => 0, Remises::ECART => 0];

    public function compter(string $verdict): void
    {
        ++$this->comptes[$verdict];
    }

    public function lignes(): int
    {
        return array_sum($this->comptes);
    }

    public function nombre(string $verdict): int
    {
        return $this->comptes[$verdict];
    }

    /** Lignes que la conversion ferait changer de plus d'1 F par unité : bloquant. */
    public function ecarts(): int
    {
        return $this->comptes[Remises::ECART];
    }

    /** « 140 identique(s), 12 à 1 F près par unité, 0 déjà différente(s) avant la bascule, 0 au-delà » */
    public function texte(): string
    {
        return \sprintf(
            '%d identique(s), %d à 1 F près par unité, %d déjà différente(s) avant la bascule, %d au-delà',
            $this->comptes[Remises::IDENTIQUE], $this->comptes[Remises::ARRONDI], $this->comptes[Remises::DEJA_DIFFERENTE], $this->comptes[Remises::ECART],
        );
    }
}
