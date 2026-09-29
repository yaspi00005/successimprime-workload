<?php

namespace App\Entity;

use App\Repository\CampagneDestinataireRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un destinataire d'une campagne : suit l'envoi individuel (statut,
 * date, erreur éventuelle) pour un client donné. CampagneService
 * traite les lignes "en attente" par lots, appelé par la commande
 * app:envoyer-campagnes (tâche planifiée).
 */
#[ORM\Entity(repositoryClass: CampagneDestinataireRepository::class)]
#[ORM\Table(name: 'campagne_destinataire')]
#[ORM\Index(name: 'idx_campagne_destinataire_statut', columns: ['statut'])]
class CampagneDestinataire
{
    public const STATUT_EN_ATTENTE = 'en_attente';
    public const STATUT_ENVOYE = 'envoye';
    public const STATUT_ECHEC = 'echec';
    public const STATUT_IGNORE = 'ignore';

    public const STATUTS_LABELS = [
        self::STATUT_EN_ATTENTE => 'En attente',
        self::STATUT_ENVOYE => 'Envoyé',
        self::STATUT_ECHEC => 'Échec',
        self::STATUT_IGNORE => 'Ignoré',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Campagne::class, inversedBy: 'destinataires')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Campagne $campagne = null;

    #[ORM\ManyToOne(targetEntity: Clients::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::STATUT_EN_ATTENTE;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $erreur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateEnvoi = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampagne(): ?Campagne
    {
        return $this->campagne;
    }

    public function setCampagne(?Campagne $campagne): static
    {
        $this->campagne = $campagne;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getStatutLabel(): string
    {
        return self::STATUTS_LABELS[$this->statut] ?? $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getErreur(): ?string
    {
        return $this->erreur;
    }

    public function setErreur(?string $erreur): static
    {
        $this->erreur = $erreur;

        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeImmutable
    {
        return $this->dateEnvoi;
    }

    public function setDateEnvoi(?\DateTimeImmutable $dateEnvoi): static
    {
        $this->dateEnvoi = $dateEnvoi;

        return $this;
    }
}
