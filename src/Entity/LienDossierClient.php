<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Le dossier d'un client dans le dossier Clients de l'atelier (maquette
 * DS02) : lié (validé, ou code du client dans le nom) ou seulement
 * suggéré par le rapprochement des noms.
 *
 * La liaison suit un dossier renommé grâce au petit fichier caché
 * « .succes » posé dedans (identifiant du client) ; si l'écriture n'est
 * pas permise, elle reste par le nom du dossier.
 */
#[ORM\Entity]
#[ORM\Table(name: 'lien_dossier_client')]
#[ORM\Index(name: 'idx_lien_dossier_etat', columns: ['etat'])]
class LienDossierClient
{
    public const LIE = 'lie';
    public const SUGGERE = 'suggere';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Clients $client;

    /** Nom du dossier dans la racine (« AWA MODE - Awa Diarra »). */
    #[ORM\Column(length: 255)]
    private string $nomDossier;

    #[ORM\Column(length: 10)]
    private string $etat;

    #[ORM\Column(type: 'smallint')]
    private int $score = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $nombreFichiers = 0;

    /** Le fichier caché « .succes » a été posé dans le dossier. */
    #[ORM\Column(options: ['default' => false])]
    private bool $marquePosee = false;

    /** Le dossier n'a pas été retrouvé à la dernière analyse. */
    #[ORM\Column(options: ['default' => false])]
    private bool $absent = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lieLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $liePar = null;

    #[ORM\Column]
    private \DateTimeImmutable $majLe;

    public function __construct(Clients $client, string $nomDossier, string $etat, int $score)
    {
        $this->client = $client;
        $this->nomDossier = $nomDossier;
        $this->etat = $etat;
        $this->score = $score;
        $this->majLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Clients
    {
        return $this->client;
    }

    public function getNomDossier(): string
    {
        return $this->nomDossier;
    }

    public function getEtat(): string
    {
        return $this->etat;
    }

    public function estLie(): bool
    {
        return self::LIE === $this->etat;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function suggerer(string $nomDossier, int $score, int $fichiers): static
    {
        $this->nomDossier = $nomDossier;
        $this->etat = self::SUGGERE;
        $this->score = $score;
        $this->nombreFichiers = $fichiers;
        $this->majLe = new \DateTimeImmutable();

        return $this;
    }

    public function lier(string $nomDossier, int $score, ?User $par, \DateTimeImmutable $le): static
    {
        $this->nomDossier = $nomDossier;
        $this->etat = self::LIE;
        $this->score = $score;
        $this->liePar = $par;
        $this->lieLe = $le;
        $this->absent = false;
        $this->majLe = $le;

        return $this;
    }

    /** Dossier renommé, retrouvé par sa marque. */
    public function renommer(string $nomDossier): static
    {
        $this->nomDossier = $nomDossier;
        $this->absent = false;
        $this->majLe = new \DateTimeImmutable();

        return $this;
    }

    public function getNombreFichiers(): int
    {
        return $this->nombreFichiers;
    }

    public function setNombreFichiers(int $n): static
    {
        $this->nombreFichiers = $n;

        return $this;
    }

    public function isMarquePosee(): bool
    {
        return $this->marquePosee;
    }

    public function setMarquePosee(bool $posee): static
    {
        $this->marquePosee = $posee;

        return $this;
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

    public function getLieLe(): ?\DateTimeImmutable
    {
        return $this->lieLe;
    }

    public function getLiePar(): ?User
    {
        return $this->liePar;
    }

    public function getMajLe(): \DateTimeImmutable
    {
        return $this->majLe;
    }
}
