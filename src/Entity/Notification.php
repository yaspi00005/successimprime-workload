<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Notification interne (cloche du gabarit de base, centre de notifications,
 * téléphone). Chaque ligne cible un seul destinataire ; pour notifier
 * plusieurs personnes, NotificationService ou NotificationsEquipe crée une
 * ligne par destinataire.
 *
 * Notifications de l'équipe à niveaux (docs/notifications-equipe.md) :
 * une sorte (type), un niveau (critique, urgent, à faire, info), un bouton
 * d'action (libellé + route), une empreinte qui regroupe les répétitions
 * (compteur), un report, une résolution (automatique quand l'action est
 * faite) et la remontée au responsable puis à la direction. Les anciennes
 * notifications (sans type) restent des « info » qui disparaissent une fois
 * lues.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(name: 'idx_notification_destinataire_lue', columns: ['destinataire_id', 'lue'])]
#[ORM\Index(name: 'idx_notification_destinataire_resolue', columns: ['destinataire_id', 'resolue_le'])]
#[ORM\Index(name: 'idx_notification_empreinte', columns: ['empreinte'])]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    public const LONGUEUR_MAX = 255;

    public const CRITIQUE = 'critique';
    public const URGENT = 'urgent';
    public const A_FAIRE = 'a_faire';
    public const INFO = 'info';

    /** Du plus grave au plus léger. */
    public const NIVEAUX = [
        self::CRITIQUE => 'Critique',
        self::URGENT => 'Urgent',
        self::A_FAIRE => 'À faire',
        self::INFO => 'Info',
    ];

    /** Comment elle a disparu. */
    public const RESOLUE_AUTO = 'auto';
    public const RESOLUE_ACTION = 'action';
    public const RESOLUE_LUE = 'lue';
    public const RESOLUE_ACCORD = 'accord';
    public const RESOLUE_REFUS = 'refus';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $destinataire = null;

    #[ORM\Column(length: 255)]
    private string $message = '';

    /**
     * Nom de route Symfony pour le lien de la notification (ex.
     * "app_commandes_show"), null si la notification n'est pas
     * cliquable.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $route = null;

    /**
     * Paramètres de la route (ex. ['id' => 42]).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $routeParametres = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $lue = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    /** Sorte (TypesNotification), null pour une ancienne notification. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(length: 10, options: ['default' => self::INFO])]
    private string $niveau = self::INFO;

    /** Seconde ligne (client, commande, cause), sans montant. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $detail = null;

    /** Libellé du bouton d'action (« Valider », « Relancer »…), vers la route. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $actionLibelle = null;

    /** Ce qui reconnaît la même alerte (une seule ligne active par personne). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $empreinte = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $compteur = 1;

    /** Montant affiché seulement à qui a le droit $droitMontant. */
    #[ORM\Column(nullable: true)]
    private ?int $montant = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $droitMontant = null;

    #[ORM\Column(name: 'mise_a_jour_le', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $miseAJourLe = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reporteeJusqua = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolueLe = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $resolution = null;

    /** Dernière petite fenêtre (ou son) montrée à l'écran. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $alerteeLe = null;

    /** Dernier rappel envoyé sur le téléphone (urgent non traité). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rappeleLe = null;

    /** Remontées déjà faites (0 : aucune, 1 : responsable, 2 : direction). */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $escalade = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $escaladeeLe = null;

    /** Née pendant les heures calmes (hors critique) : ni téléphone, ni petite fenêtre, ni son. */
    #[ORM\Column(options: ['default' => false])]
    private bool $silencieuse = false;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        $this->dateCreation ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinataire(): ?User
    {
        return $this->destinataire;
    }

    public function setDestinataire(?User $destinataire): static
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        // La colonne garde 255 caractères : un message plus long (cause d'une panne) est coupé proprement.
        $message = trim($message);
        $this->message = mb_strlen($message) > self::LONGUEUR_MAX ? rtrim(mb_substr($message, 0, self::LONGUEUR_MAX - 1)).'…' : $message;

        return $this;
    }

    /**
     * Une alerte qui se répète (synchronisation en panne) reprend la
     * notification encore non lue au lieu d'en ajouter une : nouveau texte,
     * nouvelle date, elle remonte en haut de la cloche.
     */
    public function remettreAJour(string $message, \DateTimeImmutable $le): static
    {
        $this->setMessage($message);
        $this->dateCreation = $le;

        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getRouteParametres(): array
    {
        return $this->routeParametres ?? [];
    }

    public function setRouteParametres(?array $routeParametres): static
    {
        $this->routeParametres = $routeParametres === [] ? null : $routeParametres;

        return $this;
    }

    public function isLue(): bool
    {
        return $this->lue;
    }

    public function marquerLue(): static
    {
        $this->lue = true;

        return $this;
    }

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getNiveau(): string
    {
        return $this->niveau;
    }

    public function setNiveau(string $niveau): static
    {
        if (!isset(self::NIVEAUX[$niveau])) {
            throw new \InvalidArgumentException('Niveau inconnu : '.$niveau);
        }
        $this->niveau = $niveau;

        return $this;
    }

    public function getLibelleNiveau(): string
    {
        return self::NIVEAUX[$this->niveau];
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function setDetail(?string $detail): static
    {
        $detail = null !== $detail ? trim($detail) : null;
        $this->detail = null === $detail || '' === $detail ? null : (mb_strlen($detail) > self::LONGUEUR_MAX ? rtrim(mb_substr($detail, 0, self::LONGUEUR_MAX - 1)).'…' : $detail);

        return $this;
    }

    public function getActionLibelle(): ?string
    {
        return $this->actionLibelle;
    }

    public function setActionLibelle(?string $actionLibelle): static
    {
        $this->actionLibelle = null !== $actionLibelle ? mb_substr($actionLibelle, 0, 40) : null;

        return $this;
    }

    public function getEmpreinte(): ?string
    {
        return $this->empreinte;
    }

    public function setEmpreinte(?string $empreinte): static
    {
        $this->empreinte = null !== $empreinte ? mb_substr($empreinte, 0, 100) : null;

        return $this;
    }

    public function getCompteur(): int
    {
        return $this->compteur;
    }

    /**
     * La même alerte revient : une fois de plus, nouveau texte, sans
     * nouvelle ligne (règle anti-bruit « envoyée une fois puis regroupée »).
     */
    public function regrouper(string $message, ?string $detail, \DateTimeImmutable $le): static
    {
        ++$this->compteur;
        $this->setMessage($message);
        $this->setDetail($detail);
        $this->miseAJourLe = $le;

        return $this;
    }

    /** Mise à jour du texte d'une alerte qui dure (sans compter une fois de plus). */
    public function actualiserTexte(string $message, ?string $detail, ?int $montant, \DateTimeImmutable $le): static
    {
        if ($message !== $this->message || $detail !== $this->detail || $montant !== $this->montant) {
            $this->setMessage($message);
            $this->setDetail($detail);
            $this->montant = $montant;
            $this->miseAJourLe = $le;
        }

        return $this;
    }

    public function getMontant(): ?int
    {
        return $this->montant;
    }

    public function getDroitMontant(): ?string
    {
        return $this->droitMontant;
    }

    /** Montant affiché seulement à qui a ce droit (ROLE_PAIEMENT_VOIR, ROLE_TRESORERIE_VOIR…). */
    public function setMontant(?int $montant, ?string $droit): static
    {
        $this->montant = $montant;
        $this->droitMontant = null !== $montant ? ($droit ?? 'ROLE_ADMIN') : null;

        return $this;
    }

    /**
     * Le titre tel que la personne le voit : « Acompte reçu · 30 000 F »
     * pour qui a le droit du montant, « Acompte reçu » pour les autres.
     *
     * @param list<string> $roles rôles de la personne qui lit
     */
    public function titrePour(array $roles): string
    {
        if (null === $this->montant || !self::peutVoirMontant($this->droitMontant, $roles)) {
            return $this->message;
        }

        return $this->message.' · '.number_format($this->montant, 0, ',', "\u{202F}").' F';
    }

    /** @param list<string> $roles */
    public static function peutVoirMontant(?string $droit, array $roles): bool
    {
        return null !== $droit && (\in_array(User::ROLE_ADMIN, $roles, true) || \in_array($droit, $roles, true));
    }

    public function getMiseAJourLe(): ?\DateTimeImmutable
    {
        return $this->miseAJourLe;
    }

    /** Dernière fois qu'elle a bougé (création ou regroupement). */
    public function getDerniereFois(): ?\DateTimeImmutable
    {
        return $this->miseAJourLe ?? $this->dateCreation;
    }

    public function getReporteeJusqua(): ?\DateTimeImmutable
    {
        return $this->reporteeJusqua;
    }

    public function reporter(?\DateTimeImmutable $jusqua): static
    {
        $this->reporteeJusqua = $jusqua;
        // Revenue d'un report : elle se remontre comme neuve (petite fenêtre, rappel).
        $this->alerteeLe = null;
        $this->lue = false;

        return $this;
    }

    public function estReportee(\DateTimeImmutable $maintenant): bool
    {
        return null !== $this->reporteeJusqua && $this->reporteeJusqua > $maintenant;
    }

    public function getResolueLe(): ?\DateTimeImmutable
    {
        return $this->resolueLe;
    }

    public function getResolution(): ?string
    {
        return $this->resolution;
    }

    public function resoudre(string $resolution, \DateTimeImmutable $le): static
    {
        if (null === $this->resolueLe) {
            $this->resolueLe = $le;
            $this->resolution = $resolution;
            $this->lue = true;
        }

        return $this;
    }

    public function rouvrir(): static
    {
        $this->resolueLe = null;
        $this->resolution = null;
        $this->lue = false;
        $this->alerteeLe = null;

        return $this;
    }

    public function estResolue(): bool
    {
        return null !== $this->resolueLe;
    }

    /**
     * Encore à montrer : pas résolue, et pas une info déjà lue (une info,
     * comme une ancienne notification, disparaît une fois lue).
     */
    public function estActive(): bool
    {
        return null === $this->resolueLe && !($this->lue && self::INFO === $this->niveau);
    }

    public function getAlerteeLe(): ?\DateTimeImmutable
    {
        return $this->alerteeLe;
    }

    public function marquerAlertee(\DateTimeImmutable $le): static
    {
        $this->alerteeLe = $le;

        return $this;
    }

    public function getRappeleLe(): ?\DateTimeImmutable
    {
        return $this->rappeleLe;
    }

    public function marquerRappelee(\DateTimeImmutable $le): static
    {
        $this->rappeleLe = $le;

        return $this;
    }

    public function getEscalade(): int
    {
        return $this->escalade;
    }

    public function getEscaladeeLe(): ?\DateTimeImmutable
    {
        return $this->escaladeeLe;
    }

    public function escalader(int $niveau, \DateTimeImmutable $le): static
    {
        $this->escalade = $niveau;
        $this->escaladeeLe = $le;

        return $this;
    }

    public function isSilencieuse(): bool
    {
        return $this->silencieuse;
    }

    public function setSilencieuse(bool $silencieuse): static
    {
        $this->silencieuse = $silencieuse;

        return $this;
    }
}
