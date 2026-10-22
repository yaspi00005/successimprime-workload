<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Frais de livraison et objet d'un devis, d'une commande ou d'une facture
 * (docs/documents-livraisons.md, « Frais de livraison et objet »).
 *
 * - fraisLivraison : montant en F, sans TVA, choisi parmi les paliers des
 *   Réglages (Ventes › Livraison et objet) ou 0. Il est compris dans
 *   totalHt et totalTtc : c'est une ligne « Livraison » visible sur les
 *   documents, jamais un prix de ligne gonflé. Le serveur le contrôle à
 *   l'enregistrement (ReglagesSaisie::fraisPermis).
 * - objet : « Impressions », « Prestations informatiques »… imprimé en haut
 *   du devis, de la proforma et de la facture (« Objet : … »). Facultatif.
 *
 * Il suit le devis à la commande, puis la commande à la facture (copie).
 */
trait LivraisonObjetDocumentTrait
{
    #[ORM\Column(options: ['default' => 0])]
    private int $fraisLivraison = 0;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $objet = null;

    public function getFraisLivraison(): int
    {
        return $this->fraisLivraison;
    }

    public function setFraisLivraison(?int $montant): static
    {
        $this->fraisLivraison = max(0, $montant ?? 0);

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        // Une seule ligne, 150 caractères au plus.
        $objet = trim((string) preg_replace('/\s+/u', ' ', (string) $objet));
        $this->objet = '' === $objet ? null : mb_substr($objet, 0, 150);

        return $this;
    }
}
