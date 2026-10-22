<?php

namespace App\Entity;

use App\Repository\RelanceRetraitRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d'une relance envoyée à un client dont la commande est prête
 * mais pas encore récupérée : à la main (WhatsApp, SMS, appel) ou
 * automatiquement par une règle.
 */
#[ORM\Entity(repositoryClass: RelanceRetraitRepository::class)]
#[ORM\Table(name: 'relance_retrait')]
#[ORM\Index(name: 'idx_relance_retrait_commande_date', columns: ['commande_id', 'envoyee_le'])]
class RelanceRetrait
{
    public const CANAL_WHATSAPP = 'whatsapp';
    public const CANAL_SMS = 'sms';
    public const CANAL_EMAIL = 'email';
    public const CANAL_APPEL = 'appel';

    public const CANAUX_LABELS = [
        self::CANAL_WHATSAPP => 'WhatsApp',
        self::CANAL_SMS => 'SMS',
        self::CANAL_EMAIL => 'E-mail',
        self::CANAL_APPEL => 'Appel',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Commandes $commande = null;

    #[ORM\Column(length: 20)]
    private string $canal = self::CANAL_WHATSAPP;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RegleRappelPaiement $regle = null;

    public function __construct()
    {
        $this->envoyeeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function getCanalLibelle(): string
    {
        return self::CANAUX_LABELS[$this->canal] ?? $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = \array_key_exists($canal, self::CANAUX_LABELS) ? $canal : self::CANAL_WHATSAPP;

        return $this;
    }

    public function getEnvoyeeLe(): \DateTimeImmutable
    {
        return $this->envoyeeLe;
    }

    public function setEnvoyeeLe(\DateTimeImmutable $envoyeeLe): static
    {
        $this->envoyeeLe = $envoyeeLe;

        return $this;
    }

    public function getPar(): ?User
    {
        return $this->par;
    }

    public function setPar(?User $par): static
    {
        $this->par = $par;

        return $this;
    }

    public function getRegle(): ?RegleRappelPaiement
    {
        return $this->regle;
    }

    public function setRegle(?RegleRappelPaiement $regle): static
    {
        $this->regle = $regle;

        return $this;
    }

    public function isAutomatique(): bool
    {
        return $this->regle !== null;
    }
}
