<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fichier du dossier d'un client que l'atelier publie pour lui
 * (« Envoyer au client », maquette DS01) : BAT, facture, visuel validé.
 * C'est une COPIE rangée sous var/uploads/publies ; elle part au site en
 * ligne avec la synchronisation et s'affiche dans l'espace client. Les
 * fichiers sources (AI, PSD) ne sont publiés que sur choix exprès.
 *
 * Retirer la publication cache la copie au client ; le fichier de
 * l'atelier n'est jamais touché.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fichier_publie')]
#[ORM\Index(name: 'idx_fichier_publie_client', columns: ['client_id', 'retire_le'])]
class FichierPublie
{
    use PublicIdTrait;

    public const CATEGORIES = ['bat' => 'BAT', 'facture' => 'Facture', 'visuel' => 'Visuel validé', 'document' => 'Document', 'source' => 'Fichier source'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** Fichier du dossier d'où vient la copie (au local seulement). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?FichierDossier $fichierDossier = null;

    #[ORM\Column(length: 255)]
    private string $nom;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column]
    private int $taille;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    /** Nom de la copie dans var/uploads/publies. */
    #[ORM\Column(length: 80)]
    private string $nomStockage;

    #[ORM\Column(length: 12)]
    private string $categorie;

    #[ORM\Column]
    private \DateTimeImmutable $publieLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $publiePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retireLe = null;

    /** Sans argument : copie reçue par la synchronisation (Serialiseur remplit les champs). */
    public function __construct(?Clients $client = null, string $nom = '', string $type = 'application/octet-stream', int $taille = 0, string $empreinte = '', string $nomStockage = '', string $categorie = 'document', ?\DateTimeImmutable $publieLe = null)
    {
        $this->initialiserPublicId();
        if (null !== $client) {
            $this->client = $client;
        }
        $this->nom = mb_substr($nom, 0, 255);
        $this->type = mb_substr($type, 0, 100);
        $this->taille = $taille;
        $this->empreinte = $empreinte;
        $this->nomStockage = $nomStockage;
        $this->categorie = \array_key_exists($categorie, self::CATEGORIES) ? $categorie : 'document';
        $this->publieLe = $publieLe ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Clients
    {
        return $this->client;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getFichierDossier(): ?FichierDossier
    {
        return $this->fichierDossier;
    }

    public function setFichierDossier(?FichierDossier $f): static
    {
        $this->fichierDossier = $f;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTaille(): int
    {
        return $this->taille;
    }

    public function getEmpreinte(): string
    {
        return $this->empreinte;
    }

    public function getNomStockage(): string
    {
        return $this->nomStockage;
    }

    public function getCategorie(): string
    {
        return $this->categorie;
    }

    public function getCategorieLibelle(): string
    {
        return self::CATEGORIES[$this->categorie] ?? $this->categorie;
    }

    public function getPublieLe(): \DateTimeImmutable
    {
        return $this->publieLe;
    }

    public function getPubliePar(): ?User
    {
        return $this->publiePar;
    }

    public function setPubliePar(?User $u): static
    {
        $this->publiePar = $u;

        return $this;
    }

    public function getRetireLe(): ?\DateTimeImmutable
    {
        return $this->retireLe;
    }

    public function setRetireLe(?\DateTimeImmutable $le): static
    {
        $this->retireLe = $le;

        return $this;
    }

    public function estVisible(): bool
    {
        return null === $this->retireLe;
    }
}
