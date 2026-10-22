<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des échanges affiché sur l'écran Synchronisation (maquette 39).
 * Une ligne par donnée envoyée ou reçue, ou par incident.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synchro_echange')]
#[ORM\Index(name: 'idx_synchro_echange_le', columns: ['le'])]
class SynchroEchange
{
    public const VERS_EN_LIGNE = 'en_ligne'; // ⬆ local → en ligne
    public const VERS_LOCAL = 'local';       // ⬇ en ligne → local
    public const SYSTEME = 'systeme';        // liaison, incident

    public const OK = 'ok';
    public const REPRISE = 'reprise';
    public const CONFLIT = 'conflit';
    public const ERREUR = 'erreur';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $le;

    #[ORM\Column(length: 12)]
    private string $sens;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $type;

    #[ORM\Column(length: 255)]
    private string $libelle;

    #[ORM\Column(length: 10)]
    private string $statut;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message;

    /** Une erreur est résolue quand la même donnée finit par passer. */
    #[ORM\Column(options: ['default' => false])]
    private bool $resolu = false;

    public function __construct(string $sens, ?string $type, string $libelle, string $statut, ?string $message = null, ?\DateTimeImmutable $le = null)
    {
        $this->le = $le ?? new \DateTimeImmutable();
        $this->sens = $sens;
        $this->type = $type;
        $this->libelle = mb_substr($libelle, 0, 255);
        $this->statut = $statut;
        $this->message = $message === null ? null : mb_substr($message, 0, 2000);
    }

    public function getId(): ?int { return $this->id; }
    public function getLe(): \DateTimeImmutable { return $this->le; }
    public function getSens(): string { return $this->sens; }
    public function getType(): ?string { return $this->type; }
    public function getLibelle(): string { return $this->libelle; }
    public function getStatut(): string { return $this->statut; }
    public function getMessage(): ?string { return $this->message; }
    public function isResolu(): bool { return $this->resolu; }

    public function resoudre(): self
    {
        $this->resolu = true;

        return $this;
    }
}
