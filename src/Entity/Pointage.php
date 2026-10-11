<?php

namespace App\Entity;

use App\Repository\PointageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une présence : une arrivée et le départ qui la ferme (même le
 * lendemain). L'heure est toujours celle du serveur.
 */
#[ORM\Entity(repositoryClass: PointageRepository::class)]
#[ORM\Table(name: 'pointage')]
#[ORM\Index(name: 'idx_pointage_arrivee', columns: ['arrivee_le'])]
class Pointage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $utilisateur;

    #[ORM\Column]
    private \DateTimeImmutable $arriveeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $departLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PointPointage $pointArrivee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PointPointage $pointDepart = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $photoArrivee = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $photoDepart = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AppareilPointage $appareilArrivee = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?AppareilPointage $appareilDepart = null;

    /** @var array{latitude: float, longitude: float, distance: ?int}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $positionArrivee = null;

    /** @var array{latitude: float, longitude: float, distance: ?int}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $positionDepart = null;

    /** Les points de bonus (soirée, nuit) ne comptent qu'après validation. */
    #[ORM\Column]
    private bool $heuresSupValidees = false;

    #[ORM\Column]
    private int $ajustementPoints = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifAjustement = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motifCorrection = null;

    #[ORM\Column]
    private bool $corrige = false;

    public function __construct(User $utilisateur, \DateTimeImmutable $arriveeLe)
    {
        $this->utilisateur = $utilisateur;
        $this->arriveeLe = $arriveeLe;
    }

    /**
     * @param array{latitude: float, longitude: float, distance: ?int}|null $position
     */
    public function arriver(?PointPointage $point, ?string $photo, ?AppareilPointage $appareil, ?array $position): static
    {
        $this->pointArrivee = $point;
        $this->photoArrivee = $photo;
        $this->appareilArrivee = $appareil;
        $this->positionArrivee = $position;

        return $this;
    }

    /**
     * @param array{latitude: float, longitude: float, distance: ?int}|null $position
     */
    public function partir(\DateTimeImmutable $le, ?PointPointage $point, ?string $photo, ?AppareilPointage $appareil, ?array $position): static
    {
        $this->departLe = $le;
        $this->pointDepart = $point;
        $this->photoDepart = $photo;
        $this->appareilDepart = $appareil;
        $this->positionDepart = $position;

        return $this;
    }

    /** Correction manuelle par la RH, toujours avec un motif. */
    public function corriger(\DateTimeImmutable $arrivee, ?\DateTimeImmutable $depart, string $motif): static
    {
        $this->arriveeLe = $arrivee;
        $this->departLe = $depart;
        $this->motifCorrection = $motif;
        $this->corrige = true;

        return $this;
    }

    public function ajuster(int $points, string $motif): static
    {
        $this->ajustementPoints = $points;
        $this->motifAjustement = $motif;

        return $this;
    }

    public function oublierPhotos(): static
    {
        $this->photoArrivee = null;
        $this->photoDepart = null;

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

    public function getArriveeLe(): \DateTimeImmutable
    {
        return $this->arriveeLe;
    }

    public function getDepartLe(): ?\DateTimeImmutable
    {
        return $this->departLe;
    }

    public function getPointArrivee(): ?PointPointage
    {
        return $this->pointArrivee;
    }

    public function getPointDepart(): ?PointPointage
    {
        return $this->pointDepart;
    }

    public function getPhotoArrivee(): ?string
    {
        return $this->photoArrivee;
    }

    public function getPhotoDepart(): ?string
    {
        return $this->photoDepart;
    }

    public function getAppareilArrivee(): ?AppareilPointage
    {
        return $this->appareilArrivee;
    }

    public function getAppareilDepart(): ?AppareilPointage
    {
        return $this->appareilDepart;
    }

    /** @return array{latitude: float, longitude: float, distance: ?int}|null */
    public function getPositionArrivee(): ?array
    {
        return $this->positionArrivee;
    }

    /** @return array{latitude: float, longitude: float, distance: ?int}|null */
    public function getPositionDepart(): ?array
    {
        return $this->positionDepart;
    }

    public function isHeuresSupValidees(): bool
    {
        return $this->heuresSupValidees;
    }

    public function setHeuresSupValidees(bool $valide): static
    {
        $this->heuresSupValidees = $valide;

        return $this;
    }

    public function getAjustementPoints(): int
    {
        return $this->ajustementPoints;
    }

    public function getMotifAjustement(): ?string
    {
        return $this->motifAjustement;
    }

    public function getMotifCorrection(): ?string
    {
        return $this->motifCorrection;
    }

    public function isCorrige(): bool
    {
        return $this->corrige;
    }

    /** Dernier pointage enregistré, pour l'anti-doublon. */
    public function dernierPointage(): \DateTimeImmutable
    {
        return $this->departLe ?? $this->arriveeLe;
    }
}
