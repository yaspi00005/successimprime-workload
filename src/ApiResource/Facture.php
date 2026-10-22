<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as DocOperation;
use App\ApiResource\Objet\Reference;
use App\State\FactureProvider;

/**
 * Factures et proformas émises (pas les brouillons), mêmes montants que
 * la comptabilité.
 */
#[ApiResource(
    shortName: 'Facture',
    description: 'Factures et proformas émises ou annulées, les plus récentes d\'abord. Droit « Factures » (ROLE_FACTURE).',
    operations: [
        new GetCollection(
            uriTemplate: '/factures',
            openapi: new DocOperation(summary: 'Liste des factures'),
            security: "is_granted('ROLE_FACTURE')",
            parameters: [
                'q' => new QueryParameter(description: 'Numéro de facture ou nom du client'),
                'client' => new QueryParameter(description: 'Identifiant du client'),
                'depuis' => new QueryParameter(description: 'Date (AAAA-MM-JJ) : factures datées depuis'),
                'type' => new QueryParameter(description: 'facture ou proforma'),
            ],
        ),
        new Get(uriTemplate: '/factures/{id}', security: "is_granted('ROLE_FACTURE')", openapi: new DocOperation(summary: 'Une facture')),
    ],
    provider: FactureProvider::class,
)]
final class Facture
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly int $id,
        public readonly ?string $numero,
        /** facture ou proforma. */
        public readonly string $type,
        /** emise ou annulee. */
        public readonly string $etat,
        public readonly ?\DateTimeInterface $date,
        public readonly ?\DateTimeInterface $echeance,
        public readonly ?string $commande,
        public readonly ?Reference $client,
        public readonly int $totalHt,
        public readonly int $montantTva,
        public readonly int $totalTtc,
        /** Déjà payé, en F CFA. Null sans le droit « Paiements » (ROLE_PAIEMENT_VOIR). */
        public readonly ?int $montantPaye,
        /** Reste à payer, en F CFA. Null sans le droit « Paiements ». */
        public readonly ?int $resteAPayer,
        /** Null sans le droit « Paiements ». */
        public readonly ?string $statutPaiement,
    ) {
    }
}
