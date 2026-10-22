<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Réclamation d'un client sur un travail (maquette 18) : il l'écrit dans
 * son espace, avec des photos ; l'atelier répond et la clôt. À ne pas
 * confondre avec Reclamation (remboursement d'un agent).
 *
 * Créée en ligne, elle monte au local ; la réponse de l'atelier redescend.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reclamation_client')]
#[ORM\Index(name: 'idx_reclamation_client_statut', columns: ['statut'])]
class ReclamationClient
{
    use PublicIdTrait;

    public const OUVERTE = 'ouverte';
    public const EN_COURS = 'en_cours';
    public const RESOLUE = 'resolue';
    public const REFUSEE = 'refusee';

    public const STATUTS = [
        self::OUVERTE => 'Reçue',
        self::EN_COURS => 'En cours',
        self::RESOLUE => 'Résolue',
        self::REFUSEE => 'Non retenue',
    ];

    public const MOTIFS = [
        'couleur' => 'Couleurs',
        'finition' => 'Finition (œillets, découpe…)',
        'quantite' => 'Quantité',
        'texte' => 'Texte ou faute',
        'retard' => 'Retard',
        'livraison' => 'Livraison',
        'autre' => 'Autre',
    ];

    public const PHOTOS_MAX = 3;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** R-0018 ; W-R-0018 quand elle est créée en ligne. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** Personne qui l'a écrite (compte d'entreprise à plusieurs). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $auteur = null;

    #[ORM\Column(length: 20)]
    private string $motif = 'autre';

    #[ORM\Column(length: 120)]
    private string $objet = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    #[ORM\Column(length: 12)]
    private string $statut = self::OUVERTE;

    /** Réponse de l'atelier, lue par le client. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reponse = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $reponduPar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reponduLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closeLe = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** @var Collection<int, ReclamationClientPhoto> */
    #[ORM\OneToMany(targetEntity: ReclamationClientPhoto::class, mappedBy: 'reclamation')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $photos;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->photos = new ArrayCollection();
    }

    public static function nouvelle(Clients $client, string $objet, string $description, \DateTimeImmutable $creeLe): self
    {
        $r = new self();
        $r->client = $client;
        $r->objet = mb_substr(trim($objet), 0, 120);
        $r->description = mb_substr(trim($description), 0, 2000);
        $r->creeLe = $creeLe;

        return $r;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;

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

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function setAuteur(?string $auteur): static
    {
        $this->auteur = $auteur;

        return $this;
    }

    public function getMotif(): string
    {
        return $this->motif;
    }

    public function setMotif(string $motif): static
    {
        $this->motif = \array_key_exists($motif, self::MOTIFS) ? $motif : 'autre';

        return $this;
    }

    public function getLibelleMotif(): string
    {
        return self::MOTIFS[$this->motif] ?? 'Autre';
    }

    public function getObjet(): string
    {
        return $this->objet;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getLibelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function estClose(): bool
    {
        return \in_array($this->statut, [self::RESOLUE, self::REFUSEE], true);
    }

    /**
     * Réponse de l'atelier. Une réclamation close ne se rouvre pas ici : le
     * client en écrit une nouvelle.
     *
     * @throws \DomainException
     */
    public function repondre(string $reponse, string $statut, string $par, \DateTimeImmutable $le): void
    {
        if (!\array_key_exists($statut, self::STATUTS) || self::OUVERTE === $statut) {
            throw new \DomainException('Choisissez où en est la réclamation.');
        }
        if ($this->estClose()) {
            throw new \DomainException('Cette réclamation est déjà close.');
        }
        $reponse = trim($reponse);
        if ('' === $reponse) {
            throw new \DomainException('Écrivez la réponse que le client lira.');
        }
        $this->reponse = mb_substr($reponse, 0, 1000);
        $this->reponduPar = mb_substr($par, 0, 80);
        $this->reponduLe = $le;
        $this->statut = $statut;
        $this->closeLe = $this->estClose() ? $le : null;
    }

    public function getReponse(): ?string
    {
        return $this->reponse;
    }

    public function getReponduPar(): ?string
    {
        return $this->reponduPar;
    }

    public function getReponduLe(): ?\DateTimeImmutable
    {
        return $this->reponduLe;
    }

    public function getCloseLe(): ?\DateTimeImmutable
    {
        return $this->closeLe;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    /** « 1 jour », « 3 h » : temps entre la réclamation et sa clôture. */
    public function getDureeTraitement(): ?string
    {
        if (null === $this->closeLe) {
            return null;
        }
        $heures = max(1, intdiv($this->closeLe->getTimestamp() - $this->creeLe->getTimestamp(), 3600));
        if ($heures < 24) {
            return $heures.' h';
        }
        $jours = (int) round($heures / 24);

        return $jours.' jour'.($jours > 1 ? 's' : '');
    }

    /** @return Collection<int, ReclamationClientPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }
}
