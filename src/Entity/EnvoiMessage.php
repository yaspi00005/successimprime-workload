<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\EnvoiMessageRepository;
use App\Security\DestinationCode;
use Doctrine\ORM\Mapping as ORM;

/**
 * Historique des envois de SMS et d'e-mails (Clients › Crédits SMS et
 * e-mail, docs/messagerie-marketing.md) : une ligne par message tenté,
 * qu'il soit parti ou non.
 *
 * Jamais le texte du message : ni code de connexion, ni code PIN, ni lien
 * avec un jeton. Seulement le type (code de connexion, campagne…), l'objet
 * d'un e-mail, le destinataire, l'état et les unités SMS comptées.
 *
 * Écrite par App\Service\Messagerie\JournalEnvois, directement en base
 * (hors du gestionnaire d'entités de la requête) ; les lignes écrites sur
 * le site en ligne montent au local (synchronisation, sens ascendant).
 */
#[ORM\Entity(repositoryClass: EnvoiMessageRepository::class)]
#[ORM\Table(name: 'envoi_message')]
#[ORM\Index(name: 'idx_envoi_message_le', columns: ['envoye_le'])]
#[ORM\Index(name: 'idx_envoi_message_canal_le', columns: ['canal', 'envoye_le'])]
class EnvoiMessage
{
    use PublicIdTrait;

    public const SMS = 'sms';
    public const EMAIL = 'email';

    public const CANAUX = [
        self::SMS => 'SMS',
        self::EMAIL => 'E-mail',
    ];

    public const ENVOYE = 'envoye';
    public const ECHEC = 'echec';
    /** Rien n'est parti : clés Orange absentes, messagerie non réglée (développement, démonstration). */
    public const NON_ENVOYE = 'non_envoye';

    public const STATUTS = [
        self::ENVOYE => 'Envoyé',
        self::ECHEC => 'Échec',
        self::NON_ENVOYE => 'Non envoyé',
    ];

    /** Où le message est parti : l'ERP de l'imprimerie ou le site en ligne. */
    public const LOCAL = 'local';
    public const EN_LIGNE = 'en_ligne';

    public const ORIGINES = [
        self::LOCAL => 'ERP de l\'imprimerie',
        self::EN_LIGNE => 'Site en ligne',
    ];

    /** Sorte de message : ce que l'application envoyait, sans le texte. */
    public const TYPES = [
        'code_connexion' => 'Code de connexion',
        'code_securite' => 'Code de sécurité (personnel)',
        'notification_client' => 'Notification au client',
        'compte_entreprise' => 'Compte entreprise',
        'rappel_paiement' => 'Rappel de paiement',
        'relance_retrait' => 'Commande à retirer',
        'campagne' => 'Campagne',
        'anniversaire' => 'Anniversaire',
        'sondage' => 'Invitation à un sondage',
        'demande_site' => 'Accusé de demande du site',
        'conception' => 'Demande de conception',
        'devis' => 'Devis envoyé au client',
        'rapport_semaine' => 'Rapport de la semaine',
        'alerte_systeme' => 'Alerte système (personnel)',
        'alerte_equipe' => 'Alerte de l\'équipe (personnel)',
        'autre' => 'Autre',
    ];

    /**
     * Messages au personnel (code de sécurité de l'administration en ligne,
     * rapport de la semaine) : leur destinataire n'est pas un client. Son
     * adresse ou son numéro en entier, et la recherche dessus, sont pour la
     * direction seulement (le droit Clients ne suffit pas).
     */
    public const TYPES_PERSONNEL = ['code_securite', 'rapport_semaine', 'alerte_systeme', 'alerte_equipe'];

