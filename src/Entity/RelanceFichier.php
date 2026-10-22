<?php

namespace App\Entity;

use App\Service\Prepresse\EtapesRelance;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une relance pour des fichiers manquants (maquette PQ04,
 * docs/prepresse.md) : étape (J+1, J+3, J+7, J+15 ou à la main), canal,
 * message préparé, et qui l'a envoyée quand.
 *
 * WhatsApp n'a pas d'API ici : la relance est « préparée » (lien wa.me
 * prêt) puis « envoyée » quand quelqu'un ouvre le lien et le confirme.
 * Le message de l'espace client et la pause partent tout de suite ;
 * l'appel est une ligne de « Mes actions » de la caisse.
 */
#[ORM\Entity]
#[ORM\Table(name: 'relance_fichier')]
#[ORM\Index(name: 'idx_relance_fichier_commande', columns: ['commande_id', 'etape'])]
class RelanceFichier
{
    public const WHATSAPP = 'whatsapp';
    public const ESPACE = 'espace';
    public const APPEL = 'appel';
    public const PAUSE = 'pause';

    public const CANAUX = [
        self::WHATSAPP => 'WhatsApp',
        self::ESPACE => 'Espace client',
        self::APPEL => 'Appel par la caisse',
        self::PAUSE => 'Mise en pause',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Commandes $commande;

    #[ORM\Column(length: 10)]
    private string $etape;

    #[ORM\Column(length: 12)]
    private string $canal;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    /** Null : préparée par la tâche des relances. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $creePar = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $envoyePar = null;

    /** Formats manquants au moment de la relance. */
    #[ORM\Column(options: ['default' => 0])]
    private int $manquants = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    /** Lien wa.me prêt à ouvrir. */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $lien = null;

    /** Réponse du client notée par l'atelier (« je passe samedi »). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $reponse = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reponseLe = null;

    public function __construct(Commandes $commande, string $etape, string $canal, \DateTimeImmutable $creeLe, ?User $creePar = null)
    {
        if (!isset(EtapesRelance::LIBELLES[$etape]) || !isset(self::CANAUX[$canal])) {
            throw new \InvalidArgumentException('Étape ou canal de relance inconnu.');
        }
        $this->commande = $commande;
        $this->etape = $etape;
        $this->canal = $canal;
        $this->creeLe = $creeLe;
        $this->creePar = $creePar;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): Commandes
    {
        return $this->commande;
    }

    public function getEtape(): string
    {
        return $this->etape;
    }

    public function getLibelleEtape(): string
    {
        return EtapesRelance::LIBELLES[$this->etape] ?? $this->etape;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function getLibelleCanal(): string
    {
        return self::CANAUX[$this->canal] ?? $this->canal;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function estAutomatique(): bool
    {
        return null === $this->creePar;
    }

    public function getEnvoyeLe(): ?\DateTimeImmutable
    {
        return $this->envoyeLe;
    }

    public function getEnvoyePar(): ?User
    {
        return $this->envoyePar;
    }

    public function estEnvoyee(): bool
    {
        return null !== $this->envoyeLe;
    }

    public function envoyer(?User $par, \DateTimeImmutable $le): static
    {
        $this->envoyeLe = $le;
        $this->envoyePar = $par;

        return $this;
    }

    public function getManquants(): int
    {
        return $this->manquants;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getLien(): ?string
    {
        return $this->lien;
    }

    public function preparer(int $manquants, ?string $message, ?string $lien): static
    {
        $this->manquants = max(0, $manquants);
        $this->message = $message;
        $this->lien = null !== $lien ? mb_substr($lien, 0, 1000) : null;

        return $this;
    }

    public function getReponse(): ?string
    {
        return $this->reponse;
    }

    public function getReponseLe(): ?\DateTimeImmutable
    {
        return $this->reponseLe;
    }

    public function noterReponse(?string $reponse, \DateTimeImmutable $le): static
    {
        $reponse = null !== $reponse ? trim($reponse) : null;
        $this->reponse = '' !== (string) $reponse ? mb_substr((string) $reponse, 0, 255) : null;
        $this->reponseLe = null !== $this->reponse ? $le : null;

        return $this;
    }
}
