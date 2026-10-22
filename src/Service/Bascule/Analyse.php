<?php

namespace App\Service\Bascule;

/**
 * Résultat de la vérification : écarts classés, lignes orphelines déjà
 * présentes (signalées, jamais modifiées), conversions de données à
 * rejouer et nombre de tables. Sur une copie de la structure sans données,
 * $pireCas liste ce qui bloquerait si les tables étaient remplies : à
 * revérifier sur la vraie base.
 */
final class Analyse
{
    /**
     * @param list<Constat>                                                                $constats
     * @param list<array{table: string, colonnes: list<string>, parente: string, lignes: int}> $orphelins
     * @param list<Constat>                                                                $pireCas
     * @param list<Conversion>                                                             $conversions à faire (les impossibles sont des constats bloquants)
     */
    public function __construct(
        public readonly array $constats,
        public readonly array $orphelins,
        public readonly int $tables,
        public readonly int $tablesAttendues,
        public readonly array $pireCas = [],
        public readonly bool $sansDonnees = false,
        public readonly array $conversions = [],
    ) {
    }

    /** @return list<Constat> */
    public function niveau(string $niveau): array
    {
        return array_values(array_filter($this->constats, static fn (Constat $c): bool => $niveau === $c->niveau));
    }

    /** @return list<Constat> */
    public function bloquants(): array
    {
        return $this->niveau(Constat::BLOQUANT);
    }

    /** Rien à changer : ni écart de schéma, ni conversion de données. */
    public function identique(): bool
    {
        return [] === $this->constats && [] === $this->conversions;
    }
}
