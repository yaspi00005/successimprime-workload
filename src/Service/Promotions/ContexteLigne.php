<?php

namespace App\Service\Promotions;

use App\Entity\Clients;
use App\Entity\Produits;

/**
 * Ce qu'il faut savoir d'une ligne de devis ou de commande pour choisir sa
 * promotion (CalculPromotion), rassemblé par PromotionsSaisie depuis la
 * base : rien ne vient du navigateur, sauf le code promo tapé.
 *
 * La promotion se juge au jour du document : modifier plus tard une
 * commande prise avant la promotion ne la lui donne pas, et une commande
 * prise pendant la garde après la fin.
 */
final class ContexteLigne
{
    /**
     * @param list<string>    $segments              segments calculés du client (sans le document en cours)
     * @param array<int, int> $utilisations          par promotion : commandes du client qui l'ont déjà utilisée (sans celle-ci)
     * @param list<int>       $exceptionsAcceptees   promotions dont l'exception B2B est acceptée pour cette ligne
     */
    public function __construct(
        public readonly ?Clients $client,
        public readonly ?Produits $produit,
        public readonly float $prixUnitaire,
        public readonly int $remiseSaisie,
        public readonly int $quantite,
        public readonly int $totalAvantRemise,
        /** Jour du devis ou de la commande (aujourd'hui pour un nouveau document). */
        public readonly \DateTimeImmutable $jour,
        public readonly array $segments = [],
        public readonly ?string $code = null,
        public readonly array $utilisations = [],
        public readonly array $exceptionsAcceptees = [],
    ) {
    }

    public function estB2b(): bool
    {
        return null !== $this->client && $this->client->isB2B();
    }
}
