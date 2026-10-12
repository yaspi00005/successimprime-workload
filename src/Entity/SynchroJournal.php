<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * File d'envoi de la synchronisation (docs/synchronisation.md) : une ligne
 * par donnée créée, modifiée ou supprimée de ce côté-ci, à faire passer de
 * l'autre côté. L'ordre d'envoi est celui de l'id.
 *
 * Le contenu est relu au moment de l'envoi : plusieurs modifications de la
 * même fiche avant l'envoi ne font partir que son dernier état.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synchro_journal')]
#[ORM\Index(name: 'idx_synchro_journal_attente', columns: ['envoye_le', 'id'])]
#[ORM\Index(name: 'idx_synchro_journal_donnee', columns: ['type', 'public_id', 'envoye_le'])]
class SynchroJournal
{
    public const MAJ = 'maj';
    public const SUPPRESSION = 'suppr';
    /** Geste du client fait en ligne, rejoué par le local avec ses propres règles (BAT). */
    public const ACTION = 'action';

    /** Au-delà, l'élément est marqué bloqué et attend une décision sur l'écran Synchronisation. */
    public const TENTATIVES_MAX = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Identifiant unique de l'envoi : l'autre côté ne l'applique qu'une fois. */
    #[ORM\Column(length: 36, unique: true)]
    private string $evenement;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(name: 'public_id', length: 36)]
    private string $publicId;

    #[ORM\Column(length: 10)]
    private string $operation;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $tentatives = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $erreur = null;

    /** Écarté à la main depuis l'écran Synchronisation (élément bloqué). */
    #[ORM\Column(name: 'ignore_manuel', options: ['default' => false])]
    private bool $ignore = false;

    /** Contenu d'une action (les autres éléments sont relus au moment de l'envoi). */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $donnees = null;

    public function __construct(string $type, string $publicId, string $operation, ?\DateTimeImmutable $le = null, ?array $donnees = null)
    {
        $this->donnees = $donnees;
        $this->evenement = Uuid::v7()->toRfc4122();
        $this->type = $type;
        $this->publicId = $publicId;
        $this->operation = $operation;
        $this->creeLe = $le ?? new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEvenement(): string { return $this->evenement; }
    public function getType(): string { return $this->type; }
    public function getPublicId(): string { return $this->publicId; }
    public function getOperation(): string { return $this->operation; }
    public function getDonnees(): ?array { return $this->donnees; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getEnvoyeLe(): ?\DateTimeImmutable { return $this->envoyeLe; }
    public function getTentatives(): int { return $this->tentatives; }
    public function getErreur(): ?string { return $this->erreur; }
    public function isIgnore(): bool { return $this->ignore; }

    public function estBloque(): bool
    {
        return $this->envoyeLe === null && !$this->ignore && $this->tentatives >= self::TENTATIVES_MAX;
    }

    public function marquerEnvoye(\DateTimeImmutable $le): self
    {
        $this->envoyeLe = $le;
        $this->erreur = null;

        return $this;
    }

    public function noterEchec(string $erreur): self
    {
        ++$this->tentatives;
        $this->erreur = mb_substr($erreur, 0, 1000);

        return $this;
    }

    public function ignorer(\DateTimeImmutable $le): self
    {
        $this->ignore = true;
        $this->envoyeLe = $le;

        return $this;
    }
}
