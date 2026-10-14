<?php

namespace App\Service\Statistiques\Export;

/**
 * Un tableau d'un export : une feuille dans le classeur Excel, un bloc
 * dans le PDF. Chaque colonne a un format : texte, entier, fcfa, pourcent
 * (en %, 47 pour 47 %), m2, date (\DateTimeInterface).
 */
final class Tableau
{
    public const FORMATS = ['texte', 'entier', 'fcfa', 'pourcent', 'm2', 'date'];

    /** @var list<array{libelle: string, format: string}> */
    public readonly array $colonnes;

    /**
     * @param array<string, string>   $colonnes libellé => format
     * @param list<list<mixed>>       $lignes
     * @param list<mixed>|null        $total    dernière ligne en gras
     */
    public function __construct(
        public readonly string $titre,
        array $colonnes,
        public readonly array $lignes,
        public readonly ?array $total = null,
        public readonly ?string $note = null,
    ) {
        $liste = [];
        foreach ($colonnes as $libelle => $format) {
            if (!\in_array($format, self::FORMATS, true)) {
                throw new \InvalidArgumentException(\sprintf('Format de colonne inconnu : %s', $format));
            }
            $liste[] = ['libelle' => $libelle, 'format' => $format];
        }
        $this->colonnes = $liste;

        foreach ($lignes as $i => $ligne) {
            if (\count($ligne) !== \count($liste)) {
                throw new \InvalidArgumentException(\sprintf('Ligne %d de « %s » : %d valeurs pour %d colonnes.', $i + 1, $titre, \count($ligne), \count($liste)));
            }
        }
    }

    /**
     * Valeur lisible d'une cellule (PDF, aperçu), « — » si vide.
     */
    public static function afficher(mixed $valeur, string $format): string
    {
        if (null === $valeur || '' === $valeur) {
            return '—';
        }

        return match ($format) {
            'entier' => number_format((float) $valeur, 0, ',', ' '),
            'fcfa' => number_format((float) $valeur, 0, ',', ' ').' F',
            'pourcent' => number_format((float) $valeur, floor((float) $valeur) == (float) $valeur ? 0 : 1, ',', ' ').' %',
            'm2' => number_format((float) $valeur, 1, ',', ' ').' m²',
            'date' => $valeur instanceof \DateTimeInterface ? $valeur->format('d/m/Y') : (string) $valeur,
            default => (string) $valeur,
        };
    }

    public function estVide(): bool
    {
        return [] === $this->lignes && null === $this->total;
    }
}