    public const LONGUEUR_SUJET = 190;
    public const LONGUEUR_ERREUR = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 5)]
    private string $canal = self::SMS;

    #[ORM\Column(length: 30)]
    private string $type = 'autre';

    /** Objet de l'e-mail (vide pour un SMS). */
    #[ORM\Column(length: 190, nullable: true)]
    private ?string $sujet = null;

    /** Numéro (chiffres, avec l'indicatif) ou adresse e-mail en minuscules. Affiché masqué sans le droit Clients. */
    #[ORM\Column(length: 190)]
    private string $destinataire = '';

    #[ORM\Column(length: 10)]
    private string $statut = self::ENVOYE;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $erreur = null;

    /** SMS : parties comptées (160 caractères, 70 avec des caractères spéciaux). E-mail : 1 par destinataire parti. */
    #[ORM\Column(options: ['default' => 0])]
    private int $unites = 0;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeLe;

    #[ORM\Column(length: 8, options: ['default' => self::LOCAL])]
    private string $origine = self::LOCAL;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->envoyeLe = new \DateTimeImmutable();
    }

    public static function nouveau(string $canal, string $type, string $destinataire, string $statut, ?string $erreur, int $unites, ?string $sujet, \DateTimeImmutable $le, string $origine = self::LOCAL): self
    {
        $e = new self();
        $e->canal = isset(self::CANAUX[$canal]) ? $canal : self::SMS;
        $e->type = isset(self::TYPES[$type]) ? $type : 'autre';
        $e->destinataire = mb_substr($destinataire, 0, 190);
        $e->statut = isset(self::STATUTS[$statut]) ? $statut : self::ECHEC;
        $e->erreur = null === $erreur || '' === trim($erreur) ? null : mb_substr(trim($erreur), 0, self::LONGUEUR_ERREUR);
        $e->unites = max(0, $unites);
        $e->sujet = null === $sujet || '' === trim($sujet) ? null : mb_substr(trim($sujet), 0, self::LONGUEUR_SUJET);
        $e->envoyeLe = $le;
        $e->origine = self::EN_LIGNE === $origine ? self::EN_LIGNE : self::LOCAL;

        return $e;
    }

    /** « +223 •• •• 42 12 », « a•••@gmail.com » : ce que voit qui n'a pas le droit Clients. */
    public static function masquer(string $canal, string $destinataire): string
    {
        return self::EMAIL === $canal ? DestinationCode::masquerEmail($destinataire) : DestinationCode::masquerTelephone($destinataire);
    }

    public function getId(): ?int { return $this->id; }
    public function getCanal(): string { return $this->canal; }
    public function getLibelleCanal(): string { return self::CANAUX[$this->canal] ?? $this->canal; }
    public function getType(): string { return $this->type; }
    public function getLibelleType(): string { return self::TYPES[$this->type] ?? $this->type; }
    public function getSujet(): ?string { return $this->sujet; }
    public function getDestinataire(): string { return $this->destinataire; }
    public function getDestinataireMasque(): string { return self::masquer($this->canal, $this->destinataire); }

    /** Destinataire complet, lisible (« +223 76 00 00 99 ») : seulement avec le droit Clients. */
    public function getDestinataireLisible(): string
    {
        if (self::EMAIL === $this->canal || '' === $this->destinataire) {
            return '' === $this->destinataire ? '—' : $this->destinataire;
        }
        if (11 === \strlen($this->destinataire) && str_starts_with($this->destinataire, '223')) {
            return '+223 '.implode(' ', str_split(substr($this->destinataire, 3), 2));
        }

        return '+'.$this->destinataire;
    }

    public function estPourLePersonnel(): bool { return \in_array($this->type, self::TYPES_PERSONNEL, true); }

    /**
     * Destinataire tel que cette personne peut le voir : en entier avec le
     * droit Clients (et pour un message au personnel, seulement la direction),
     * masqué sinon.
     */
    public function destinataireVisible(bool $voirClients, bool $voirPersonnel): string
    {
        return ($this->estPourLePersonnel() ? $voirPersonnel : $voirClients) ? $this->getDestinataireLisible() : $this->getDestinataireMasque();
    }

    public function getStatut(): string { return $this->statut; }
    public function getLibelleStatut(): string { return self::STATUTS[$this->statut] ?? $this->statut; }
    public function getErreur(): ?string { return $this->erreur; }
    public function getUnites(): int { return $this->unites; }
    public function getEnvoyeLe(): \DateTimeImmutable { return $this->envoyeLe; }
    public function getOrigine(): string { return $this->origine; }
    public function getLibelleOrigine(): string { return self::ORIGINES[$this->origine] ?? $this->origine; }
    public function estEnvoye(): bool { return self::ENVOYE === $this->statut; }
}
