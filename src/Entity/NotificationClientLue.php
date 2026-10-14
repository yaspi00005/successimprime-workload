<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Notification de l'espace client lue par une personne (maquettes EC02,
 * EC03 et EC05) : la clé est celle de l'événement du fil
 * (NotificationsClient::cle). Tenu sur le site en ligne seulement, rien
 * ne part au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_client_lue')]
#[ORM\UniqueConstraint(name: 'uniq_notification_client_lue', columns: ['compte_id', 'cle'])]
class NotificationClientLue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CompteClient $compte;

    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column]
    private \DateTimeImmutable $lueLe;

    public function __construct(CompteClient $compte, string $cle, \DateTimeImmutable $lueLe)
    {
        $this->compte = $compte;
        $this->cle = $cle;
        $this->lueLe = $lueLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompte(): CompteClient { return $this->compte; }
    public function getCle(): string { return $this->cle; }
    public function getLueLe(): \DateTimeImmutable { return $this->lueLe; }
}
