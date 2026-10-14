<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as DocOperation;
use App\State\ClientProvider;

/**
 * Fiche client, sans notes internes, coordonnées GPS ni historique.
 */
#[ApiResource(
    shortName: 'Client',
    description: 'Clients. Droit « Clients » (ROLE_CLIENT).',
    operations: [
        new GetCollection(
            uriTemplate: '/clients',
            openapi: new DocOperation(summary: 'Liste des clients'),
            security: "is_granted('ROLE_CLIENT')",
            parameters: [
                'q' => new QueryParameter(description: 'Nom, code ou téléphone'),
                'modifieDepuis' => new QueryParameter(description: 'Date (AAAA-MM-JJ) : clients créés ou modifiés depuis'),
            ],
        ),
        new Get(uriTemplate: '/clients/{id}', security: "is_granted('ROLE_CLIENT')", openapi: new DocOperation(summary: 'Un client')),
    ],
    provider: ClientProvider::class,
)]
final class Client
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly int $id,
        public readonly ?string $code,
        public readonly string $nom,
        /** Grille de prix : B2B (revendeur) ou B2C (prix normal), sans lien avec le type de compte. */
        public readonly string $type,
        public readonly ?string $telephone,
        public readonly ?string $email,
        public readonly ?string $ville,
        public readonly ?string $commune,
        public readonly ?string $quartier,
        public readonly bool $actif,
        public readonly ?\DateTimeInterface $creeLe,
        /** Plafond de crédit en F CFA. Null sans le droit « Paiements » (ROLE_PAIEMENT_VOIR). */
        public readonly ?int $plafondCredit,
        /** Crédit utilisé en F CFA. Null sans le droit « Paiements ». */
        public readonly ?int $soldeCredit,
    ) {
    }
}
