<?php

namespace App\Service\Bascule;

/**
 * Une ligne de commande ou de devis telle qu'elle est enregistrée dans la
 * base de la boutique : de quoi recalculer son total avec l'ancien calcul
 * (remise en pourcentage) et le nouveau (remise en F par unité). Reste en
 * mémoire pendant la bascule ; rien n'en sort dans un rapport.
 */
final class LigneRemise
{
    public function __construct(
        public readonly int $id,
        /** Valeur brute de la colonne remise (« 12.50 », « 10 »…). */
        public readonly string $remise,
        public readonly int $totalHt,
        public readonly string $prixUnitaire = '0',
        public readonly int $quantite = 1,
        public readonly ?string $surface = null,
        public readonly ?string $longueur = null,
        public readonly int $nombreFaces = 1,
        public readonly string $modeCalcul = 'unite',
        /** Somme des montants des finitions de la ligne (chacun au moins 0). */
        public readonly int $finitions = 0,
    ) {
    }

    /** @param array<string, mixed> $ligne une ligne lue en base (colonnes de ConversionsDonnees::lignesRemise) */
    public static function depuis(array $ligne): self
    {
        return new self(
            (int) $ligne['id'],
            (string) $ligne['remise'],
            (int) $ligne['total_ht'],
            (string) ($ligne['prix_unitaire'] ?? '0'),
            (int) ($ligne['quantite'] ?? 1),
            null === ($ligne['surface'] ?? null) ? null : (string) $ligne['surface'],
            null === ($ligne['longueur'] ?? null) ? null : (string) $ligne['longueur'],
            (int) ($ligne['nombre_faces'] ?? 1),
            (string) ($ligne['mode_calcul'] ?? 'unite'),
            (int) ($ligne['finitions'] ?? 0),
        );
    }

    public function facteur(): float
    {
        return Remises::facteur($this->modeCalcul, $this->quantite, $this->surface, $this->longueur, $this->nombreFaces);
    }

    public function brut(): int
    {
        return Remises::brut($this->prixUnitaire, $this->facteur(), $this->finitions);
    }

    public function pourcentage(): float
    {
        return (float) $this->remise;
    }

    /** La valeur peut-elle être un pourcentage de l'ancienne version (de 0 à 100) ? */
    public function dansLesBornes(): bool
    {
        return $this->pourcentage() >= 0 && $this->pourcentage() <= 100;
    }

    /** L'ancien calcul (remise en pourcentage) redonne-t-il le total enregistré ? */
    public function suitLePourcentage(): bool
    {
        return $this->dansLesBornes() && Remises::totalAncien($this->brut(), $this->pourcentage()) === $this->totalHt;
    }

    /** Le nouveau calcul (remise entière en F par unité) redonne-t-il le total enregistré ? */
    public function suitLaRemiseParUnite(): bool
    {
        $valeur = $this->pourcentage();

        return $valeur >= 0 && floor($valeur) === $valeur
            && Remises::totalNouveau($this->brut(), (int) $valeur, $this->facteur()) === $this->totalHt;
    }

    /** Remise en F par unité qui remplace le pourcentage. */
    public function remiseParUnite(): int
    {
        $brut = $this->brut();

        return Remises::parUnite(Remises::montant($this->pourcentage(), $this->totalHt, $brut), $brut, $this->facteur());
    }

    /** Verdict après conversion, pour une remise par unité donnée (celle relue en base). */
    public function verdict(int $remiseParUnite, bool $coherenteAvant): string
    {
        return Remises::verdict($this->totalHt, Remises::totalNouveau($this->brut(), $remiseParUnite, $this->facteur()), $this->facteur(), $coherenteAvant);
    }
}
