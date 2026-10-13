<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement Orange Money lancé par le client depuis son espace (J5) :
 * jetons rendus par Orange, état, et le paiement « à valider » créé une
 * fois l'argent reçu. Tenu sur le site en ligne seulement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'transaction_orange_money')]
#[ORM\UniqueConstraint(name: 'uniq_transaction_om_order', columns: ['order_id'])]
class TransactionOrangeMoney
{
    public const INITIEE = 'initiee';
    public const REUSSIE = 'reussie';
    public const ECHOUEE = 'echouee';
    public const EXPIREE = 'expiree';

    /** Le lien de paiement d'Orange vaut 10 minutes. */
    public const VALIDITE_MINUTES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Commandes $commande;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteClient $compte;

    #[ORM\Column]
    private int $montant;

    /** Notre référence chez Orange (order_id), unique. */
    #[ORM\Column(length: 40)]
    private string $orderId;

    #[ORM\Column(length: 100)]
    private string $payToken = '';

    /** sha256 du notif_token : seul Orange le connaît, il authentifie sa notification. */
    #[ORM\Column(length: 64)]
    private string $notifEmpreinte = '';

    #[ORM\Column(type: 'text')]
    private string $lienPaiement = '';

    #[ORM\Column(length: 10)]
    private string $statut = self::INITIEE;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $txnid = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineeLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Paiements $paiement = null;

    public function __construct(Commandes $commande, ?CompteClient $compte, int $montant, string $orderId, \DateTimeImmutable $creeLe)
    {
        $this->commande = $commande;
        $this->compte = $compte;
        $this->montant = $montant;
        $this->orderId = $orderId;
        $this->creeLe = $creeLe;
    }

    public function ouvrir(string $payToken, string $notifToken, string $lien): void
    {
        $this->payToken = $payToken;
        $this->notifEmpreinte = hash('sha256', $notifToken);
        $this->lienPaiement = $lien;
    }

    public function notifTokenValide(string $notifToken): bool
    {
        return '' !== $this->notifEmpreinte && hash_equals($this->notifEmpreinte, hash('sha256', $notifToken));
    }

    /** Lien encore utilisable : le client peut y retourner au lieu d'en ouvrir un second. */
    public function estOuverte(\DateTimeImmutable $maintenant): bool
    {
        return self::INITIEE === $this->statut && '' !== $this->lienPaiement
            && $this->creeLe > $maintenant->modify(\sprintf('-%d minutes', self::VALIDITE_MINUTES - 1));
    }

    public function reussir(?string $txnid, Paiements $paiement, \DateTimeImmutable $le): void
    {
        $this->statut = self::REUSSIE;
        $this->txnid = null !== $txnid ? mb_substr($txnid, 0, 60) : null;
        $this->paiement = $paiement;
        $this->termineeLe = $le;
    }

    public function terminer(string $statut, \DateTimeImmutable $le): void
    {
        if (self::INITIEE === $this->statut && \in_array($statut, [self::ECHOUEE, self::EXPIREE], true)) {
            $this->statut = $statut;
            $this->termineeLe = $le;
        }
    }

    public function getId(): ?int { return $this->id; }
    public function getCommande(): Commandes { return $this->commande; }
    public function getCompte(): ?CompteClient { return $this->compte; }
    public function getMontant(): int { return $this->montant; }
    public function getOrderId(): string { return $this->orderId; }
    public function getPayToken(): string { return $this->payToken; }
    public function getLienPaiement(): string { return $this->lienPaiement; }
    public function getStatut(): string { return $this->statut; }
    public function getTxnid(): ?string { return $this->txnid; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getTermineeLe(): ?\DateTimeImmutable { return $this->termineeLe; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
}
