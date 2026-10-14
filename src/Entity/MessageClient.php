<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fil de messages entre un client et l'atelier (Messages, maquette EC01) :
 * texte seulement, rattaché si besoin à une de ses commandes. Le client
 * écrit en ligne, le message monte au local ; l'atelier répond depuis
 * Clients › Messages et la réponse redescend, le client en est prévenu.
 */
#[ORM\Entity]
#[ORM\Table(name: 'message_client')]
#[ORM\Index(name: 'idx_message_client_fil', columns: ['client_id', 'cree_le'])]
class MessageClient
{
    use PublicIdTrait;

    public const CLIENT = 'client';
    public const ATELIER = 'atelier';

    /** Longueur d'un message. */
    public const TEXTE_MAX = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** client ou atelier. */
    #[ORM\Column(length: 10)]
    private string $sens = self::CLIENT;

    /** Personne du compte qui l'a écrit ; « Succès Imprim » pour l'atelier. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $texte = '';

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Au local : message du client traité (répondu ou classé). Ne part pas. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    /** Au local : employé qui a répondu. Ne part pas. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $ecritPar = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    public static function nouveau(Clients $client, string $sens, string $texte, ?string $auteur, ?Commandes $commande, \DateTimeImmutable $le): self
    {
        $m = new self();
        $m->client = $client;
        $m->sens = self::ATELIER === $sens ? self::ATELIER : self::CLIENT;
        $m->texte = mb_substr(trim($texte), 0, self::TEXTE_MAX);
        $m->auteur = null !== $auteur ? mb_substr($auteur, 0, 80) : null;
        $m->commande = $commande;
        $m->creeLe = $le;

        return $m;
    }

    public function estDeLAtelier(): bool
    {
        return self::ATELIER === $this->sens;
    }

    public function traiter(?\DateTimeImmutable $le): static
    {
        $this->traiteLe = $le;

        return $this;
    }

    public function setEcritPar(?User $u): static
    {
        $this->ecritPar = $u;

        return $this;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getCommande(): ?Commandes { return $this->commande; }
    public function getSens(): string { return $this->sens; }
    public function getAuteur(): ?string { return $this->auteur; }
    public function getTexte(): string { return $this->texte; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getTraiteLe(): ?\DateTimeImmutable { return $this->traiteLe; }
    public function getEcritPar(): ?User { return $this->ecritPar; }
}
