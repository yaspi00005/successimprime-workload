<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Téléphone (ou ordinateur) qui a accepté les notifications de
 * l'application : adresse du service de notification du navigateur et
 * ses deux clés de chiffrement. Une personne peut en avoir plusieurs.
 */
#[ORM\Entity]
#[ORM\Table(name: 'abonnement_push')]
#[ORM\UniqueConstraint(name: 'uniq_abonnement_push', columns: ['empreinte'])]
class AbonnementPush
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $utilisateur;

    #[ORM\Column(type: 'text')]
    private string $adresse;

    /** sha256 de l'adresse, pour l'unicité (l'adresse est trop longue pour un index). */
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

    public function __construct(User $utilisateur, string $adresse, string $clePublique, string $secret, ?string $appareil, \DateTimeImmutable $creeLe)
    {
        $this->utilisateur = $utilisateur;
        $this->adresse = $adresse;
        $this->empreinte = self::empreinte($adresse);
        $this->clePublique = $clePublique;
        $this->secret = $secret;
        $this->appareil = $appareil !== null ? mb_substr($appareil, 0, 120) : null;
        $this->creeLe = $creeLe;
    }

    public static function empreinte(string $adresse): string
    {
        return hash('sha256', $adresse);
    }

    public function getId(): ?int { return $this->id; }
    public function getUtilisateur(): User { return $this->utilisateur; }
    public function getAdresse(): string { return $this->adresse; }
    public function getClePublique(): string { return $this->clePublique; }
    public function getSecret(): string { return $this->secret; }
    public function getAppareil(): ?string { return $this->appareil; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getDernierEnvoiLe(): ?\DateTimeImmutable { return $this->dernierEnvoiLe; }

    /** Même navigateur ré-abonné (nouvelles clés) ou passé à une autre personne. */
    public function renouveler(User $utilisateur, string $clePublique, string $secret, ?string $appareil): void
    {
        $this->utilisateur = $utilisateur;
        $this->clePublique = $clePublique;
        $this->secret = $secret;
        $this->appareil = $appareil !== null ? mb_substr($appareil, 0, 120) : null;
    }

    public function noterEnvoi(\DateTimeImmutable $le): void
    {
        $this->dernierEnvoiLe = $le;
    }
}
