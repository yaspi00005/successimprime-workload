<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Crédit IA d'un client, en F (tenu en ligne). Le solde n'est changé que
 * par une requête conditionnelle (CreditsIa) : deux générations lancées
 * ensemble ne dépensent jamais plus que le solde. Chaque changement a sa
 * ligne dans MouvementCreditIa.
 */
#[ORM\Entity]
#[ORM\Table(name: 'credit_ia')]
#[ORM\UniqueConstraint(name: 'uniq_credit_ia_client', columns: ['client_id'])]
class CreditIa
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    /** Peut passer sous zéro si la caisse rejette un paiement déjà crédité : la génération est alors bloquée. */
    #[ORM\Column]
    private int $solde = 0;

    public function __construct(Clients $client)
    {
        $this->client = $client;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Clients { return $this->client; }
    public function getSolde(): int { return $this->solde; }
}
