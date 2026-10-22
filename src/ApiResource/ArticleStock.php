<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as DocOperation;
use App\State\ArticleStockProvider;

/**
 * Articles du stock (papier, bâche, encre...). Le stock reste à
 * l'administrateur, comme à l'écran.
 */
#[ApiResource(
    shortName: 'ArticleStock',
    description: 'Articles du stock et quantités. Administrateur seulement.',
    operations: [
        new GetCollection(
            uriTemplate: '/stock',
            openapi: new DocOperation(summary: 'Liste des articles du stock'),
            security: "is_granted('ROLE_ADMIN')",
            parameters: [
                'q' => new QueryParameter(description: 'Référence, désignation ou nom commercial'),
                'alerte' => new QueryParameter(description: '1 : seulement les articles sous le seuil d\'alerte'),
            ],
        ),
        new Get(uriTemplate: '/stock/{id}', security: "is_granted('ROLE_ADMIN')", openapi: new DocOperation(summary: 'Un article du stock')),
    ],
    provider: ArticleStockProvider::class,
)]
final class ArticleStock
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly int $id,
        public readonly ?string $reference,
        public readonly ?string $designation,
        /** Nom connu de tous de l'article vendu, ou null. */
        public readonly ?string $nomCommercial,
        public readonly ?string $famille,
        public readonly string $unite,
        public readonly float $stock,
        public readonly float $seuilAlerte,
        public readonly bool $enAlerte,
        public readonly ?string $emplacement,
        /** Coût unitaire moyen en F CFA. Null sans le droit « Marges et coûts » (ROLE_COUT_MARGE). */
        public readonly ?int $coutUnitaire,
    ) {
    }
}
