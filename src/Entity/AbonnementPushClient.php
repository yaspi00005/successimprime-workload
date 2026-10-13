<?php

namespace App\Entity;

use App\Service\Push\DestinatairePush;
use Doctrine\ORM\Mapping as ORM;

/**
 * Téléphone d'un client qui a accepté les notifications de son espace
 * (application installée depuis /espace). Une personne d'un compte
 * entreprise a les siens.
 */
#[ORM\Entity]
#[ORM\Table(name: 'abonnement_push_client')]
#[ORM\UniqueConstraint(name: 'uniq_abonnement_push_client', columns: ['empreinte'])]
class AbonnementPushClient implements DestinatairePush
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CompteClient $compte;

    #[ORM\Column(type: 'text')]
    private string $adresse;

    /** sha256 de l'adresse : un navigateur = un abonnement. */
    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column(length: 120)]
    private string $clePublique;

    #[ORM\Column(length: 40)]
    private string $secret;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $appareil;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dernierEnvoiLe = null;

    public function __construct(CompteClient $compte, string $adresse, string $clePublique, string $secret, ?string $appareil, \DateTimeImmutable $creeLe)
    {
        $this->compte = $compte;
        $this->adresse = $adresse;
        $this->empreinte = AbonnementPush::empreinte($adresse);
        $this->clePublique = $clePublique;
        $this->secret = $secret;
        $this->appareil = null !== $appareil ? mb_substr($appareil, 0, 120) : null;
        $this->creeLe = $creeLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompte(): CompteClient { return $this->compte; }
    public function getAdresse(): string { return $this->adresse; }
    public function getClePublique(): string { return $this->clePublique; }
    public function getSecret(): string { return $this->secret; }
    public function getAppareil(): ?string { return $this->appareil; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getDernierEnvoiLe(): ?\DateTimeImmutable { return $this->dernierEnvoiLe; }

    /** Même navigateur ré-abonné (nouvelles clés) ou passé à une autre personne. */
    public function renouveler(CompteClient $compte, string $clePublique, string $secret, ?string $appareil): void
    {
        $this->compte = $compte;
        $this->clePublique = $clePublique;
        $this->secret = $secret;
        $this->appareil = null !== $appareil ? mb_substr($appareil, 0, 120) : null;
    }
}
