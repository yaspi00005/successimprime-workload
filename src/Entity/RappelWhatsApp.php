<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Rappel du planning à envoyer par WhatsApp (étape 2 du planning).
 *
 * Sans clé Meta, rien ne part tout seul : la direction voit la liste
 * « Rappels à envoyer » et touche le bouton WhatsApp de chaque rappel
 * (lien wa.me avec le message prêt) ; l'heure du toucher est notée.
 */
#[ORM\Entity]
#[ORM\Table(name: 'rappel_whatsapp')]
#[ORM\UniqueConstraint(name: 'uniq_rappel_whatsapp', columns: ['tache_id', 'cle'])]
#[ORM\Index(name: 'idx_rappel_whatsapp_envoye', columns: ['envoye_le'])]
class RappelWhatsApp
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tache $tache;

    /** j5, veille, jour ou retard. */
    #[ORM\Column(length: 10)]
    private string $cle;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $destinataire;

    #[ORM\Column(length: 30)]
    private string $telephone;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $envoyePar = null;

    public function __construct(Tache $tache, string $cle, ?User $destinataire, string $telephone, string $message, \DateTimeImmutable $creeLe)
    {
        $this->tache = $tache;
        $this->cle = $cle;
        $this->destinataire = $destinataire;
        $this->telephone = $telephone;
        $this->message = $message;
        $this->creeLe = $creeLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getTache(): Tache { return $this->tache; }
    public function getCle(): string { return $this->cle; }
    public function getDestinataire(): ?User { return $this->destinataire; }
    public function getTelephone(): string { return $this->telephone; }
    public function getMessage(): string { return $this->message; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getEnvoyeLe(): ?\DateTimeImmutable { return $this->envoyeLe; }
    public function getEnvoyePar(): ?User { return $this->envoyePar; }
    public function estEnvoye(): bool { return $this->envoyeLe !== null; }

    public function marquerEnvoye(?User $par, \DateTimeImmutable $le): void
    {
        if ($this->envoyeLe === null) {
            $this->envoyeLe = $le;
            $this->envoyePar = $par;
        }
    }
}
