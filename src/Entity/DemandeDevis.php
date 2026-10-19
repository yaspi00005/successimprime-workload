<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Demande écrite par un visiteur du site, sans compte (maquette 11) : une
 * demande de devis (produit, taille, quantité, finitions, fichier) ou un
 * court message de la page Contact.
 *
 * Créée en ligne, elle monte au local (docs/synchronisation.md) et n'en
 * redescend pas : le site ne la montre plus à personne. Au local, elle
 * devient un prospect (ou rejoint le client du même numéro) et un devis
 * brouillon, que l'équipe vérifie avant tout envoi.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demande_devis')]
#[ORM\Index(name: 'idx_demande_devis_traitee', columns: ['traitee_le'])]
#[ORM\Index(name: 'idx_demande_devis_rappel', columns: ['nature', 'rappel_le'])]
class DemandeDevis
{
    use PublicIdTrait;

    public const NATURE_DEVIS = 'devis';
    public const NATURE_CONTACT = 'contact';
    /** Demande de rappel laissée atelier fermé (page Contact, maquette EC06). */
    public const NATURE_RAPPEL = 'rappel';

    /** Objets proposés sur la page Contact. */
    public const OBJETS = [
        'devis' => 'Demande de devis',
        'commande' => 'Suivi d\'une commande',
        'fichier' => 'Conseil sur un fichier ou un support',
        'reclamation' => 'Réclamation',
        'autre' => 'Autre question',
    ];

    /** Libellés courts des boutons « C'est pour… » de la page Contact (maquette EC04). */
    public const OBJETS_COURTS = [
        'devis' => 'Un devis',
        'commande' => 'Suivre une commande',
        'fichier' => 'Conseil fichier',
        'reclamation' => 'Réclamation',
        'autre' => 'Autre',
    ];

    public const MESSAGE_MAX = 2000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DD-0012 ; W-DD-0012 quand elle est écrite en ligne. Donné au visiteur. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numero = null;

    #[ORM\Column(length: 10)]
    private string $nature = self::NATURE_DEVIS;

    #[ORM\Column(length: 120)]
    private string $nom = '';

    /** Numéro malien normalisé (223 et 8 chiffres), non vérifié. */
    #[ORM\Column(length: 15)]
    private string $telephone = '';

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    /** Clé de OBJETS (page Contact). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $objet = null;

    /** Numéro de commande donné par le visiteur (page Contact), non vérifié. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $numeroCommande = null;

    /** Heure de rappel choisie (demande de rappel, atelier fermé). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rappelLe = null;

    /** Produit choisi dans le catalogue publié. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProduitConfiguration $configuration = null;

    /** Nom du produit au moment de la demande (gardé si le produit disparaît). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $designation = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 1, nullable: true)]
    private ?string $largeurCm = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 1, nullable: true)]
    private ?string $hauteurCm = null;

    #[ORM\Column(nullable: true)]
    private ?int $quantite = null;

    /**
     * Surface donnée par le visiteur quand il a indiqué sa quantité en m²
     * (pièces au m², docs/saisie-devis-commandes.md) ; la quantité reste le
     * nombre de pièces qui en découle.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 9, scale: 2, nullable: true)]
    private ?string $quantiteM2 = null;

    /** @var Collection<int, Finition> */
    #[ORM\ManyToMany(targetEntity: Finition::class)]
    #[ORM\JoinTable(name: 'demande_devis_finition')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $finitions;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** Fichier joint, rangé hors de public/ comme ceux de « Mes fichiers ». */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $fichier = null;

    /** Modèle de l'Atelier 3D validé par le visiteur (docs/atelier-3d.md) : vues, réglages, images. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CreationAtelier $creation = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** SMS d'accusé de réception parti (sans montant). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $accuseEnvoyeLe = null;

    /* ---------- Au local seulement (jamais envoyés) ---------- */

