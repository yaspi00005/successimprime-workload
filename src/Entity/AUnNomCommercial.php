<?php

namespace App\Entity;

/**
 * Élément du catalogue qui peut porter un nom commercial (docs/noms-commerciaux.md) :
 * à côté de sa désignation technique (« Vinyle adhésif »), le nom que tout
 * le monde connaît (« Autocollant »). Produits, supports, types
 * d'impression et articles de stock.
 *
 * L'affichage et la recherche passent par App\Service\Catalogue\NomCommercial.
 */
interface AUnNomCommercial
{
    /** Nom commercial, null quand il n'y en a pas. */
    public function getNomCommercial(): ?string;

    public function setNomCommercial(?string $nomCommercial): static;

    /** Désignation technique : nom du produit, du support, du type ou désignation de l'article. */
    public function getNomTechnique(): string;
}
