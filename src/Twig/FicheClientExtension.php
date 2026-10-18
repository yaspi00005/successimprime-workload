<?php

namespace App\Twig;

use App\Entity\ProduitClient;
use App\Service\Clients\CalculsFicheClient;
use App\Service\EspaceClient\MesProduits;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Petits outils de la fiche client (templates/clients/fiche.html.twig,
 * docs/clients.md) : caractéristiques et état « commandable » d'un produit
 * du client, intensité d'une case de l'activité, texte raccourci.
 */
final class FicheClientExtension
{
    /** « 85 × 55 cm · Couché 350 g » */
    #[AsTwigFunction('produit_client_format')]
    public function format(ProduitClient $p): string
    {
        return MesProduits::format($p);
    }

    /** Le produit peut-il être commandé tout de suite (prix, fichier prêt ou produit de l'atelier) ? */
    #[AsTwigFunction('produit_client_commandable')]
    public function commandable(ProduitClient $p): bool
    {
        return MesProduits::estCommandable($p);
    }

    /** 0 à 4, pour la couleur d'une case de « Activité sur 12 mois ». */
    #[AsTwigFunction('calcul_intensite')]
    public function intensite(int $nombre, int $maximum): int
    {
        return CalculsFicheClient::intensite($nombre, $maximum);
    }

    /** Coupe un texte à $longueur caractères, avec « … ». */
    #[AsTwigFilter('raccourcir')]
    public function raccourcir(?string $texte, int $longueur = 120): string
    {
        $texte = trim((string) $texte);

        return mb_strlen($texte) > $longueur ? rtrim(mb_substr($texte, 0, $longueur - 1)).'…' : $texte;
    }
}
