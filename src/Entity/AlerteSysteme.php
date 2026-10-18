<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\AlerteSystemeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Alerte système (Réglages › Alertes système, docs/alertes-systeme.md) :
 * quelque chose a cassé sur le site public, l'espace client ou l'ERP
 * (erreur 500, paiement Orange Money, synchronisation, envoi d'e-mail ou
 * de SMS, sauvegarde, disque, file de messages, tâche planifiée).
 *
 * La même panne (même empreinte) tant qu'elle n'est pas résolue reste UNE
 * alerte dont le compteur monte. Écrite directement en base par
 * App\Service\Alertes\AlertesSysteme (hors du gestionnaire d'entités de la
 * requête, qui peut être cassé par l'erreur elle-même) ; née en ligne, elle
 * monte au local par la synchronisation (sens ascendant).
 *
 * Jamais de mot de passe ni de donnée de client dans le titre : le détail
 * technique est nettoyé (NettoyageAlerte) et ne se lit que sur l'écran.
 */
#[ORM\Entity(repositoryClass: AlerteSystemeRepository::class)]
#[ORM\Table(name: 'alerte_systeme')]
#[ORM\Index(name: 'idx_alerte_systeme_empreinte', columns: ['empreinte', 'etat'])]
#[ORM\Index(name: 'idx_alerte_systeme_etat', columns: ['etat', 'derniere_le'])]
#[ORM\Index(name: 'idx_alerte_systeme_prevenir', columns: ['a_prevenir'])]
#[ORM\HasLifecycleCallbacks]
class AlerteSysteme
{
    use PublicIdTrait;

    public const ERREUR_SERVEUR = 'erreur_serveur';
    public const PAIEMENT = 'paiement';
    public const SYNCHRO = 'synchro';
    public const ENVOI = 'envoi';
    public const SAUVEGARDE = 'sauvegarde';
    public const DISQUE = 'disque';
    public const FILE_MESSAGES = 'file_messages';
    public const TACHE = 'tache';
    public const JOURNAL = 'journal';
    public const ESSAI = 'essai';

    /** Sortes d'alertes, dans l'ordre de l'écran des réglages : libellé et aide. */
    public const TYPES = [
        self::ERREUR_SERVEUR => ['libelle' => 'Erreur du site (500)', 'aide' => 'une page du site, de l\'espace client ou de l\'administration plante'],
        self::PAIEMENT => ['libelle' => 'Paiement Orange Money', 'aide' => 'paiement non ouvert, rappel d\'Orange invalide, argent reçu sans commande'],
        self::SYNCHRO => ['libelle' => 'Synchronisation', 'aide' => 'pas de synchronisation réussie, ou l\'imprimerie ne s\'est pas manifestée'],
        self::ENVOI => ['libelle' => 'Envoi d\'e-mail ou de SMS', 'aide' => 'un e-mail ou un SMS n\'est pas parti'],
        self::SAUVEGARDE => ['libelle' => 'Sauvegarde', 'aide' => 'sauvegarde ratée, trop ancienne, ou copie sur le second disque en échec'],
        self::DISQUE => ['libelle' => 'Espace disque', 'aide' => 'moins de place que le seuil réglé'],
        self::FILE_MESSAGES => ['libelle' => 'File de messages', 'aide' => 'messages en échec ou bloqués dans la file d\'envoi'],
        self::TACHE => ['libelle' => 'Tâche planifiée', 'aide' => 'une commande lancée par le planificateur s\'est arrêtée sur une erreur'],
        self::JOURNAL => ['libelle' => 'Autre erreur notée', 'aide' => 'toute autre erreur écrite au journal du serveur'],
        self::ESSAI => ['libelle' => 'Alerte d\'essai', 'aide' => 'bouton « Envoyer une alerte d\'essai »'],
    ];

    public const CRITIQUE = 'critique';
    public const AVERTISSEMENT = 'avertissement';

    public const GRAVITES = [self::CRITIQUE => 'Critique', self::AVERTISSEMENT => 'À surveiller'];

    public const OUVERTE = 'ouverte';
    public const PRISE = 'prise';
    public const RESOLUE = 'resolue';

    public const ETATS = [self::OUVERTE => 'Ouverte', self::PRISE => 'Prise en charge', self::RESOLUE => 'Résolue'];

    public const LOCAL = 'local';
    public const EN_LIGNE = 'en_ligne';

    public const ORIGINES = [self::LOCAL => 'ERP de l\'imprimerie', self::EN_LIGNE => 'Site en ligne'];

    public const LONGUEUR_TITRE = 190;
    public const LONGUEUR_DETAIL = 12000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « AL-000042 » au local, « W-AL-000042 » en ligne : à donner au téléphone, dans l'e-mail. */
    #[ORM\Column(length: 20)]
    private string $reference = '';

    #[ORM\Column(length: 20)]
    private string $type = self::JOURNAL;

    #[ORM\Column(length: 15)]
    private string $gravite = self::CRITIQUE;

    #[ORM\Column(length: 190)]
    private string $titre = '';

    /** Détail technique nettoyé (classe, message, page, fichier, pile d'appels) : écran seulement, jamais dans l'e-mail. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detail = null;

    /** Même panne = même empreinte (sha1 de la sorte et de ce qui la reconnaît). */
    #[ORM\Column(length: 40)]
    private string $empreinte = '';

    #[ORM\Column(length: 8, options: ['default' => self::LOCAL])]
    private string $origine = self::LOCAL;

    #[ORM\Column(options: ['default' => 1])]
    private int $occurrences = 1;

    #[ORM\Column]
    private \DateTimeImmutable $premiereLe;

    #[ORM\Column]
    private \DateTimeImmutable $derniereLe;

    #[ORM\Column(length: 10, options: ['default' => self::OUVERTE])]
    private string $etat = self::OUVERTE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $prisePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $priseLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $resoluePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $resolueLe = null;

    /** Ce qui a été fait (texte de la personne), ou « Revenue à la normale toute seule ». */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $resolution = null;

    /** Personnes à prévenir (nouvelle alerte, ou rouverte) : l'envoi attend la fin du délai entre deux e-mails de la même sorte. */
    #[ORM\Column(options: ['default' => false])]
    private bool $aPrevenir = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $prevenueLe = null;

    /** Née en ligne : l'e-mail est déjà parti du serveur en ligne, le local ne le renvoie pas. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailEnvoyeLe = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->premiereLe = new \DateTimeImmutable();
        $this->derniereLe = $this->premiereLe;
    }

    /**
     * Compteur monté par la synchronisation sur une alerte déjà résolue au
     * local : la panne est revenue en ligne, l'alerte se rouvre.
     */
    #[ORM\PreUpdate]
    public function rouvrirSiRevenue(\Doctrine\ORM\Event\PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField('occurrences') && self::RESOLUE === $this->etat && $args->getNewValue('occurrences') > $args->getOldValue('occurrences')) {
            $this->rouvrir();
            $em = $args->getObjectManager();
            $em->getUnitOfWork()->recomputeSingleEntityChangeSet($em->getClassMetadata(self::class), $this);
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getType(): string { return $this->type; }
    public function getLibelleType(): string { return self::TYPES[$this->type]['libelle'] ?? $this->type; }
    public function getGravite(): string { return $this->gravite; }
    public function getLibelleGravite(): string { return self::GRAVITES[$this->gravite] ?? $this->gravite; }
    public function estCritique(): bool { return self::CRITIQUE === $this->gravite; }
    public function getTitre(): string { return $this->titre; }
    public function getDetail(): ?string { return $this->detail; }
    public function getEmpreinte(): string { return $this->empreinte; }
    public function getOrigine(): string { return $this->origine; }
    public function getLibelleOrigine(): string { return self::ORIGINES[$this->origine] ?? $this->origine; }
    public function estEnLigne(): bool { return self::EN_LIGNE === $this->origine; }
    public function getOccurrences(): int { return $this->occurrences; }
    public function getPremiereLe(): \DateTimeImmutable { return $this->premiereLe; }
    public function getDerniereLe(): \DateTimeImmutable { return $this->derniereLe; }
    public function getEtat(): string { return $this->etat; }
    public function getLibelleEtat(): string { return self::ETATS[$this->etat] ?? $this->etat; }
    public function estResolue(): bool { return self::RESOLUE === $this->etat; }
    public function getPrisePar(): ?User { return $this->prisePar; }
    public function getPriseLe(): ?\DateTimeImmutable { return $this->priseLe; }
    public function getResoluePar(): ?User { return $this->resoluePar; }
    public function getResolueLe(): ?\DateTimeImmutable { return $this->resolueLe; }
    public function getResolution(): ?string { return $this->resolution; }
    public function isAPrevenir(): bool { return $this->aPrevenir; }
    public function getPrevenueLe(): ?\DateTimeImmutable { return $this->prevenueLe; }
    public function getEmailEnvoyeLe(): ?\DateTimeImmutable { return $this->emailEnvoyeLe; }

    /** « Je prends » : la personne s'en occupe (une autre peut reprendre la main). */
    public function prendre(User $par, \DateTimeImmutable $le): self
    {
        $this->etat = self::PRISE;
        $this->prisePar = $par;
        $this->priseLe = $le;
        $this->resoluePar = null;
        $this->resolueLe = null;

        return $this;
    }

    public function resoudre(?User $par, string $resolution, \DateTimeImmutable $le): self
    {
        $this->etat = self::RESOLUE;
        $this->resoluePar = $par;
        $this->resolueLe = $le;
        $this->resolution = '' === trim($resolution) ? null : mb_substr(trim($resolution), 0, 2000);
        // Résolue avant d'avoir été envoyée (pendant le délai entre deux e-mails) : plus personne à prévenir.
        $this->aPrevenir = false;

        return $this;
    }

    public function rouvrir(): self
    {
        $this->etat = self::OUVERTE;
        $this->prisePar = null;
        $this->priseLe = null;
        $this->resoluePar = null;
        $this->resolueLe = null;
        $this->aPrevenir = true;

        return $this;
    }

    public function marquerPrevenue(\DateTimeImmutable $le, bool $emailParti): self
    {
        $this->aPrevenir = false;
        $this->prevenueLe = $le;
        if ($emailParti && null === $this->emailEnvoyeLe) {
            $this->emailEnvoyeLe = $le;
        }

        return $this;
    }
}
