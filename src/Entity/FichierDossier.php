<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fichier du dossier d'un client, tel que l'analyse l'a lu (maquette
 * DS01) : sous-dossier, taille, date, dimensions, miniature, famille de
 * versions, commande liée. C'est cette fiche que les écrans montrent : ils
 * ne lisent pas le disque à chaque affichage.
 *
 * Le fichier reste où il est : l'ERP le lit, il ne le déplace ni ne le
 * supprime jamais.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fichier_dossier')]
#[ORM\UniqueConstraint(name: 'uniq_fichier_dossier_chemin', columns: ['client_id', 'chemin_hash'])]
#[ORM\Index(name: 'idx_fichier_dossier_sd', columns: ['client_id', 'sous_dossier', 'absent'])]
class FichierDossier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    /** Clé du modèle de sous-dossiers, ou « _autres ». */
    #[ORM\Column(length: 40)]
    private string $sousDossier;

    /** Chemin dans le dossier du client, séparé par « / » (« 01 Conceptions/kakemono_v3.ai »). */
    #[ORM\Column(length: 700)]
    private string $chemin;

    #[ORM\Column(length: 40)]
    private string $cheminHash;

    #[ORM\Column(length: 255)]
    private string $nom;

    #[ORM\Column(length: 12)]
    private string $extension = '';

    #[ORM\Column(type: Types::BIGINT)]
    private string $octets = '0';

    #[ORM\Column]
    private \DateTimeImmutable $modifieLe;

    /** Taille et date lues sur le disque : le fichier n'est relu que s'il change. */
    #[ORM\Column(length: 40)]
    private string $signature = '';

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $dimensions = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $resume = null;

    /** Nom de la miniature dans var/dossiers-clients/miniatures, ou null (icône). */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $miniature = null;

    /** Famille de versions (« kakemono-soiree-jazz.ai ») et numéro (v1, v2…). */
    #[ORM\Column(length: 191)]
    private string $baseVersion = '';

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    private int $version = 1;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $commandeLieePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $commandeLieeLe = null;

    /** Qui l'a ajouté depuis l'ERP (déposé, classé), quand on le sait. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ajoutePar = null;

    /** Texte des PDF (case « Recherche dans le texte des PDF »). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $texte = null;

    #[ORM\Column]
    private \DateTimeImmutable $vuLe;

    #[ORM\Column(options: ['default' => false])]
    private bool $absent = false;

    public function __construct(Clients $client, string $chemin)
    {
        $this->client = $client;
        $this->chemin = $chemin;
        $this->cheminHash = sha1($chemin);
        $this->nom = basename($chemin);
        $this->extension = strtolower(substr(pathinfo($chemin, \PATHINFO_EXTENSION), 0, 12));
        $this->sousDossier = '_autres';
        $this->modifieLe = new \DateTimeImmutable();
        $this->vuLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Clients
    {
        return $this->client;
    }

    public function getSousDossier(): string
    {
        return $this->sousDossier;
    }

    public function setSousDossier(string $sousDossier): static
    {
        $this->sousDossier = $sousDossier;

        return $this;
    }

    public function getChemin(): string
    {
        return $this->chemin;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function getOctets(): int
    {
        return (int) $this->octets;
    }

    public function getModifieLe(): \DateTimeImmutable
    {
        return $this->modifieLe;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    /** Taille et date du disque ; true si le fichier a changé depuis la dernière lecture. */
    public function noterDisque(int $octets, \DateTimeImmutable $modifieLe, \DateTimeImmutable $vuLe): bool
    {
        $signature = sha1($octets.'|'.$modifieLe->getTimestamp());
        $change = $signature !== $this->signature;
        $this->octets = (string) $octets;
        $this->modifieLe = $modifieLe;
        $this->signature = $signature;
        $this->vuLe = $vuLe;
        $this->absent = false;

        return $change;
    }

    public function getDimensions(): ?string
    {
        return $this->dimensions;
    }

    public function getResume(): ?string
    {
        return $this->resume;
    }

    public function decrire(?string $dimensions, ?string $resume): static
    {
        $this->dimensions = null === $dimensions ? null : mb_substr($dimensions, 0, 160);
        $this->resume = null === $resume ? null : mb_substr($resume, 0, 160);

        return $this;
    }

    public function getMiniature(): ?string
    {
        return $this->miniature;
    }

    public function setMiniature(?string $miniature): static
    {
        $this->miniature = $miniature;

        return $this;
    }

    public function getBaseVersion(): string
    {
        return $this->baseVersion;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(string $base, int $version): static
    {
        $this->baseVersion = mb_substr($base, 0, 191);
        $this->version = max(1, min(999, $version));

        return $this;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function lierCommande(?Commandes $commande, ?User $par, \DateTimeImmutable $le): static
    {
        $this->commande = $commande;
        $this->commandeLieePar = null === $commande ? null : $par;
        $this->commandeLieeLe = null === $commande ? null : $le;

        return $this;
    }

    public function getCommandeLieePar(): ?User
    {
        return $this->commandeLieePar;
    }

    public function getCommandeLieeLe(): ?\DateTimeImmutable
    {
        return $this->commandeLieeLe;
    }

    public function getAjoutePar(): ?string
    {
        return $this->ajoutePar;
    }

    public function setAjoutePar(?string $ajoutePar): static
    {
        $this->ajoutePar = null === $ajoutePar ? null : mb_substr($ajoutePar, 0, 120);

        return $this;
    }

    public function getTexte(): ?string
    {
        return $this->texte;
    }

    public function setTexte(?string $texte): static
    {
        $this->texte = null === $texte ? null : mb_substr($texte, 0, 60000);

        return $this;
    }

    public function getVuLe(): \DateTimeImmutable
    {
        return $this->vuLe;
    }

    public function isAbsent(): bool
    {
        return $this->absent;
    }

    public function setAbsent(bool $absent): static
    {
        $this->absent = $absent;

        return $this;
    }

    public function estSource(): bool
    {
        return \in_array($this->extension, \App\Service\DossiersClients\NommageFichier::SOURCES, true);
    }
}
