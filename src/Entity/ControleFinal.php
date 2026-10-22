<?php

namespace App\Entity;

use App\Repository\ControleFinalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Contrôle final d'une commande (PD06, docs/production-colis.md) : la photo
 * de la pièce imprimée à côté du BAT, les vérifications cochées ligne par
 * ligne (textes, couleurs, taille mesurée, finitions, aucune trace), qui et
 * quand. Une ligne par commande ; « Refaire une pièce » rouvre le contrôle.
 * Reste à l'ERP local, jamais synchronisé.
 */
#[ORM\Entity(repositoryClass: ControleFinalRepository::class)]
#[ORM\Table(name: 'controle_final')]
#[ORM\UniqueConstraint(name: 'uniq_controle_final_commande', columns: ['commande_id'])]
class ControleFinal
{
    /** Les cases à cocher pour chaque ligne (maquette PD06). */
    public const POINTS = ['textes', 'couleurs', 'taille', 'finitions', 'traces'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Commandes::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Commandes $commande;

    /**
     * Par ligne de commande (clé = id de la ligne) : les cases cochées, la
     * taille mesurée en cm et la confirmation d'un écart de taille.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $verifications = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $validePar = null;

    /** Chemin relatif à var/uploads/production/controles (image réencodée, sans EXIF). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $photoLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $photoPar = null;

    /** « Refaire une pièce » : nombre de reprises demandées depuis ce contrôle. */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $reprises = 0;

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    public function __construct(Commandes $commande)
    {
        $this->commande = $commande;
        $this->modifieLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): Commandes
    {
        return $this->commande;
    }

    /** @return array<string, array<string, mixed>> */
    public function getVerifications(): array
    {
        return $this->verifications;
    }

    /** @return array<string, mixed> */
    public function verificationsLigne(int $ligne): array
    {
        return $this->verifications[(string) $ligne] ?? [];
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function estValide(): bool
    {
        return $this->valideLe !== null;
    }

    /**
     * Contrôle validé : toutes les cases de toutes les lignes sont cochées
     * (le contrôleur le vérifie avant).
     *
     * @param array<string, array<string, mixed>> $verifications
     */
    public function valider(array $verifications, User $par, \DateTimeImmutable $le): void
    {
        $this->verifications = $verifications;
        $this->valideLe = $le;
        $this->validePar = $par;
        $this->modifieLe = $le;
    }

    /** Une pièce est à refaire : le contrôle est à refaire avec elle. */
    public function rouvrir(\DateTimeImmutable $le): void
    {
        $this->valideLe = null;
        $this->validePar = null;
        ++$this->reprises;
        $this->modifieLe = $le;
    }

    public function getReprises(): int
    {
        return $this->reprises;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function getPhotoLe(): ?\DateTimeImmutable
    {
        return $this->photoLe;
    }

    public function getPhotoPar(): ?User
    {
        return $this->photoPar;
    }

    public function photographier(string $chemin, User $par, \DateTimeImmutable $le): void
    {
        $this->photo = $chemin;
        $this->photoLe = $le;
        $this->photoPar = $par;
        $this->modifieLe = $le;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }
}
