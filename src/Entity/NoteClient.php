<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Note ou compte rendu d'appel ajouté sur la fiche client (maquette K03).
 * Un rappel crée aussi une ligne d'action à la date choisie.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_note_client_le', columns: ['client_id', 'le'])]
class NoteClient
{
    public const TYPES = ['note' => 'Note', 'appel' => 'Compte rendu d\'appel', 'visite' => 'Visite'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $auteur;

    #[ORM\Column]
    private \DateTimeImmutable $le;

    #[ORM\Column(length: 10)]
    private string $type;

    #[ORM\Column(type: 'text')]
    private string $texte;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rappelLe = null;

    public function __construct(Clients $client, string $texte, string $type, ?User $auteur, \DateTimeImmutable $le)
    {
        $this->client = $client;
        $this->texte = mb_substr(trim($texte), 0, 2000);
        $this->type = isset(self::TYPES[$type]) ? $type : 'note';
        $this->auteur = $auteur;
        $this->le = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Clients { return $this->client; }
    public function getAuteur(): ?User { return $this->auteur; }
    public function getLe(): \DateTimeImmutable { return $this->le; }
    public function getType(): string { return $this->type; }
    public function getTypeLabel(): string { return self::TYPES[$this->type]; }
    public function getTexte(): string { return $this->texte; }
    public function getRappelLe(): ?\DateTimeImmutable { return $this->rappelLe; }
    public function setRappelLe(?\DateTimeImmutable $r): static { $this->rappelLe = $r; return $this; }
}
