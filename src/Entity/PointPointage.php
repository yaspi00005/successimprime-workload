<?php

namespace App\Entity;

use App\Repository\PointPointageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Point de pointage (PT-01 Atelier, PT-02 Accueil) : son QR imprimé
 * porte un code secret. « Renouveler » change le secret et rend l'ancien
 * QR inutilisable.
 */
#[ORM\Entity(repositoryClass: PointPointageRepository::class)]
#[ORM\Table(name: 'point_pointage')]
#[ORM\UniqueConstraint(name: 'uniq_point_pointage_code', columns: ['code'])]
class PointPointage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $code;

    #[ORM\Column(length: 80)]
    private string $nom;

    #[ORM\Column(length: 64)]
    private string $secret;

    #[ORM\Column(nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column]
    private int $rayonMetres = 100;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column]
    private \DateTimeImmutable $renouveleLe;

    public function __construct(string $code, string $nom)
    {
        $this->code = $code;
        $this->nom = $nom;
        $this->renouveler(new \DateTimeImmutable());
    }

    public function renouveler(\DateTimeImmutable $le): static
    {
        $this->secret = bin2hex(random_bytes(16));
        $this->renouveleLe = $le;

        return $this;
    }

    public function secretValide(string $secret): bool
    {
        return $this->actif && hash_equals($this->secret, $secret);
    }

    /** Distance en mètres (formule de haversine), null sans coordonnées. */
    public function distance(float $latitude, float $longitude): ?int
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }
        $r = 6371000;
        $dLat = deg2rad($latitude - $this->latitude);
        $dLon = deg2rad($longitude - $this->longitude);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) * sin($dLon / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($a))));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setPosition(?float $latitude, ?float $longitude): static
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return $this;
    }

    public function getRayonMetres(): int
    {
        return $this->rayonMetres;
    }

    public function setRayonMetres(int $rayonMetres): static
    {
        $this->rayonMetres = max(10, $rayonMetres);

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getRenouveleLe(): \DateTimeImmutable
    {
        return $this->renouveleLe;
    }

    /** Tablette de l'entrée (maquette E05) : empreinte du jeton gardé dans son cookie. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tablette = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $tabletteLe = null;

    public function getTablette(): ?string { return $this->tablette; }
    public function getTabletteLe(): ?\DateTimeImmutable { return $this->tabletteLe; }

    public function brancherTablette(?string $empreinte, ?\DateTimeImmutable $le): static
    {
        $this->tablette = $empreinte;
        $this->tabletteLe = $empreinte !== null ? $le : null;

        return $this;
    }
}
