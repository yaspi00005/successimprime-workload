<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\BonLivraisonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BonLivraisonRepository::class)]
#[ORM\Table(name: 'bon_livraison')]
class BonLivraison
{
    use PublicIdTrait;

    public const STATUT_BROUILLON = 'brouillon';
    public const STATUT_VALIDE = 'valide';
    public const STATUT_LIVRE = 'livre';
    public const STATUT_ANNULE = 'annule';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private ?string $numero = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: false,
        onDelete: 'CASCADE'
    )]
    private ?Commandes $commande = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $creeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $valideLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $livreLe = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $creePar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $validePar = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?User $livrePar = null;

    #[ORM\Column(length: 30)]
    private string $statut = self::STATUT_BROUILLON;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $nomReceptionnaire = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $telephoneReceptionnaire = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresseLivraison = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(
        nullable: true,
        onDelete: 'SET NULL'
    )]
    private ?Articles $articleStock = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $gestionStock = false;

    #[ORM\Column(
        type: Types::DECIMAL,
        precision: 10,
        scale: 3,
        options: ['default' => '1.000']
    )]
    private string $coefficientStock = '1.000';

    /**
     * @var Collection<int, BonLivraisonLigne>
     */
    /*
     * ============================================================
     * TOURNÉE ET REMISE (L01 à L03, docs/documents-livraisons.md)
     * ============================================================
     */

    /** Livreur prévu pour la tournée. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $livreur = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $prevuLe = null;

    /** Créneau donné au client, ex. « 14:00-16:00 ». */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $creneau = null;

    /** Rang dans la tournée du livreur. */
    #[ORM\Column(nullable: true)]
    private ?int $ordreTournee = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $chargeLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $enRouteLe = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $arriveLe = null;

    /** Photos de la remise (noms de fichiers rangés hors du dossier public). @var list<string> */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $photos = null;

    /** Signature du client (image PNG rangée hors du dossier public). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $signature = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $signataire = null;

    /** Encaissement saisi par le livreur : provisoire jusqu'à la validation à la caisse. */
    #[ORM\Column(nullable: true)]
    private ?int $encaisseMontant = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $encaisseMode = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $encaisseLe = null;

    /** Paiement créé quand la caisse valide l'encaissement. */
    #[ORM\ManyToOne(targetEntity: Paiements::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Paiements $paiementLivraison = null;

    /** Position GPS du téléphone au moment de la remise. */
    #[ORM\Column(nullable: true)]
    private ?float $remiseLatitude = null;

    #[ORM\Column(nullable: true)]
    private ?float $remiseLongitude = null;

    /** Retard justifié par le bureau (panne, client absent) : il ne coûte pas de points. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $retardJustifie = null;

    /** Colis abîmé ou erreur de livraison, noté par le bureau. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $erreurLivraison = null;

    #[ORM\OneToMany(
        targetEntity: BonLivraisonLigne::class,
        mappedBy: 'bonLivraison',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $lignes;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
        $this->lignes = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(string $numero): static
    {
        $this->numero = trim($numero);

        return $this;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getCreeLe(): ?\DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getValideLe(): ?\DateTimeImmutable
    {
        return $this->valideLe;
    }

    public function getLivreLe(): ?\DateTimeImmutable
    {
        return $this->livreLe;
    }

    public function getCreePar(): ?User
    {
        return $this->creePar;
    }

    public function setCreePar(?User $creePar): static
    {
        $this->creePar = $creePar;

        return $this;
    }

    public function getValidePar(): ?User
    {
        return $this->validePar;
    }

    public function getLivrePar(): ?User
    {
        return $this->livrePar;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function getNomReceptionnaire(): ?string
    {
        return $this->nomReceptionnaire;
    }

    public function setNomReceptionnaire(
        ?string $nomReceptionnaire
    ): static {
        $this->nomReceptionnaire =
            $this->nettoyer($nomReceptionnaire);

        return $this;
    }

    public function getTelephoneReceptionnaire(): ?string
    {
        return $this->telephoneReceptionnaire;
    }

    public function setTelephoneReceptionnaire(
        ?string $telephoneReceptionnaire
    ): static {
        $this->telephoneReceptionnaire =
            $this->nettoyer($telephoneReceptionnaire);

        return $this;
    }

    public function getAdresseLivraison(): ?string
    {
        return $this->adresseLivraison;
    }

    public function setAdresseLivraison(
        ?string $adresseLivraison
    ): static {
        $this->adresseLivraison =
            $this->nettoyer($adresseLivraison);

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(
        ?string $observation
    ): static {
        $this->observation =
            $this->nettoyer($observation);

        return $this;
    }

    /**
     * @return Collection<int, BonLivraisonLigne>
     */
    public function getLignes(): Collection
    {
        return $this->lignes;
    }

    public function addLigne(
        BonLivraisonLigne $ligne
    ): static {
        if (!$this->lignes->contains($ligne)) {
            $this->lignes->add($ligne);
            $ligne->setBonLivraison($this);
        }

        return $this;
    }

    public function removeLigne(
        BonLivraisonLigne $ligne
    ): static {
        if ($this->lignes->removeElement($ligne)) {
            if ($ligne->getBonLivraison() === $this) {
                $ligne->setBonLivraison(null);
            }
        }

        return $this;
    }

    public function valider(
        User $utilisateur
    ): static {
        if ($this->statut !== self::STATUT_BROUILLON) {
            throw new \LogicException(
                'Seul un bon de livraison en brouillon peut être validé.'
            );
        }

        if ($this->lignes->isEmpty()) {
            throw new \LogicException(
                'Le bon de livraison doit contenir au moins une ligne.'
            );
        }

        $this->statut = self::STATUT_VALIDE;
        $this->valideLe = new \DateTimeImmutable();
        $this->validePar = $utilisateur;

        return $this;
    }

    public function marquerLivre(
        User $utilisateur
    ): static {
        if ($this->statut !== self::STATUT_VALIDE) {
            throw new \LogicException(
                'Le bon de livraison doit être validé avant d’être livré.'
            );
        }

        if ($this->lignes->isEmpty()) {
            throw new \LogicException(
                'Le bon de livraison ne contient aucun article.'
            );
        }

        /*
     * On enregistre les quantités réellement livrées.
     */
        foreach ($this->lignes as $ligne) {
            $detail = $ligne->getCommandeDetail();

            if ($detail === null) {
                throw new \LogicException(
                    'Une ligne du bon n’est plus liée à son détail de commande.'
                );
            }

            $quantiteLivree =
                $ligne->getQuantiteLivree();

            $detail->enregistrerQuantiteLivree(
                $quantiteLivree
            );
        }

        $this->statut =
            self::STATUT_LIVRE;

        $this->livreLe =
            new \DateTimeImmutable();

        $this->livrePar =
            $utilisateur;

        return $this;
    }

    public function annuler(): static
    {
        if ($this->statut === self::STATUT_LIVRE) {
            throw new \LogicException(
                'Un bon de livraison déjà livré ne peut pas être annulé.'
            );
        }

        $this->statut = self::STATUT_ANNULE;

        return $this;
    }

    public function estBrouillon(): bool
    {
        return $this->statut === self::STATUT_BROUILLON;
    }

    public function estValide(): bool
    {
        return $this->statut === self::STATUT_VALIDE;
    }

    public function estLivre(): bool
    {
        return $this->statut === self::STATUT_LIVRE;
    }

    private function nettoyer(?string $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur !== '' ? $valeur : null;
    }

    public function __toString(): string
    {
        return $this->numero ?? 'Bon de livraison';
    }


    public function getCoefficientStock(): string
    {
        return $this->coefficientStock;
    }

    public function setCoefficientStock(
        string|int|float $coefficientStock
    ): static {
        $coefficient = (float) $coefficientStock;

        if ($coefficient <= 0) {
            throw new \InvalidArgumentException(
                'Le coefficient de consommation du stock doit être supérieur à zéro.'
            );
        }

        $this->coefficientStock = number_format(
            $coefficient,
            3,
            '.',
            ''
        );

        return $this;
    }

    /* ---------- Tournée et remise ---------- */

    public const ETAPES = ['prepare' => 'Préparée', 'charge' => 'Chargée', 'en_route' => 'En route', 'arrive' => 'Arrivée', 'remis' => 'Remise signée'];

    /** Encaissement : modes acceptés à la livraison. */
    public const MODES_ENCAISSEMENT = ['orange_money' => 'Orange Money', 'especes' => 'Espèces', 'credit' => 'Crédit client'];

    /** Heure de chaque étape franchie. @return array<string, ?\DateTimeImmutable> */
    public function etapes(): array
    {
        return [
            'prepare' => $this->valideLe,
            'charge' => $this->chargeLe,
            'en_route' => $this->enRouteLe,
            'arrive' => $this->arriveLe,
            'remis' => $this->livreLe,
        ];
    }

    /** Dernière étape franchie. */
    public function etape(): ?string
    {
        $derniere = null;
        foreach ($this->etapes() as $cle => $le) {
            if ($le !== null) {
                $derniere = $cle;
            }
        }

        return $derniere;
    }

    /** Franchit l'étape suivante (chargée, en route, arrivée) ; les étapes manquées prennent la même heure. */
    public function avancer(string $etape, \DateTimeImmutable $le): void
    {
        if ($this->statut !== self::STATUT_VALIDE) {
            throw new \LogicException('La livraison doit être validée (préparée) et pas encore remise.');
        }
        switch ($etape) {
            case 'arrive':
                $this->arriveLe ??= $le;
                // no break
            case 'en_route':
                $this->enRouteLe ??= $le;
                // no break
            case 'charge':
                $this->chargeLe ??= $le;
                break;
            default:
                throw new \InvalidArgumentException('Étape inconnue.');
        }
    }

    /** Partielle : au moins une ligne livrée en dessous de la commande. */
    public function estPartielle(): bool
    {
        foreach ($this->lignes as $l) {
            if ($this->statut === self::STATUT_LIVRE && $l->getQuantiteLivree() < $l->getQuantiteCommandee()) {
                return true;
            }
        }

        return false;
    }

    public function encaissementProvisoire(): bool
    {
        return $this->encaisseMontant !== null && $this->encaisseMontant > 0 && $this->paiementLivraison === null;
    }

    public function noterEncaissement(?int $montant, ?string $mode, \DateTimeImmutable $le): void
    {
        if ($this->paiementLivraison !== null) {
            throw new \LogicException('L\'encaissement est déjà validé à la caisse.');
        }
        if ($mode === null || !isset(self::MODES_ENCAISSEMENT[$mode])) {
            throw new \InvalidArgumentException('Mode d\'encaissement inconnu.');
        }
        $this->encaisseMode = $mode;
        $this->encaisseMontant = $mode === 'credit' ? null : max(0, (int) $montant);
        $this->encaisseLe = $le;
    }

    public function getLivreur(): ?User { return $this->livreur; }
    public function setLivreur(?User $livreur): static { $this->livreur = $livreur; return $this; }
    public function getPrevuLe(): ?\DateTimeImmutable { return $this->prevuLe; }
    public function setPrevuLe(?\DateTimeImmutable $le): static { $this->prevuLe = $le?->setTime(0, 0); return $this; }
    public function getCreneau(): ?string { return $this->creneau; }
    public function setCreneau(?string $creneau): static { $this->creneau = $creneau !== null && trim($creneau) !== '' ? mb_substr(trim($creneau), 0, 20) : null; return $this; }
    public function getOrdreTournee(): ?int { return $this->ordreTournee; }
    public function setOrdreTournee(?int $ordre): static { $this->ordreTournee = $ordre; return $this; }
    public function getChargeLe(): ?\DateTimeImmutable { return $this->chargeLe; }
    public function getEnRouteLe(): ?\DateTimeImmutable { return $this->enRouteLe; }
    public function getArriveLe(): ?\DateTimeImmutable { return $this->arriveLe; }
    /** @return list<string> */
    public function getPhotos(): array { return $this->photos ?? []; }
    public function ajouterPhoto(string $fichier): static { $this->photos = [...$this->getPhotos(), $fichier]; return $this; }
    public function getSignature(): ?string { return $this->signature; }
    public function signer(string $fichier, ?string $signataire): static { $this->signature = $fichier; $this->signataire = $signataire !== null && trim($signataire) !== '' ? mb_substr(trim($signataire), 0, 120) : null; return $this; }
    public function getSignataire(): ?string { return $this->signataire; }
    public function getEncaisseMontant(): ?int { return $this->encaisseMontant; }
    public function getEncaisseMode(): ?string { return $this->encaisseMode; }
    public function getEncaisseLe(): ?\DateTimeImmutable { return $this->encaisseLe; }
    public function getPaiementLivraison(): ?Paiements { return $this->paiementLivraison; }

    /** @return array{0: float, 1: float}|null */
    public function getRemisePosition(): ?array { return $this->remiseLatitude !== null && $this->remiseLongitude !== null ? [$this->remiseLatitude, $this->remiseLongitude] : null; }
    public function noterPosition(?float $latitude, ?float $longitude): static
    {
        $valide = $latitude !== null && $longitude !== null && abs($latitude) <= 90 && abs($longitude) <= 180 && ($latitude != 0.0 || $longitude != 0.0);
        $this->remiseLatitude = $valide ? round($latitude, 6) : null;
        $this->remiseLongitude = $valide ? round($longitude, 6) : null;

        return $this;
    }
    public function getRetardJustifie(): ?string { return $this->retardJustifie; }
    public function setRetardJustifie(?string $motif): static { $this->retardJustifie = $motif !== null && trim($motif) !== '' ? mb_substr(trim($motif), 0, 255) : null; return $this; }
    public function getErreurLivraison(): ?string { return $this->erreurLivraison; }
    public function setErreurLivraison(?string $motif): static { $this->erreurLivraison = $motif !== null && trim($motif) !== '' ? mb_substr(trim($motif), 0, 255) : null; return $this; }
    public function setPaiementLivraison(?Paiements $p): static { $this->paiementLivraison = $p; return $this; }
}
