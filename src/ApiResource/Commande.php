<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as DocOperation;
use App\ApiResource\Objet\LigneCommande;
use App\ApiResource\Objet\Reference;
use App\State\CommandeProvider;

/**
 * Commande et ses lignes. Jamais de coût ni de marge ; les paiements
 * seulement avec le droit « Paiements ».
 */
#[ApiResource(
    shortName: 'Commande',
    description: 'Commandes, les plus récentes d\'abord. Droit « Commandes » (ROLE_COMMANDE).',
    operations: [
        new GetCollection(
            uriTemplate: '/commandes',
            openapi: new DocOperation(summary: 'Liste des commandes'),
            security: "is_granted('ROLE_COMMANDE')",
            parameters: [
                'q' => new QueryParameter(description: 'Numéro de commande ou nom du client'),
                'client' => new QueryParameter(description: 'Identifiant du client'),
                'depuis' => new QueryParameter(description: 'Date (AAAA-MM-JJ) : commandes passées depuis'),
                'aConfirmer' => new QueryParameter(description: '1 : seulement les commandes en ligne à confirmer'),
            ],
        ),
        new Get(uriTemplate: '/commandes/{id}', security: "is_granted('ROLE_COMMANDE')", openapi: new DocOperation(summary: 'Une commande')),
    ],
    provider: CommandeProvider::class,
)]
final class Commande
{
    /**
     * @param list<LigneCommande> $lignes
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public readonly int $id,
        public readonly ?string $numero,
        public readonly ?\DateTimeInterface $date,
        public readonly ?\DateTimeInterface $dateLivraison,
        public readonly ?Reference $client,
        /** comptoir, en_ligne... */
        public readonly string $canal,
        /** Faux pour une commande en ligne que l'atelier n'a pas encore confirmée. */
        public readonly bool $confirmee,
        public readonly string $statutTravaux,
        /** Total TTC en F CFA. */
        public readonly int $totalTtc,
        /** Déjà payé, en F CFA. Null sans le droit « Paiements » (ROLE_PAIEMENT_VOIR). */
        public readonly ?int $totalPaye,
        /** Reste à payer, en F CFA. Null sans le droit « Paiements ». */
        public readonly ?int $resteAPayer,
        /** non_paye, partiel, paye. Null sans le droit « Paiements ». */
        public readonly ?string $statutPaiement,
        public readonly array $lignes,
    ) {
    }
}
