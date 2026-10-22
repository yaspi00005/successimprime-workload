<?php

namespace App\Service\Assistant;

/**
 * Ce qu'un outil rend : les données lues par le modèle (seulement ce que
 * la personne a le droit de voir) et les boutons montrés sous la réponse,
 * construits ici et non par le modèle.
 */
final class ResultatOutil
{
    /**
     * @param array<string, mixed>                                      $donnees
     * @param list<array{libelle: string, url: string, type: string}> $actions
     */
    public function __construct(
        public readonly array $donnees,
        public readonly array $actions = [],
        public readonly bool $erreur = false,
        public readonly bool $passageAgent = false,
    ) {
    }

    public static function erreur(string $message): self
    {
        return new self(['erreur' => $message], [], true);
    }

    /** Texte du tool_result : du JSON, lu comme une donnée. */
    public function texte(): string
    {
        return (string) json_encode($this->donnees, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @return array{libelle: string, url: string, type: string} */
    public static function lien(string $libelle, string $url, string $type = 'lien'): array
    {
        return ['libelle' => $libelle, 'url' => $url, 'type' => $type];
    }
}
