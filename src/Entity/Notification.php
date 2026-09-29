<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification interne generique (cloche du gabarit de base) :
 * nouvelle commande/devis, paiement reçu, étape de production,
 * stock bas... Chaque ligne cible un seul destinataire ; pour
 * notifier plusieurs personnes (ex. tous les admins), NotificationService
 * crée une ligne par destinataire.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notification_destinataire_lue', columns: ['destinataire_id', 'lue'])]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $destinataire = null;

    #[ORM\Column(length: 255)]
    private string $message = '';

    /**
     * Nom de route Symfony pour le lien de la notification (ex.
     * "app_commandes_show"), null si la notification n'est pas
     * cliquable.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $route = null;

    /**
     * Paramètres de la route (ex. ['id' => 42]).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $routeParametres = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $lue = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinataire(): ?User
    {
        return $this->destinataire;
    }

    public function setDestinataire(?User $destinataire): static
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = trim($message);

        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getRouteParametres(): array
    {
        return $this->routeParametres ?? [];
    }

    public function setRouteParametres(?array $routeParametres): static
    {
        $this->routeParametres = $routeParametres === [] ? null : $routeParametres;

        return $this;
    }

    public function isLue(): bool
    {
        return $this->lue;
    }

    public function marquerLue(): static
    {
        $this->lue = true;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }
}
