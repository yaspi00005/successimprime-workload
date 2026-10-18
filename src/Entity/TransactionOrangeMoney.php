<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Paiement Orange Money lancé par le client depuis son espace (J5) :
 * jetons rendus par Orange, état, et le paiement « à valider » créé une
 * fois l'argent reçu. Tenu sur le site en ligne seulement.
 *
 * Il paie une commande, l'acompte d'une demande de conception ou un pack
 * de crédit IA (docs/demande-conception.md) : pour ces deux-là, la
 * commande n'existe qu'une fois l'argent reçu. Il paie aussi un règlement
 * de plusieurs factures et acomptes (ReglementEnLigne, « Factures et
 * paiements ») : un paiement par commande à la réception de l'argent.
 *
 * Il fait aussi un dépôt sur le compte du client à la boutique (« Mon
 * solde », DepotSoldeClient) : aucun paiement n'est créé, le dépôt passe
 * « reçu » et la caisse le valide au local.
 *
 * Le montant est la part de la commande ; le fonds de soutien et le frais
 * de retrait (FraisOrangeMoney) sont payés en plus : Orange reçoit le
 * total, et les frais passent sur le paiement créé.
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

    public const OBJET_COMMANDE = 'commande';
    public const OBJET_ACOMPTE_CONCEPTION = 'acompte_conception';
    public const OBJET_CREDIT_IA = 'credit_ia';
    public const OBJET_REGLEMENT = 'reglement';
    public const OBJET_DEPOT = 'depot';

    /** Le lien de paiement d'Orange vaut 10 minutes. */
    public const VALIDITE_MINUTES = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Commandes $commande;

    #[ORM\Column(length: 20, options: ['default' => self::OBJET_COMMANDE])]
    private string $objet = self::OBJET_COMMANDE;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?DemandeConception $demandeConception = null;

    /** Client qui paie un acompte ou un pack (la commande vient après). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** Règlement de plusieurs factures et acomptes (« Factures et paiements »). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ReglementEnLigne $reglement = null;

    /** Dépôt sur le compte du client (« Mon solde »). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?DepotSoldeClient $depot = null;

    /** Pack de crédit IA : nombre d'images achetées. */
    #[ORM\Column(nullable: true)]
    private ?int $packImages = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CompteClient $compte;

    /** Part de la commande (acompte, pack), sans les frais. */
    #[ORM\Column]
    private int $montant;

    /** Frais calculés au départ (taux des réglages), payés en plus du montant. */
    #[ORM\Column(options: ['default' => 0])]
    private int $fondsSoutien = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $fraisRetrait = 0;

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

    public function __construct(?Commandes $commande, ?CompteClient $compte, int $montant, string $orderId, \DateTimeImmutable $creeLe)
    {
        $this->commande = $commande;
        $this->compte = $compte;
        $this->montant = $montant;
        $this->orderId = $orderId;
        $this->creeLe = $creeLe;
    }

    public static function pourAcompte(DemandeConception $demande, ?CompteClient $compte, int $montant, string $orderId, \DateTimeImmutable $creeLe): self
    {
        $t = new self(null, $compte, $montant, $orderId, $creeLe);
        $t->objet = self::OBJET_ACOMPTE_CONCEPTION;
        $t->demandeConception = $demande;
        $t->client = $demande->getClient();

        return $t;
    }

    public static function pourCreditIa(Clients $client, ?CompteClient $compte, int $images, int $montant, string $orderId, \DateTimeImmutable $creeLe): self
    {
        $t = new self(null, $compte, $montant, $orderId, $creeLe);
        $t->objet = self::OBJET_CREDIT_IA;
        $t->client = $client;
        $t->packImages = $images;

        return $t;
    }

    /** Règlement : la part Orange Money et ses frais sont ceux du règlement, calculés au départ. */
    public static function pourReglement(ReglementEnLigne $reglement, ?CompteClient $compte, \DateTimeImmutable $creeLe): self
    {
        $t = new self(null, $compte, $reglement->getMontantOm(), $reglement->getReference(), $creeLe);
        $t->objet = self::OBJET_REGLEMENT;
        $t->reglement = $reglement;
        $t->client = $reglement->getClient();

        return $t->avecFrais($reglement->getFondsSoutien(), $reglement->getFraisRetrait());
    }

    /** Dépôt sur le compte du client : le montant et les frais sont ceux du dépôt. */
    public static function pourDepot(DepotSoldeClient $depot, ?CompteClient $compte, \DateTimeImmutable $creeLe): self
    {
        $t = new self(null, $compte, $depot->getMontant(), $depot->getReference(), $creeLe);
        $t->objet = self::OBJET_DEPOT;
        $t->depot = $depot;
        $t->client = $depot->getClient();

        return $t->avecFrais($depot->getFondsSoutien(), $depot->getFraisRetrait());
    }

    /** Frais Orange Money du paiement, calculés par le serveur avant de partir chez Orange. */
    public function avecFrais(int $fondsSoutien, int $fraisRetrait): static
    {
        $this->fondsSoutien = max(0, $fondsSoutien);
        $this->fraisRetrait = max(0, $fraisRetrait);

        return $this;
    }

    /** Ce qu'Orange débite au client : le montant et les frais. */
    public function getTotal(): int
    {
        return $this->montant + $this->fondsSoutien + $this->fraisRetrait;
    }

    /** Commande créée à la réception de l'argent (acompte, pack de crédit). */
    public function rattacher(Commandes $commande): void
    {
        $this->commande ??= $commande;
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

    /** Dépôt reçu : aucun paiement, le dépôt attend la validation de la caisse. */
    public function reussirDepot(?string $txnid, \DateTimeImmutable $le): void
    {
        $this->statut = self::REUSSIE;
        $this->txnid = null !== $txnid ? mb_substr($txnid, 0, 60) : null;
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
    public function getCommande(): ?Commandes { return $this->commande; }
    public function getObjet(): string { return $this->objet; }
    public function getDemandeConception(): ?DemandeConception { return $this->demandeConception; }
    public function getReglement(): ?ReglementEnLigne { return $this->reglement; }
    public function getDepot(): ?DepotSoldeClient { return $this->depot; }
    public function getClient(): ?Clients { return $this->client ?? $this->commande?->getClients(); }
    public function getPackImages(): ?int { return $this->packImages; }
    public function getCompte(): ?CompteClient { return $this->compte; }
    public function getMontant(): int { return $this->montant; }
    public function getFondsSoutien(): int { return $this->fondsSoutien; }
    public function getFraisRetrait(): int { return $this->fraisRetrait; }
    public function getOrderId(): string { return $this->orderId; }
    public function getPayToken(): string { return $this->payToken; }
    public function getLienPaiement(): string { return $this->lienPaiement; }
    public function getStatut(): string { return $this->statut; }
    public function getTxnid(): ?string { return $this->txnid; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getTermineeLe(): ?\DateTimeImmutable { return $this->termineeLe; }
    public function getPaiement(): ?Paiements { return $this->paiement; }
}
