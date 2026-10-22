<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Anniversaire d'un client (docs/promotions.md) : une ligne par client et
 * par nombre d'années, créée quand l'administrateur décide (valider, changer
 * le modèle, exclure) sur l'écran « Anniversaires à venir », ou le jour J
 * par la tâche app:promotions.
 *
 * L'unicité (client, années) rend la tâche sûre à relancer : le jour J,
 * une ligne déjà traitée (traiteLe) ne recrée ni promotion ni SMS ; le
 * rappel, 7 jours avant la fin, n'est envoyé qu'une fois (rappelLe).
 * Reste au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'promotion_anniversaire')]
#[ORM\UniqueConstraint(name: 'uniq_anniversaire_client_annees', columns: ['client_id', 'annees'])]
#[ORM\Index(name: 'idx_anniversaire_date', columns: ['date_anniversaire'])]
class AnniversaireClient
{
    public const VALIDE = 'valide';
    public const EXCLU = 'exclu';

    public const CANAL_SMS = 'sms';
    public const CANAL_WHATSAPP = 'whatsapp';
    /** Ni SMS ni lien : pas de numéro, pas d'accord, fiche de démonstration... */
    public const CANAL_AUCUN = 'aucun';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    #[ORM\Column]
    private int $annees = 1;

    /** Jour J (fiche créée ou première commande, plus N années). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $dateAnniversaire;

    /** null : pas encore décidé (le jour J, la tâche suit le réglage de la tuile). */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $decision = null;

    /** Modèle choisi à la main ; vide : celui des années du client. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ModeleAnniversaire $modele = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidePar = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decideLe = null;

    /** Promotion créée le jour J. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Promotion $promotion = null;

    /** Passage du jour J fait (promotion créée ou anniversaire écarté). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $traiteLe = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $canal = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $envoyeLe = null;

    /** Pourquoi rien n'est parti, ou l'erreur de l'envoi. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $erreur = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $rappelLe = null;

    public function __construct(Clients $client, int $annees, \DateTimeImmutable $dateAnniversaire)
    {
        $this->client = $client;
        $this->annees = max(1, $annees);
        $this->dateAnniversaire = $dateAnniversaire->setTime(0, 0);
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): ?Clients { return $this->client; }
    public function getAnnees(): int { return $this->annees; }
    public function getDateAnniversaire(): \DateTimeImmutable { return $this->dateAnniversaire; }
    public function getDecision(): ?string { return $this->decision; }
    public function estExclu(): bool { return self::EXCLU === $this->decision; }
    public function estValide(): bool { return self::VALIDE === $this->decision; }
    public function getModele(): ?ModeleAnniversaire { return $this->modele; }
    public function getDecidePar(): ?User { return $this->decidePar; }
    public function getDecideLe(): ?\DateTimeImmutable { return $this->decideLe; }
    public function getPromotion(): ?Promotion { return $this->promotion; }
    public function getTraiteLe(): ?\DateTimeImmutable { return $this->traiteLe; }
    public function estTraite(): bool { return null !== $this->traiteLe; }
    public function getCanal(): ?string { return $this->canal; }
    public function getEnvoyeLe(): ?\DateTimeImmutable { return $this->envoyeLe; }
    public function getErreur(): ?string { return $this->erreur; }
    public function getRappelLe(): ?\DateTimeImmutable { return $this->rappelLe; }

    /**
     * Décision de l'administrateur avant le jour J. Une fois traité,
     * l'anniversaire ne change plus.
     *
     * @throws \DomainException
     */
    public function decider(string $decision, ?ModeleAnniversaire $modele, ?User $par, \DateTimeImmutable $le): static
    {
        if ($this->estTraite()) {
            throw new \DomainException('Cet anniversaire est déjà passé : la promotion est faite.');
        }
        if (!\in_array($decision, [self::VALIDE, self::EXCLU], true)) {
            throw new \DomainException('Décision inconnue.');
        }
        $this->decision = $decision;
        if (self::VALIDE === $decision) {
            $this->modele = $modele;
        }
        $this->decidePar = $par;
        $this->decideLe = $le;

        return $this;
    }

    /**
     * Passage du jour J : promotion créée avec ce modèle (ou null si
     * l'anniversaire est écarté, $motif dit pourquoi).
     */
    public function marquerTraite(?Promotion $promotion, ?ModeleAnniversaire $modele, \DateTimeImmutable $le, ?string $motif = null): static
    {
        $this->promotion = $promotion;
        if (null !== $modele) {
            $this->modele = $modele;
        }
        $this->traiteLe = $le;
        $this->erreur = null !== $motif ? mb_substr($motif, 0, 255) : null;

        return $this;
    }

    public function marquerEnvoi(string $canal, ?\DateTimeImmutable $le, ?string $erreur = null): static
    {
        $this->canal = \in_array($canal, [self::CANAL_SMS, self::CANAL_WHATSAPP], true) ? $canal : self::CANAL_AUCUN;
        $this->envoyeLe = $le;
        $this->erreur = null !== $erreur ? mb_substr($erreur, 0, 255) : null;

        return $this;
    }

    public function marquerRappel(\DateTimeImmutable $le): static
    {
        $this->rappelLe = $le;

        return $this;
    }
}