    /** Client du même numéro, ou fiche créée pour porter le devis. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Prospect $prospect = null;

    /** Devis brouillon préparé à l'arrivée. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Devis $devis = null;

    /** Conversation de la messagerie clients ouverte pour ce message (maquette EC08) : délai et retard imputé. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ConversationClient $conversation = null;

    /** Rappel fait (liste « À rappeler » de la caisse). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rappelFaitLe = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $rappelFaitPar = null;

    /** Alerte « rappel non fait à l'heure promise » déjà envoyée à la direction. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $rappelAlerteLe = null;

    /** Préparée au local (prospect, devis) : une seule fois. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $prepareeLe = null;

    /** Marquée traitée par l'équipe. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $traiteeLe = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $traiteePar = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->finitions = new ArrayCollection();
    }

    public static function nouvelle(string $nature, string $nom, string $telephone, \DateTimeImmutable $creeLe): self
    {
        $d = new self();
        $d->nature = \in_array($nature, [self::NATURE_CONTACT, self::NATURE_RAPPEL], true) ? $nature : self::NATURE_DEVIS;
        $d->nom = mb_substr(trim($nom), 0, 120);
        $d->telephone = $telephone;
        $d->creeLe = $creeLe;

        return $d;
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

    public function getNature(): string
    {
        return $this->nature;
    }

    public function estDevis(): bool
    {
        return self::NATURE_DEVIS === $this->nature;
    }

    public function estRappel(): bool
    {
        return self::NATURE_RAPPEL === $this->nature;
    }

    public function getNumeroCommande(): ?string
    {
        return $this->numeroCommande;
    }

    public function setNumeroCommande(?string $numero): static
    {
        $numero = strtoupper(trim((string) preg_replace('/\s+/', '', (string) $numero)));
        $this->numeroCommande = '' === $numero ? null : mb_substr($numero, 0, 50);

        return $this;
    }

    public function getRappelLe(): ?\DateTimeImmutable
    {
        return $this->rappelLe;
    }

    public function setRappelLe(?\DateTimeImmutable $rappelLe): static
    {
        $this->rappelLe = $rappelLe;

        return $this;
    }

    public function getConversation(): ?ConversationClient
    {
        return $this->conversation;
    }

    public function setConversation(?ConversationClient $conversation): static
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getRappelFaitLe(): ?\DateTimeImmutable
    {
        return $this->rappelFaitLe;
    }

    public function getRappelFaitPar(): ?string
    {
        return $this->rappelFaitPar;
    }

    public function marquerRappelFait(string $par, \DateTimeImmutable $le): static
    {
        $this->rappelFaitLe = $le;
        $this->rappelFaitPar = mb_substr($par, 0, 80);

        return $this;
    }

    public function getRappelAlerteLe(): ?\DateTimeImmutable
    {
        return $this->rappelAlerteLe;
    }

    public function noterRappelAlerte(\DateTimeImmutable $le): static
    {
        $this->rappelAlerteLe = $le;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function getTelephone(): string
    {
        return $this->telephone;
    }

    /** Les 8 chiffres, comme sur les fiches clients. */
    public function getTelephoneLocal(): string
    {
        return str_starts_with($this->telephone, '223') ? substr($this->telephone, 3) : $this->telephone;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = trim((string) $email);
        $this->email = '' === $email ? null : mb_substr($email, 0, 180);

        return $this;
    }

    public function getObjet(): ?string
    {
        return $this->objet;
    }

    public function setObjet(?string $objet): static
    {
        $this->objet = null !== $objet && isset(self::OBJETS[$objet]) ? $objet : null;

        return $this;
    }

    public function getLibelleObjet(): ?string
    {
        return null === $this->objet ? null : self::OBJETS[$this->objet];
    }

    public function getConfiguration(): ?ProduitConfiguration
    {
        return $this->configuration;
    }

    public function setConfiguration(?ProduitConfiguration $configuration): static
    {
        $this->configuration = $configuration;

        return $this;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $designation = trim((string) $designation);
        $this->designation = '' === $designation ? null : mb_substr($designation, 0, 255);

        return $this;
    }

    public function getLargeurCm(): ?float
    {
        return null === $this->largeurCm ? null : (float) $this->largeurCm;
    }

