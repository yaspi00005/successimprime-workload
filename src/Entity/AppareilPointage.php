<?php

namespace App\Entity;

use App\Repository\AppareilPointageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Téléphone d'un employé pour pointer (option « Téléphone enregistré ») :
 * un nouvel appareil attend la validation de la RH.
 */
#[ORM\Entity(repositoryClass: AppareilPointageRepository::class)]
#[ORM\Table(name: 'appareil_pointage')]
#[ORM\UniqueConstraint(name: 'uniq_appareil_pointage_empreinte', columns: ['empreinte'])]
class AppareilPointage
{
    public const COOKIE = 'si_pointage';

    public const EN_ATTENTE = 'en_attente';
    public const AUTORISE = 'autorise';
    public const REFUSE = 'refuse';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $utilisateur;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 20)]
    private string $etat = self::EN_ATTENTE;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $decideLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $decidePar = null;

    public function __construct(User $utilisateur, string $jeton, string $nom)
    {
        $this->utilisateur = $utilisateur;
        $this->empreinte = self::empreinte($jeton);
        $this->nom = mb_substr($nom, 0, 120);
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    /** « Chrome · Android », d'après le navigateur. */
    public static function nomDepuis(?string $agent): string
    {
        $agent = (string) $agent;
        $systeme = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iPhone',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'Mac',
            default => 'appareil',
        };
        $navigateur = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Navigateur',
        };

        return $navigateur.' · '.$systeme;
    }

    public function decider(bool $autorise, ?User $par): static
    {
        $this->etat = $autorise ? self::AUTORISE : self::REFUSE;
        $this->decidePar = $par;
        $this->decideLe = new \DateTimeImmutable();

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): User
    {
        return $this->utilisateur;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function estAutorise(): bool
    {
        return $this->etat === self::AUTORISE;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }
}
