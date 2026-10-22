<?php

namespace App\Service\Statistiques\Export;

/**
 * Ce qu'un écran de statistiques exporte : un titre, la période, et les
 * tableaux que la personne voit à l'écran (mêmes requêtes, mêmes droits).
 */
final class Export
{
    /**
     * @param list<Tableau> $tableaux
     */
    public function __construct(
        public readonly string $titre,
        public readonly string $periode,
        public readonly array $tableaux,
        public readonly string $fichier,
        public readonly ?string $note = null,
    ) {
    }

    /** Nom de fichier sans accents ni espaces, avec l'extension. */
    public function nomFichier(string $extension): string
    {
        $base = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $this->fichier)));

        return trim($base, '-').'.'.$extension;
    }
}
