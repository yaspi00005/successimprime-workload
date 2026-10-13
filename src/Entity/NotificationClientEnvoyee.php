<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Notification déjà envoyée à un client (BAT prêt, commande prête,
 * paiement reçu…) et par quel moyen : chaque événement ne part qu'une
 * fois. Tenu sur le site en ligne seulement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_client_envoyee')]
#[ORM\UniqueConstraint(name: 'uniq_notification_client', columns: ['client_id', 'cle'])]
class NotificationClientEnvoyee
{
    public const PUSH = 'push';
    public const SMS = 'sms';
    public const EMAIL = 'email';
    /** Aucun moyen de joindre le client : elle reste dans son fil de notifications. */
    public const AUCUN = 'aucun';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    /** sha256 de l'événement (type, lien, date). */
    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(length: 10)]
    private string $canal;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeeLe;

    public function __construct(Clients $client, string $cle, string $type, string $canal, \DateTimeImmutable $envoyeeLe)
    {
        $this->client = $client;
        $this->cle = $cle;
        $this->type = $type;
        $this->canal = $canal;
        $this->envoyeeLe = $envoyeeLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Clients { return $this->client; }
    public function getCle(): string { return $this->cle; }
    public function getType(): string { return $this->type; }
    public function getCanal(): string { return $this->canal; }
    public function getEnvoyeeLe(): \DateTimeImmutable { return $this->envoyeeLe; }
}
