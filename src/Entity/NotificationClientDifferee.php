<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * SMS d'une notification arrivée pendant les heures calmes (21 h – 7 h) :
 * il part à 7 h, sauf s'il a alors plus de 12 heures
 * (NotificationsClient::FRAICHEUR_HEURES). Tenu sur le site en ligne.
 */
#[ORM\Entity]
#[ORM\Table(name: 'notification_client_differee')]
#[ORM\Index(name: 'idx_notification_differee_prevu', columns: ['envoye_le', 'prevu_le'])]
class NotificationClientDifferee
{
    public const ENVOYEE = 'envoyee';
    /** Trop ancienne à 7 h : elle reste seulement dans le fil. */
    public const PERIMEE = 'perimee';
    public const ECHEC = 'echec';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CompteClient $compte;

    /** Clé de l'événement (NotificationsClient::cle). */
    #[ORM\Column(length: 64)]
    private string $cle;

    #[ORM\Column(length: 20)]
    private string $type;

    /** sms (whatsapp quand l'atelier l'aura activé). */
    #[ORM\Column(length: 10)]
    private string $canal;

    /** Texte sans montant, déjà préparé. */
    #[ORM\Column(length: 300)]
    private string $message;

    /** Date de l'événement : la règle des 12 heures se compte depuis elle. */
    #[ORM\Column]
    private \DateTimeImmutable $evenementLe;

    #[ORM\Column]
    private \DateTimeImmutable $prevuLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $resultat = null;

    public function __construct(CompteClient $compte, string $cle, string $type, string $canal, string $message, \DateTimeImmutable $evenementLe, \DateTimeImmutable $prevuLe)
    {
        $this->compte = $compte;
        $this->cle = $cle;
        $this->type = mb_substr($type, 0, 20);
        $this->canal = $canal;
        $this->message = mb_substr($message, 0, 300);
        $this->evenementLe = $evenementLe;
        $this->prevuLe = $prevuLe;
    }

    public function terminer(string $resultat, \DateTimeImmutable $le): void
    {
        $this->resultat = $resultat;
        $this->envoyeLe = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getCompte(): CompteClient { return $this->compte; }
    public function getCle(): string { return $this->cle; }
    public function getType(): string { return $this->type; }
    public function getCanal(): string { return $this->canal; }
    public function getMessage(): string { return $this->message; }
    public function getEvenementLe(): \DateTimeImmutable { return $this->evenementLe; }
    public function getPrevuLe(): \DateTimeImmutable { return $this->prevuLe; }
    public function getEnvoyeLe(): ?\DateTimeImmutable { return $this->envoyeLe; }
    public function getResultat(): ?string { return $this->resultat; }
}
