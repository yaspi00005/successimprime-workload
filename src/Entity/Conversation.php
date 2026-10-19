<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fil de la messagerie de l'équipe (docs/messagerie-interne.md, maquettes
 * MI01 à MI05) : un message privé entre deux personnes, ou un groupe
 * (permanent, projet lié à un client, un devis ou une commande, annonces).
 *
 * Les clients n'y ont jamais accès. La Direction lit les groupes si le
 * réglage le permet (sans en être membre), jamais les messages privés.
 */
#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(name: 'conversations')]
#[ORM\Index(name: 'idx_conversation_type', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
class Conversation
{
    public const PRIVE = 'prive';
    public const PERMANENT = 'permanent';
    public const PROJET = 'projet';
    public const ANNONCES = 'annonces';

    public const TYPES_GROUPE = [
        self::PERMANENT => ['libelle' => 'Permanent', 'aide' => 'ex. Atelier, Caisse'],
        self::PROJET => ['libelle' => 'Projet ou commande', 'aide' => 'se ferme quand le projet est livré'],
        self::ANNONCES => ['libelle' => 'Annonces', 'aide' => 'seuls les responsables écrivent'],
    ];

    /** Couleur de la vignette du groupe (maquette MI01). */
    public const COULEURS = ['bleu', 'vert', 'rose', 'ocre', 'marine', 'violet'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateDernierMessage = null;

    /**
     * Identifiant opaque utilisé dans l'URL à la place de l'id
     * numérique auto-incrémenté (pour ne pas exposer/laisser deviner
     * le nombre de conversations).
     */
    #[ORM\Column(length: 32, unique: true)]
    private string $jeton = '';

    #[ORM\Column(length: 12, options: ['default' => self::PRIVE])]
    private string $type = self::PRIVE;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $couleur = null;

    /** Groupe créé par l'application (« general », « atelier »…) : pas recréé, pas en double. */
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $parDefaut = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Clients $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Devis $devis = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    /** Projet : fermé tout seul quand la commande liée (ou celle du devis) est livrée. */
    #[ORM\Column(options: ['default' => false])]
    private bool $fermetureAuto = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $fermeLe = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $fermeMotif = null;

    /** Sinon, seuls les responsables du groupe envoient des fichiers. */
    #[ORM\Column(options: ['default' => true])]
    private bool $fichiersPourTous = true;

    /** Les fichiers envoyés vont aussi dans le dossier du client lié (case cochée d'office). */
    #[ORM\Column(options: ['default' => false])]
    private bool $rangerDossierClient = false;

    /**
     * Membres par rôle (« tout l'atelier ») : codes des rôles de base
     * (ROLE_PRODUCTION…). Les personnes de ces rôles sont ajoutées au groupe,
     * y compris celles arrivées après sa création.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $rolesMembres = [];

    /**
     * Personnes retirées d'un groupe alors que leur rôle en fait partie :
     * elles n'y reviennent pas toutes seules.
     *
     * @var list<int>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $exclus = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    /** @var Collection<int, ConversationParticipant> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: ConversationParticipant::class, orphanRemoval: true, cascade: ['persist'])]
    private Collection $participants;

    /** @var Collection<int, Message> */
    #[ORM\OneToMany(mappedBy: 'conversation', targetEntity: Message::class, orphanRemoval: true)]
    #[ORM\OrderBy(['dateEnvoi' => 'ASC'])]
    private Collection $messages;

    public function __construct()
    {
        $this->participants = new ArrayCollection();
        $this->messages = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function initialiserDateCreation(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function initialiserJeton(): void
    {
        if ($this->jeton === '') {
            $this->jeton = bin2hex(random_bytes(16));
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDateDernierMessage(): ?\DateTimeImmutable
    {
        return $this->dateDernierMessage;
    }

    public function setDateDernierMessage(\DateTimeImmutable $dateDernierMessage): static
    {
        $this->dateDernierMessage = $dateDernierMessage;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = \in_array($type, [self::PRIVE, self::PERMANENT, self::PROJET, self::ANNONCES], true) ? $type : self::PERMANENT;

        return $this;
    }

    public function estPrive(): bool
    {
        return self::PRIVE === $this->type;
    }

    public function estGroupe(): bool
    {
        return !$this->estPrive();
    }

    public function estAnnonces(): bool
    {
        return self::ANNONCES === $this->type;
    }

    public function estProjet(): bool
    {
        return self::PROJET === $this->type;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $nom = trim((string) $nom);
        $this->nom = '' === $nom ? null : mb_substr($nom, 0, 80);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = trim((string) $description);
        $this->description = '' === $description ? null : mb_substr($description, 0, 160);

        return $this;
    }

    public function getCouleur(): string
    {
        return $this->couleur ?? 'bleu';
    }

    public function setCouleur(?string $couleur): static
    {
        $this->couleur = \in_array($couleur, self::COULEURS, true) ? $couleur : null;

        return $this;
    }

    public function getParDefaut(): ?string
    {
        return $this->parDefaut;
    }

    public function setParDefaut(?string $parDefaut): static
    {
        $this->parDefaut = $parDefaut;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getDevis(): ?Devis
    {
        return $this->devis;
    }

    public function setDevis(?Devis $devis): static
    {
        $this->devis = $devis;

        return $this;
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

    /** La commande dont la livraison ferme le groupe : la commande liée, ou celle du devis. */
    public function getCommandeSuivie(): ?Commandes
    {
        return $this->commande ?? $this->devis?->getCommande();
    }

    public function isFermetureAuto(): bool
    {
        return $this->fermetureAuto;
    }

    public function setFermetureAuto(bool $fermetureAuto): static
    {
        $this->fermetureAuto = $fermetureAuto;

        return $this;
    }

    public function estFerme(): bool
    {
        return null !== $this->fermeLe;
    }

    public function getFermeLe(): ?\DateTimeImmutable
    {
        return $this->fermeLe;
    }

    public function getFermeMotif(): ?string
    {
        return $this->fermeMotif;
    }

    public function fermer(\DateTimeImmutable $le, string $motif): static
    {
        $this->fermeLe = $le;
        $this->fermeMotif = mb_substr($motif, 0, 160);

        return $this;
    }

    public function rouvrir(): static
    {
        $this->fermeLe = null;
        $this->fermeMotif = null;

        return $this;
    }

    public function isFichiersPourTous(): bool
    {
        return $this->fichiersPourTous;
    }

    public function setFichiersPourTous(bool $fichiersPourTous): static
    {
        $this->fichiersPourTous = $fichiersPourTous;

        return $this;
    }

    public function isRangerDossierClient(): bool
    {
        return $this->rangerDossierClient;
    }

    public function setRangerDossierClient(bool $rangerDossierClient): static
    {
        $this->rangerDossierClient = $rangerDossierClient;

        return $this;
    }

    /** @return list<string> */
    public function getRolesMembres(): array
    {
        return array_values(array_unique(array_filter($this->rolesMembres, 'is_string')));
    }

    /** @param list<string> $roles */
    public function setRolesMembres(array $roles): static
    {
        $this->rolesMembres = array_values(array_unique(array_filter($roles, static fn (mixed $r): bool => \is_string($r) && 1 === preg_match('/^ROLE_[A-Z0-9_]{1,50}$/', $r))));

        return $this;
    }

    /** @return list<int> */
    public function getExclus(): array
    {
        return array_values(array_map('intval', $this->exclus));
    }

    public function exclure(User $utilisateur): static
    {
        if (null !== $utilisateur->getId() && !\in_array($utilisateur->getId(), $this->getExclus(), true)) {
            $this->exclus[] = $utilisateur->getId();
        }

        return $this;
    }

    public function reintegrer(User $utilisateur): static
    {
        $this->exclus = array_values(array_filter($this->getExclus(), static fn (int $id): bool => $id !== $utilisateur->getId()));

        return $this;
    }

    public function estExclu(User $utilisateur): bool
    {
        return \in_array($utilisateur->getId(), $this->getExclus(), true);
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    /**
     * @return Collection<int, ConversationParticipant>
     */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /**
     * @return Collection<int, Message>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function ajouterParticipant(User $utilisateur, bool $admin = false): static
    {
        $existant = $this->getParticipantPour($utilisateur);
        if (null !== $existant) {
            if ($admin) {
                $existant->setAdmin(true);
            }

            return $this;
        }

        $participant = (new ConversationParticipant())
            ->setConversation($this)
            ->setUtilisateur($utilisateur)
            ->setAdmin($admin);

        $this->participants->add($participant);

        return $this;
    }

    public function retirerParticipant(User $utilisateur): static
    {
        $participant = $this->getParticipantPour($utilisateur);
        if (null !== $participant) {
            $this->participants->removeElement($participant);
        }

        return $this;
    }

    public function aPourParticipant(User $utilisateur): bool
    {
        return $this->getParticipantPour($utilisateur) !== null;
    }

    public function getParticipantPour(User $utilisateur): ?ConversationParticipant
    {
        foreach ($this->participants as $participant) {
            $autre = $participant->getUtilisateur();
            if ($autre === $utilisateur || (null !== $autre?->getId() && $autre->getId() === $utilisateur->getId())) {
                return $participant;
            }
        }

        return null;
    }

    /** Responsable du groupe (crée, écrit dans les annonces, ajoute ou retire des membres). */
    public function estAdmin(User $utilisateur): bool
    {
        return true === $this->getParticipantPour($utilisateur)?->isAdmin();
    }

    /** @return list<User> membres actifs */
    public function getMembres(): array
    {
        $membres = [];
        foreach ($this->participants as $participant) {
            $u = $participant->getUtilisateur();
            if (null !== $u && $u->isActif()) {
                $membres[] = $u;
            }
        }

        return $membres;
    }

    /**
     * Libellé de la conversation pour un utilisateur donné : le nom du
     * groupe, ou l'autre personne d'un message privé.
     */
    public function getLibellePour(User $utilisateur): string
    {
        if ($this->estGroupe()) {
            return $this->nom ?? 'Groupe';
        }

        $autre = $this->getAutreUtilisateurPour($utilisateur);

        return null === $autre ? 'Vous' : self::nomDe($autre);
    }

    /**
     * L'autre participant d'une conversation directe (à deux), pour
     * afficher son avatar/nom.
     */
    public function getAutreUtilisateurPour(User $utilisateur): ?User
    {
        foreach ($this->participants as $participant) {
            $autre = $participant->getUtilisateur();
            if (null !== $autre && $autre !== $utilisateur && $autre->getId() !== $utilisateur->getId()) {
                return $autre;
            }
        }

        return null;
    }

    public function getDernierMessage(): ?Message
    {
        return $this->messages->last() ?: null;
    }

    /** « Oumar Sangaré » (fiche employé), sinon l'identifiant. */
    public static function nomDe(?User $u): string
    {
        if (null === $u) {
            return '';
        }
        $employe = $u->getEmploye();
        $nom = null !== $employe ? trim(($employe->getPrenom() ?? '').' '.($employe->getNom() ?? '')) : '';

        return '' !== $nom ? $nom : (string) $u->getUsername();
    }
}
