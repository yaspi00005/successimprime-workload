<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Service\Sondages\SondageCommande;
use Doctrine\ORM\Mapping as ORM;

/**
 * Invitation au sondage d'une commande, créée au local quand la commande
 * est livrée (InvitationsSondage). Son jeton ouvre le sondage sans compte
 * (/avis/c/…) : c'est le lien envoyé par SMS ou WhatsApp. Elle descend
 * sur le site ; les dates d'envoi restent au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invitation_sondage')]
#[ORM\UniqueConstraint(name: 'uniq_invitation_sondage_commande', columns: ['commande_id'])]
class InvitationSondage
{
    use PublicIdTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column(length: 24, unique: true)]
    private string $jeton;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** SMS parti (Orange SMS configuré et client d'accord). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $smsEnvoyeLe = null;

    /** L'agent a ouvert le lien WhatsApp préparé. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $whatsappLe = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->jeton = bin2hex(random_bytes(12));
    }

    public static function pour(Commandes $commande, \DateTimeImmutable $le): self
    {
        $i = new self();
        $i->commande = $commande;
        $i->client = $commande->getClients();
        $i->creeLe = $le;

        return $i;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function getJeton(): string
    {
        return $this->jeton;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function expireLe(): \DateTimeImmutable
    {
        return $this->creeLe->modify(\sprintf('+%d days', SondageCommande::JOURS_POUR_REPONDRE));
    }

    public function estValable(\DateTimeImmutable $maintenant): bool
    {
        return $maintenant < $this->expireLe();
    }

    public function getSmsEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->smsEnvoyeLe;
    }

    public function noterSms(\DateTimeImmutable $le): void
    {
        $this->smsEnvoyeLe = $le;
    }

    public function getWhatsappLe(): ?\DateTimeImmutable
    {
        return $this->whatsappLe;
    }

    public function noterWhatsapp(\DateTimeImmutable $le): void
    {
        $this->whatsappLe = $le;
    }
}
