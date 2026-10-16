<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Règlement lancé par le client depuis « Factures et paiements » (maquette
 * FP03) : plusieurs factures et acomptes payés en une fois, par Orange
 * Money, par son avoir, ou les deux. Tenu sur le site en ligne seulement.
 *
 * Chaque part (ReglementEnLignePart) est l'argent d'une commande : quand
 * Orange confirme, une part devient un paiement « en attente » de cette
 * commande, et l'avoir utilisé un paiement « solde client » en attente.
 * La boutique les valide au local (Finances › À valider), jamais
 * automatiquement.
 *
 * Les montants sont ceux calculés par le serveur au départ (FacturesClient,
 * RepartitionPaiement) ; rien de la page n'est cru.
 */
#[ORM\Entity]
#[ORM\Table(name: 'reglement_en_ligne')]
#[ORM\UniqueConstraint(name: 'uniq_reglement_en_ligne_reference', columns: ['reference'])]
#[ORM\Index(name: 'idx_reglement_en_ligne_client', columns: ['client_id', 'statut'])]
class ReglementEnLigne
{
    /** Parti chez Orange Money, pas encore confirmé. */
    public const EN_COURS = 'en_cours';
    /** Argent reçu (ou avoir seul) : les paiements « en attente » sont créés. */
    public const RECU = 'recu';
    /** Refusé, expiré ou abandonné chez Orange : rien n'a été débité. */
    public const ABANDONNE = 'abandonne';

    /**
     * Pendant ce temps, l'avoir d'un règlement en cours reste réservé : le
     * lien d'Orange vaut 10 minutes, sa confirmation peut tarder un peu.
     */
    public const RESERVATION_MINUTES = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Référence montrée au client : l'order_id envoyé à Orange, ou « AV… » pour l'avoir seul. */
    #[ORM\Column(length: 40)]
    private string $reference;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteClient $compte;

    /** Part payée par Orange Money, sans les frais. */
    #[ORM\Column]
    private int $montantOm = 0;

    #[ORM\Column]
    private int $fondsSoutien = 0;

    #[ORM\Column]
    private int $fraisRetrait = 0;

    /** Avoir de la boutique utilisé. */
    #[ORM\Column]
    private int $avoir = 0;

    #[ORM\Column(length: 12)]
    private string $statut = self::EN_COURS;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $recuLe = null;

    /** @var Collection<int, ReglementEnLignePart> */
    #[ORM\OneToMany(mappedBy: 'reglement', targetEntity: ReglementEnLignePart::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['rang' => 'ASC'])]
    private Collection $parts;

    public function __construct(string $reference, Clients $client, ?CompteClient $compte, \DateTimeImmutable $creeLe)
    {
        $this->reference = mb_substr($reference, 0, 40);
        $this->client = $client;
        $this->compte = $compte;
        $this->creeLe = $creeLe;
        $this->parts = new ArrayCollection();
    }

    public function ajouterPart(ReglementEnLignePart $part): void
    {
        $this->parts->add($part);
        $this->montantOm += $part->getMontantOm();
        $this->avoir += $part->getAvoir();
        $this->fondsSoutien += $part->getFondsSoutien();
        $this->fraisRetrait += $part->getFraisRetrait();
    }

    public function marquerRecu(\DateTimeImmutable $le): void
    {
        if (self::EN_COURS === $this->statut) {
            $this->statut = self::RECU;
            $this->recuLe = $le;
        }
    }

    public function abandonner(): void
    {
        if (self::EN_COURS === $this->statut) {
            $this->statut = self::ABANDONNE;
        }
    }

    /** L'avoir qu'il utilise est encore réservé (en cours depuis peu). */
    public function reserveAvoir(\DateTimeImmutable $maintenant): bool
    {
        return self::EN_COURS === $this->statut && $this->avoir > 0
            && $this->creeLe > $maintenant->modify(\sprintf('-%d minutes', self::RESERVATION_MINUTES));
    }

    /** Ce qu'Orange débite au client : la part Orange Money et ses frais. */
    public function getTotalOm(): int
    {
        return $this->montantOm + $this->fondsSoutien + $this->fraisRetrait;
    }

    /** Ce qui va aux commandes : Orange Money et avoir, sans les frais. */
    public function getMontant(): int
    {
        return $this->montantOm + $this->avoir;
    }

    /**
     * Les mêmes éléments pour les mêmes montants : un second clic reprend
     * le règlement en cours au lieu d'en ouvrir un autre.
     */
    public function empreinte(): string
    {
        return implode('|', array_map(
            static fn (ReglementEnLignePart $p): string => \sprintf('%s:%d:%d', $p->getCommande()->getPublicId(), $p->getMontantOm(), $p->getAvoir()),
            $this->parts->toArray(),
        ));
    }

    public function getId(): ?int { return $this->id; }
    public function getReference(): string { return $this->reference; }
    public function getClient(): Clients { return $this->client; }
    public function getCompte(): ?CompteClient { return $this->compte; }
    public function getMontantOm(): int { return $this->montantOm; }
    public function getFondsSoutien(): int { return $this->fondsSoutien; }
    public function getFraisRetrait(): int { return $this->fraisRetrait; }
    public function getAvoir(): int { return $this->avoir; }
    public function getStatut(): string { return $this->statut; }
    public function estRecu(): bool { return self::RECU === $this->statut; }
    public function estEnCours(): bool { return self::EN_COURS === $this->statut; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getRecuLe(): ?\DateTimeImmutable { return $this->recuLe; }

    /** @return list<ReglementEnLignePart> */
    public function getParts(): array { return array_values($this->parts->toArray()); }
}
