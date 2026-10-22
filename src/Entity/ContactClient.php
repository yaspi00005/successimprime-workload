<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Personne à joindre chez un client (maquette K02) : directrice,
 * comptable… Le contact principal est celui des relances et de WhatsApp.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['telephone'])]
class ContactClient
{
    public const ROLES = ['principal' => 'Principal', 'factures' => 'Factures', 'bat' => 'BAT', 'autre' => 'Autre'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    #[ORM\Column(length: 120)]
    private string $nom;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $fonction = null;

    /** Numéro normalisé, 8 chiffres (sans indicatif). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $whatsapp = true;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 20, options: ['default' => 'autre'])]
    private string $role = 'autre';

    public function __construct(Clients $client, string $nom)
    {
        $this->client = $client;
        $this->setNom($nom);
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Clients { return $this->client; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $n): static { $this->nom = mb_substr(trim($n), 0, 120); return $this; }
    public function getFonction(): ?string { return $this->fonction; }
    public function setFonction(?string $f): static { $this->fonction = $f !== null && trim($f) !== '' ? mb_substr(trim($f), 0, 80) : null; return $this; }
    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(?string $t): static { $t = self::chiffres($t); $this->telephone = $t !== '' ? $t : null; return $this; }
    public function isWhatsapp(): bool { return $this->whatsapp; }
    public function setWhatsapp(bool $w): static { $this->whatsapp = $w; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $e): static { $this->email = $e !== null && filter_var(trim($e), \FILTER_VALIDATE_EMAIL) ? mb_substr(trim($e), 0, 120) : null; return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $r): static { $this->role = isset(self::ROLES[$r]) ? $r : 'autre'; return $this; }
    public function getRoleLabel(): string { return self::ROLES[$this->role]; }

    /** « 76 12 34 56 » ou le numéro tel quel. */
    public function getTelephoneAffiche(): ?string
    {
        return $this->telephone !== null && \strlen($this->telephone) === 8 ? implode(' ', str_split($this->telephone, 2)) : $this->telephone;
    }

    /** Chiffres d'un numéro malien, sans l'indicatif 223. */
    public static function chiffres(?string $t): string
    {
        $c = (string) preg_replace('/\D+/', '', (string) $t);
        if (\strlen($c) === 11 && str_starts_with($c, '223')) {
            $c = substr($c, 3);
        } elseif (\strlen($c) === 13 && str_starts_with($c, '00223')) {
            $c = substr($c, 5);
        }

        return $c;
    }
}
