<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as DocOperation;
use App\ApiResource\Objet\ConfigurationProduit;
use App\State\ProduitProvider;

/**
 * Catalogue : produits actifs et leurs prix publics. Jamais de coût de
 * revient ni de marge.
 */
#[ApiResource(
    shortName: 'Produit',
    description: 'Produits actifs du catalogue, avec les prix publics. Tout le personnel.',
    operations: [
        new GetCollection(
            uriTemplate: '/produits',
            openapi: new DocOperation(summary: 'Liste des produits'),
            security: "is_granted('ROLE_USER')",
            parameters: [
                'q' => new QueryParameter(description: 'Désignation, nom commercial, code du produit ou catégorie'),
                'publie' => new QueryParameter(description: '1 : seulement les produits montrés sur le site'),
            ],
        ),
        new Get(uriTemplate: '/produits/{id}', security: "is_granted('ROLE_USER')", openapi: new DocOperation(summary: 'Un produit')),
    ],
    provider: ProduitProvider::class,
)]
final class Produit
{
    /**
     * @param list<ConfigurationProduit> $configurations
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly int $id,
        public readonly string $nom,
        /** Nom connu de tous (« Autocollant » pour « Vinyle adhésif »), ou null. */
        public readonly ?string $nomCommercial,
        public readonly ?string $code,
        public readonly ?string $categorie,
        /** Montré sur le site public et dans l'espace client. */
        public readonly bool $publie,
        public readonly array $configurations,
    ) {
    }
}
