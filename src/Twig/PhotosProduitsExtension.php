<?php

namespace App\Twig;

use App\Service\Site\PhotosProduits;
use Twig\Attribute\AsTwigFunction;

/**
 * Photo d'un produit d'après son nom (PhotosProduits) : cartes de « Nos
 * produits » et « Commander » de l'espace client.
 */
final class PhotosProduitsExtension
{
    public function __construct(private readonly PhotosProduits $photos)
    {
    }

    /**
     * @return array{nom: string, src: string, fichiers: list<array{chemin: string, largeur: int}>, largeur: int, hauteur: int, alt: string, cadrage: null}|null
     */
    #[AsTwigFunction('site_photo_produit')]
    public function photo(?string $produit): ?array
    {
        return $this->photos->photo($produit);
    }
}
