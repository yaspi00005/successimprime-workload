<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\SuspensionEspaceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Accès à l'espace client suspendu par l'imprimerie (demande de Yaya du
 * 15/10/2026), depuis la fiche client de l'ERP local : pour tout le compte
 * (chaque personne de l'entreprise) ou pour une seule personne, reconnue à
 * son numéro de connexion. Rien n'est effacé : la suspension se lève
 * (« Rétablir l'accès ») et la ligne reste, avec qui, quand et pourquoi.
 *
 * Seuls le client, le numéro, le début et la levée descendent au site
 * (CatalogueSynchro, « suspension_espace ») ; le motif, le nom et les
 * employés restent au local. En ligne, une personne suspendue ne se
 * connecte plus, sa session ouverte tombe à la requête suivante, et elle
 * ne reçoit ni notification, ni SMS, ni e-mail de l'espace client
 * (SuspensionsEspace).
 */
#[ORM\Entity(repositoryClass: SuspensionEspaceRepository::class)]
#[ORM\Table(name: 'suspension_espace_client')]
#[ORM\Index(name: 'idx_suspension_espace_client_levee', columns: ['client_id', 'levee_le'])]
class SuspensionEspace
{
    use PublicIdTrait;

    public const MOTIF_MAX = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** Numéro de connexion (223XXXXXXXX) de la personne suspendue ; null : tout le compte. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    /** Nom de la personne, pour la fiche (au local seulement). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nom = null;

    /** Pourquoi (au local seulement : le client lit seulement « contactez Succès Imprim »). */
    #[ORM\Column(length: self::MOTIF_MAX)]
    private string $motif = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $suspenduLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $suspenduPar = null;

    /** Accès rétabli ; null : la suspension tient toujours. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leveeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $leveePar = null;

    #[ORM\Column(length: self::MOTIF_MAX, nullable: true)]
    private ?string $noteLevee = null;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->suspenduLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    /** @throws \InvalidArgumentException numéro qui n'est pas malien */
    public function setTelephone(?string $telephone): static
    {
        if (null === $telephone || '' === trim($telephone)) {
            $this->telephone = null;

            return $this;
        }
        $this->telephone = CompteClient::normaliserTelephone($telephone) ?? throw new \InvalidArgumentException('Numéro de téléphone invalide (8 chiffres).');

        return $this;
    }

    /** Tout le compte (chaque personne de la fiche), pas une seule personne. */
    public function estToutLeCompte(): bool
    {
        return null === $this->telephone;
    }

    /** « 60 44 51 03 », pour la fiche. */
    public function getTelephoneAffiche(): ?string
    {
        return null === $this->telephone ? null : implode(' ', str_split(substr($this->telephone, 3), 2));
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $nom = mb_substr(trim((string) $nom), 0, 120);
        $this->nom = '' === $nom ? null : $nom;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $motif)), 0, self::MOTIF_MAX);

        return $this;
    }

    public function getSuspenduLe(): \DateTimeImmutable
    {
        return $this->suspenduLe;
    }

    public function setSuspenduLe(\DateTimeImmutable $le): static
    {
        $this->suspenduLe = $le;

        return $this;
    }

    public function getSuspenduPar(): ?User
    {
        return $this->suspenduPar;
    }

    public function setSuspenduPar(?User $par): static
    {
        $this->suspenduPar = $par;

        return $this;
    }

    public function getLeveeLe(): ?\DateTimeImmutable
    {
        return $this->leveeLe;
    }

    public function getLeveePar(): ?User
    {
        return $this->leveePar;
    }

    public function getNoteLevee(): ?string
    {
        return $this->noteLevee;
    }

    /** La suspension tient toujours. */
    public function estActive(): bool
    {
        return null === $this->leveeLe;
    }

    /** « Rétablir l'accès » : la ligne reste, avec qui et quand. */
    public function lever(\DateTimeImmutable $le, ?User $par, ?string $note = null): static
    {
        $this->leveeLe = $le;
        $this->leveePar = $par;
        $note = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $note)), 0, self::MOTIF_MAX);
        $this->noteLevee = '' === $note ? null : $note;

        return $this;
    }

    /** Cette suspension vise-t-elle ce numéro de connexion, sur la fiche ? */
    public function vise(string $telephone): bool
    {
        return $this->estActive() && (null === $this->telephone || $this->telephone === CompteClient::normaliserTelephone($telephone));
    }
}
