<?php

namespace App\Service\Import;

/**
 * Une ligne du fichier, telle que l'aperçu la montre : ce qui va se passer
 * (créer, mettre à jour, écartée comme doublon, erreur) et pourquoi.
 *
 * « donnees » garde ce que l'analyse a préparé pour l'enregistrement
 * (valeurs lues, fiche trouvée) : l'enregistrement refait l'analyse, il
 * ne fait jamais confiance à ce que le navigateur renvoie.
 */
final class LigneImport
{
    public const CREER = 'creer';
    public const MAJ = 'maj';
    public const IGNORE = 'ignore';
    public const ERREUR = 'erreur';

    /** Statut => [libellé, couleur de pastille]. */
    public const STATUTS = [
        self::CREER => ['Sera créé', 'vert'],
        self::MAJ => ['Sera mis à jour', 'bleu'],
        self::IGNORE => ['Écarté', 'gris'],
        self::ERREUR => ['Erreur', 'rouge'],
    ];

    public string $statut = self::CREER;
    public string $libelle = '';
    public string $detail = '';
    public ?string $raison = null;

    /** @var list<string> */
    public array $avertissements = [];

    /** @var array<string, mixed> */
    public array $donnees = [];

    /**
     * @param array<string, string> $valeurs valeurs brutes par clé de colonne
     */
    public function __construct(public readonly int $numero, public readonly array $valeurs)
    {
    }

    public function valeur(string $cle): string
    {
        return trim($this->valeurs[$cle] ?? '');
    }

    public function erreur(string $raison): static
    {
        $this->statut = self::ERREUR;
        $this->raison = $raison;

        return $this;
    }

    public function ignorer(string $raison): static
    {
        $this->statut = self::IGNORE;
        $this->raison = $raison;

        return $this;
    }

    public function avertir(string $texte): static
    {
        $this->avertissements[] = $texte;

        return $this;
    }

    public function estErreur(): bool
    {
        return self::ERREUR === $this->statut;
    }

    public function aEcrire(): bool
    {
        return self::CREER === $this->statut || self::MAJ === $this->statut;
    }

    public function statutLibelle(): string
    {
        return self::STATUTS[$this->statut][0];
    }

    public function statutCouleur(): string
    {
        return self::STATUTS[$this->statut][1];
    }

    /**
     * Nombre de lignes par statut.
     *
     * @param list<self> $lignes
     *
     * @return array{lignes: int, crees: int, modifies: int, ignores: int, erreurs: int}
     */
    public static function compter(array $lignes): array
    {
        $c = ['lignes' => \count($lignes), 'crees' => 0, 'modifies' => 0, 'ignores' => 0, 'erreurs' => 0];
        foreach ($lignes as $l) {
            match ($l->statut) {
                self::CREER => ++$c['crees'],
                self::MAJ => ++$c['modifies'],
                self::IGNORE => ++$c['ignores'],
                default => ++$c['erreurs'],
            };
        }

        return $c;
    }
}
