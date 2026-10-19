<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un fichier déposé en vrac dans le dossier « À classer » (photo WhatsApp
 * enregistrée, scan, téléchargement), maquette DS03. L'analyse propose le
 * client, la commande, le sous-dossier et un nom propre ; au classement,
 * l'ERP copie le fichier sous ce nom dans le bon sous-dossier et garde
 * l'original là où il était.
 *
 * « Retirer » l'enlève seulement de la liste : le fichier reste sur le
 * disque.
 */
#[ORM\Entity]
#[ORM\Table(name: 'fichier_a_classer')]
#[ORM\UniqueConstraint(name: 'uniq_fichier_a_classer_chemin', columns: ['chemin_hash'])]
#[ORM\Index(name: 'idx_fichier_a_classer_etat', columns: ['etat', 'apparu_le'])]
class FichierAClasser
{
    public const A_CLASSER = 'a_classer';
    public const CLASSE = 'classe';
    public const RETIRE = 'retire';
    public const DISPARU = 'disparu';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Chemin dans le dossier « À classer », séparé par « / ». */
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

    #[ORM\Column(length: 40)]
    private string $signature = '';

    /** « WhatsApp », « scanner », « Bureau · Desktop »… */
    #[ORM\Column(length: 120)]
    private string $origine = '';

    #[ORM\Column(length: 20)]
    private string $origineType = 'depot';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** Ce qui a fait deviner le client (« numéro de commande dans le nom »). */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $raison = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $sousDossier = null;

    #[ORM\Column(type: 'smallint')]
    private int $confiance = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomPropose = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $miniature = null;

    #[ORM\Column(length: 12)]
    private string $etat = self::A_CLASSER;

    #[ORM\Column]
    private \DateTimeImmutable $apparuLe;

    #[ORM\Column]
    private \DateTimeImmutable $vuLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $classeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $classePar = null;

    /** Où la copie a été rangée, dans le dossier du client. */
    #[ORM\Column(length: 700, nullable: true)]
    private ?string $cheminFinal = null;

    public function __construct(string $chemin, \DateTimeImmutable $maintenant)
    {
        $this->chemin = $chemin;
        $this->cheminHash = sha1($chemin);
        $this->nom = basename($chemin);
        $this->extension = strtolower(substr(pathinfo($chemin, \PATHINFO_EXTENSION), 0, 12));
        $this->modifieLe = $maintenant;
        $this->apparuLe = $maintenant;
        $this->vuLe = $maintenant;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    /** true si le fichier a changé. */
    public function noterDisque(int $octets, \DateTimeImmutable $modifieLe, \DateTimeImmutable $vuLe): bool
    {
        $signature = sha1($octets.'|'.$modifieLe->getTimestamp());
        $change = $signature !== $this->signature;
        $this->octets = (string) $octets;
        $this->modifieLe = $modifieLe;
        $this->signature = $signature;
        $this->vuLe = $vuLe;
        if (self::DISPARU === $this->etat) {
            $this->etat = self::A_CLASSER;
        }

        return $change;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function getOrigineType(): string
    {
        return $this->origineType;
    }

    public function setOrigine(string $type, string $libelle): static
    {
        $this->origineType = mb_substr($type, 0, 20);
        $this->origine = mb_substr($libelle, 0, 120);

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function getRaison(): ?string
    {
        return $this->raison;
    }

    public function getSousDossier(): ?string
    {
        return $this->sousDossier;
    }

    public function getConfiance(): int
    {
        return $this->confiance;
    }

    public function getNomPropose(): ?string
    {
        return $this->nomPropose;
    }

    public function proposer(?Clients $client, ?Commandes $commande, ?string $sousDossier, int $confiance, ?string $nomPropose, ?string $raison): static
    {
        $this->client = $client;
        $this->commande = $commande;
        $this->sousDossier = $sousDossier;
        $this->confiance = max(0, min(100, $confiance));
        $this->nomPropose = null === $nomPropose ? null : mb_substr($nomPropose, 0, 255);
        $this->raison = null === $raison ? null : mb_substr($raison, 0, 160);

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

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function estAClasser(): bool
    {
        return self::A_CLASSER === $this->etat;
    }

    public function classer(string $cheminFinal, ?User $par, \DateTimeImmutable $le): static
    {
        $this->etat = self::CLASSE;
        $this->cheminFinal = $cheminFinal;
        $this->classePar = $par;
        $this->classeLe = $le;

        return $this;
    }

    public function retirer(?User $par, \DateTimeImmutable $le): static
    {
        $this->etat = self::RETIRE;
        $this->classePar = $par;
        $this->classeLe = $le;

        return $this;
    }

    public function disparaitre(): static
    {
        if (self::A_CLASSER === $this->etat) {
            $this->etat = self::DISPARU;
        }

        return $this;
    }

    public function getApparuLe(): \DateTimeImmutable
    {
        return $this->apparuLe;
    }

    public function getVuLe(): \DateTimeImmutable
    {
        return $this->vuLe;
    }

    public function getClasseLe(): ?\DateTimeImmutable
    {
        return $this->classeLe;
    }

    public function getClassePar(): ?User
    {
        return $this->classePar;
    }

    public function getCheminFinal(): ?string
    {
        return $this->cheminFinal;
    }
}