    public function getHauteurCm(): ?float
    {
        return null === $this->hauteurCm ? null : (float) $this->hauteurCm;
    }

    public function setTaille(?float $largeurCm, ?float $hauteurCm): static
    {
        $this->largeurCm = null === $largeurCm ? null : number_format($largeurCm, 1, '.', '');
        $this->hauteurCm = null === $hauteurCm ? null : number_format($hauteurCm, 1, '.', '');

        return $this;
    }

    public function getQuantite(): ?int
    {
        return $this->quantite;
    }

    public function setQuantite(?int $quantite): static
    {
        $this->quantite = $quantite;

        return $this;
    }

    public function getQuantiteM2(): ?float
    {
        return null === $this->quantiteM2 ? null : (float) $this->quantiteM2;
    }

    public function setQuantiteM2(?float $metresCarres): static
    {
        $this->quantiteM2 = null === $metresCarres ? null : number_format($metresCarres, 2, '.', '');

        return $this;
    }

    /** @return Collection<int, Finition> */
    public function getFinitions(): Collection
    {
        return $this->finitions;
    }

    public function addFinition(Finition $finition): static
    {
        if (!$this->finitions->contains($finition)) {
            $this->finitions->add($finition);
        }

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $message = trim((string) $message);
        $this->message = '' === $message ? null : mb_substr($message, 0, self::MESSAGE_MAX);

        return $this;
    }

    public function getCreation(): ?CreationAtelier
    {
        return $this->creation;
    }

    public function setCreation(?CreationAtelier $creation): static
    {
        $this->creation = $creation;

        return $this;
    }

    public function getFichier(): ?CommandeDetailFichier
    {
        return $this->fichier;
    }

    public function setFichier(?CommandeDetailFichier $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getAccuseEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->accuseEnvoyeLe;
    }

    public function noterAccuseEnvoye(\DateTimeImmutable $le): static
    {
        $this->accuseEnvoyeLe = $le;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function getProspect(): ?Prospect
    {
        return $this->prospect;
    }

    public function getDevis(): ?Devis
    {
        return $this->devis;
    }

    public function getPrepareeLe(): ?\DateTimeImmutable
    {
        return $this->prepareeLe;
    }

    /** Au local : ce que la demande est devenue. */
    public function preparer(?Clients $client, ?Prospect $prospect, ?Devis $devis, \DateTimeImmutable $le): static
    {
        $this->client = $client;
        $this->prospect = $prospect;
        $this->devis = $devis;
        $this->prepareeLe = $le;

        return $this;
    }

    public function getTraiteeLe(): ?\DateTimeImmutable
    {
        return $this->traiteeLe;
    }

    public function getTraiteePar(): ?string
    {
        return $this->traiteePar;
    }

    public function estTraitee(): bool
    {
        return null !== $this->traiteeLe;
    }

    public function marquerTraitee(string $par, \DateTimeImmutable $le): static
    {
        $this->traiteeLe = $le;
        $this->traiteePar = mb_substr($par, 0, 80);

        return $this;
    }

    public function rouvrir(): static
    {
        $this->traiteeLe = null;
        $this->traiteePar = null;

        return $this;
    }

    /** « 300 × 100 cm × 2 », pour la liste de l'équipe. */
    public function getResume(): string
    {
        $morceaux = [];
        $nombre = static fn (float $n): string => number_format($n, floor($n) == $n ? 0 : 1, ',', ' ');
        if (null !== $this->largeurCm && null !== $this->hauteurCm) {
            $morceaux[] = $nombre((float) $this->largeurCm).' × '.$nombre((float) $this->hauteurCm).' cm';
        }
        if (null !== $this->quantite) {
            $morceaux[] = '× '.$this->quantite;
        }
        if (null !== $this->quantiteM2) {
            $morceaux[] = '('.number_format((float) $this->quantiteM2, 2, ',', ' ').' m² demandés)';
        }

        return trim(($this->designation ?? '').' '.implode(' ', $morceaux));
    }
}
