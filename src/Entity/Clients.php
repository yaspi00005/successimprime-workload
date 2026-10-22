<?php

namespace App\Entity;

use App\Repository\ClientsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;



#[UniqueEntity(
    fields: ['telephone'],
    message: 'Un client avec ce numéro de téléphone existe déjà.',
    errorPath: 'telephone'
)]
#[ORM\Entity(repositoryClass: ClientsRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Clients
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank(message: 'Le code client est obligatoire.')]
    private ?string $code = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $raisonSociale = null;

    #[ORM\Column(length: 50, nullable: false)]
    private ?string $nom = null;

    #[ORM\Column(length: 50, nullable: false)]
    private ?string $prenom = null;

    
    #[ORM\Column(
        length: 30,
        unique: true
    )]
    #[Assert\NotBlank(
        message: 'Le numéro de téléphone est obligatoire.'
    )]
    #[Assert\Length(
        min: 8,
        max: 30,
        minMessage: 'Le numéro de téléphone doit contenir au moins {{ limit }} caractères.',
        maxMessage: 'Le numéro de téléphone ne peut pas dépasser {{ limit }} caractères.'
    )]
    private ?string $telephone = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $telephone2 = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(
        message: 'L’adresse email n’est pas valide.'
    )]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ville = null;

    /*
     * Position du client (livraison, pose, visite). A Bamako l'adresse
     * seule ne suffit pas : on garde aussi un repere en clair.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    #[Assert\Range(min: -90, max: 90)]
    private ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    #[Assert\Range(min: -180, max: 180)]
    private ?string $longitude = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $repere = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $localiseLe = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $nif = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $rccm = null;

    #[ORM\Column(
        length: 10,
        options: ['default' => 'B2C']
    )]
    #[Assert\Choice(
        choices: ['B2C', 'B2B'],
        message: 'Le type de client doit être B2C ou B2B.'
    )]
    private string $typeClient = 'B2C';

    /*
     * Grille à remise d'un client au prix standard (docs/produits.md) :
     * « partenaire » ou « association », remise en % réglée dans
     * Paramètres › Grille tarifaire. Sans effet pour un revendeur (B2B).
     */
    public const GRILLE_PARTENAIRE = 'partenaire';
    public const GRILLE_ASSOCIATION = 'association';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $grilleTarifaire = null;

    /*
     * ============================================================
     * TYPE DE COMPTE (ENTREPRISE / PARTICULIER)
     * ============================================================
     *
     * Indépendant de typeClient : typeClient sert uniquement à la
     * tarification (B2B/B2C), typeCompte détermine la nature du
     * compte (entreprise ou particulier) et donc si les champs
     * raison sociale/NIF/RCCM sont pertinents.
     * ============================================================
     */

    public const TYPE_COMPTE_PARTICULIER = 'particulier';

    public const TYPE_COMPTE_ENTREPRISE = 'entreprise';

    public const TYPE_COMPTE_ONG = 'ong';

    public const TYPE_COMPTE_ADMINISTRATION = 'administration';

    public const TYPES_COMPTE = [
        self::TYPE_COMPTE_PARTICULIER,
        self::TYPE_COMPTE_ENTREPRISE,
        self::TYPE_COMPTE_ONG,
        self::TYPE_COMPTE_ADMINISTRATION,
    ];

    public const TYPES_COMPTE_LABELS = [
        self::TYPE_COMPTE_ENTREPRISE => 'Entreprise',
        self::TYPE_COMPTE_ONG => 'ONG',
        self::TYPE_COMPTE_ADMINISTRATION => 'Administration',
        self::TYPE_COMPTE_PARTICULIER => 'Particulier',
    ];

    /** Délais de paiement proposés (maquette K02). */
    public const DELAIS_PAIEMENT = [
        'comptant' => 'Comptant',
        '15_jours' => '15 jours',
        '30_jours' => '30 jours',
        '30_jours_fdm' => '30 jours fin de mois',
        '45_jours' => '45 jours',
        '60_jours' => '60 jours',
    ];

    public const MOYENS_PAIEMENT = [
        'orange_money' => 'Orange Money',
        'especes' => 'Espèces',
        'virement' => 'Virement',
        'cheque' => 'Chèque',
        'wave' => 'Wave',
    ];

    #[ORM\Column(
        length: 20,
        options: ['default' => self::TYPE_COMPTE_PARTICULIER]
    )]
    #[Assert\Choice(
        choices: self::TYPES_COMPTE,
        message: 'Le type de compte doit être Entreprise, ONG, Administration ou Particulier.'
    )]
    private string $typeCompte = self::TYPE_COMPTE_PARTICULIER;

    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero(
        message: 'Le plafond de crédit ne peut pas être négatif.'
    )]
    private ?int $plafondCredit = 100000;

    /*
     * Argent du client resté chez nous (ex. monnaie non rendue
     * faute d'appoint). Alimenté manuellement par la caissière ;
     * peut être déduit automatiquement au moment d'un paiement.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $soldeCredit = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observation = null;

    #[ORM\Column]
    private ?\DateTime $createdAt = null;

    #[ORM\Column]
    private ?\DateTime $updatedAt = null;

    #[ORM\Column]
    private ?bool $statut = null;

    /*
     * Certains clients ne veulent pas recevoir de SMS (campagnes,
     * rappels) : ce champ est vérifié avant tout envoi de SMS,
     * contrairement à "statut" qui bloque tout (commandes, devis...).
     */
    #[ORM\Column(options: ['default' => true])]
    private bool $recevoirSms = true;

    /*
     * Date à laquelle le client a accepté de recevoir des offres
     * (campagnes marketing). Null : pas d'accord enregistré.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consentementMarketingLe = null;

    /**
     * @var Collection<int, Devis>
     */
    #[ORM\OneToMany(
        targetEntity: Devis::class,
        mappedBy: 'clients'
    )]
    private Collection $devis;

    #[ORM\Column(
        type: UuidType::NAME,
        unique: true
    )]
    private Uuid $publicId;

    /* ---------- Fiche commerciale (maquettes K01 à K06) ---------- */

    /** Commercial qui suit le client. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $suiviPar = null;

    /**
     * Prénom et nom de la personne qui suit le client, tenus avec suiviPar.
     * C'est tout ce qui part au site en ligne (carte « Votre conseiller »
     * de l'espace client) : rien d'autre de l'employé.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $conseiller = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $secteur = null;

    /** Comment le client nous a connus (mêmes valeurs que les prospects). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $origine = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $delaiPaiement = null;

    /** Remise habituelle en %. */
    #[ORM\Column(nullable: true)]
    private ?float $remiseHabituelle = null;

    /** Acompte demandé à la commande en %. */
    #[ORM\Column(nullable: true)]
    private ?int $acompte = null;

    /**
     * Client dispensé d'acompte (case de la fiche, réservée à la direction) :
     * il valide ses commandes sans rien payer d'avance, partout où un
     * acompte est demandé (panier, commande en ligne, devis, comptoir).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $sansAcompte = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $moyenPrefere = null;

    /** Code de l'entité qui facture (Entreprise::code). */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $entiteFacturation = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $commune = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $quartier = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $etiquettes = [];

    /** Besoin noté à la création (ajout terrain, maquette K05). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $besoin = null;

    /** Canaux d'envoi des factures (fiche client FC01, « Factures envoyées à »). */
    public const CANAUX_FACTURES = ['whatsapp' => 'WhatsApp', 'email' => 'e-mail', 'papier' => 'papier'];

    /**
     * Comment le client reçoit ses factures (clés de CANAUX_FACTURES). La
     * personne est le contact « Factures » de la fiche (sinon le principal).
     * Reste au local, comme les autres réglages internes de la fiche.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $facturesCanaux = [];

    /**
     * Charte du client (fiche client FC03) : couleurs (#RRGGBB) et une ligne
     * libre (logo, police, Pantone). Pour le prépresse ; reste au local.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $charteCouleurs = [];

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $charteTexte = null;

    /** @return list<string> */
    public function getFacturesCanaux(): array { return $this->facturesCanaux; }
    /** @param iterable<string> $canaux */
    public function setFacturesCanaux(iterable $canaux): static
    {
        // Toujours dans l'ordre de CANAUX_FACTURES (« WhatsApp + e-mail »).
        $choisis = \is_array($canaux) ? $canaux : iterator_to_array($canaux, false);
        $this->facturesCanaux = array_values(array_filter(array_keys(self::CANAUX_FACTURES), static fn (string $c): bool => \in_array($c, $choisis, true)));

        return $this;
    }
    /** « WhatsApp + e-mail » */
    public function getFacturesCanauxLabel(): ?string
    {
        return [] === $this->facturesCanaux ? null : implode(' + ', array_map(static fn (string $c): string => self::CANAUX_FACTURES[$c], $this->facturesCanaux));
    }

    /** @return list<string> */
    public function getCharteCouleurs(): array { return $this->charteCouleurs; }
    /**
     * Garde les couleurs #RGB ou #RRGGBB (6 au plus), en majuscules.
     *
     * @param iterable<string> $couleurs
     */
    public function setCharteCouleurs(iterable $couleurs): static
    {
        $liste = [];
        foreach ($couleurs as $c) {
            $c = strtoupper(trim((string) $c));
            if ('' !== $c && '#' !== $c[0]) {
                $c = '#'.$c;
            }
            if (preg_match('/^#[0-9A-F]{3}$/', $c)) {
                $c = '#'.$c[1].$c[1].$c[2].$c[2].$c[3].$c[3];
            }
            if (preg_match('/^#[0-9A-F]{6}$/', $c) && !\in_array($c, $liste, true)) {
                $liste[] = $c;
            }
        }
        $this->charteCouleurs = \array_slice($liste, 0, 6);

        return $this;
    }
    public function getCharteTexte(): ?string { return $this->charteTexte; }
    public function setCharteTexte(?string $v): static { $this->charteTexte = self::texteCourt($v, 255); return $this; }

    public function getSuiviPar(): ?User { return $this->suiviPar; }
    public function setSuiviPar(?User $u): static
    {
        $this->suiviPar = $u;
        $nom = null === $u ? '' : trim(($u->getEmploye()?->getPrenom() ?? '').' '.($u->getEmploye()?->getNom() ?? ''));
        $this->conseiller = '' === $nom ? null : mb_substr($nom, 0, 80);

        return $this;
    }
    public function getConseiller(): ?string { return $this->conseiller; }
    public function getSecteur(): ?string { return $this->secteur; }
    public function setSecteur(?string $v): static { $this->secteur = self::texteCourt($v, 80); return $this; }
    public function getOrigine(): ?string { return $this->origine; }
    public function setOrigine(?string $v): static { $this->origine = $v !== null && isset(Prospect::SOURCES_LIBELLES[$v]) ? $v : null; return $this; }
    public function getOrigineLabel(): ?string { return $this->origine !== null ? Prospect::SOURCES_LIBELLES[$this->origine] ?? $this->origine : null; }
    public function getDelaiPaiement(): ?string { return $this->delaiPaiement; }
    public function setDelaiPaiement(?string $v): static { $this->delaiPaiement = $v !== null && isset(self::DELAIS_PAIEMENT[$v]) ? $v : null; return $this; }
    public function getDelaiPaiementLabel(): ?string { return $this->delaiPaiement !== null ? self::DELAIS_PAIEMENT[$this->delaiPaiement] : null; }
    public function getRemiseHabituelle(): ?float { return $this->remiseHabituelle; }
    public function setRemiseHabituelle(?float $v): static { $this->remiseHabituelle = $v !== null && $v > 0 ? min(100.0, round($v, 2)) : null; return $this; }
    public function getAcompte(): ?int { return $this->acompte; }
    public function setAcompte(?int $v): static { $this->acompte = $v !== null && $v >= 0 ? min(100, $v) : null; return $this; }
    public function isSansAcompte(): bool { return $this->sansAcompte; }
    public function setSansAcompte(bool $v): static { $this->sansAcompte = $v; return $this; }
    public function getMoyenPrefere(): ?string { return $this->moyenPrefere; }
    public function setMoyenPrefere(?string $v): static { $this->moyenPrefere = $v !== null && isset(self::MOYENS_PAIEMENT[$v]) ? $v : null; return $this; }
    public function getMoyenPrefereLabel(): ?string { return $this->moyenPrefere !== null ? self::MOYENS_PAIEMENT[$this->moyenPrefere] : null; }
    public function getEntiteFacturation(): ?string { return $this->entiteFacturation; }
    public function setEntiteFacturation(?string $v): static { $this->entiteFacturation = self::texteCourt($v, 20); return $this; }
    public function getCommune(): ?string { return $this->commune; }
    public function setCommune(?string $v): static { $this->commune = self::texteCourt($v, 80); return $this; }
    public function getQuartier(): ?string { return $this->quartier; }
    public function setQuartier(?string $v): static { $this->quartier = self::texteCourt($v, 80); return $this; }
    /** @return list<string> */
    public function getEtiquettes(): array { return $this->etiquettes; }
    /** @param iterable<string> $e */
    public function setEtiquettes(iterable $e): static
    {
        $liste = [];
        foreach ($e as $t) {
            $t = self::texteCourt((string) $t, 30);
            if ($t !== null && !\in_array(mb_strtolower($t), array_map('mb_strtolower', $liste), true)) {
                $liste[] = $t;
            }
        }
        $this->etiquettes = \array_slice($liste, 0, 12);

        return $this;
    }
    public function getBesoin(): ?string { return $this->besoin; }
    public function setBesoin(?string $v): static { $this->besoin = $v !== null && trim($v) !== '' ? trim($v) : null; return $this; }

    /** Entreprise, ONG ou administration : raison sociale, NIF, RCCM. */
    public function isOrganisation(): bool
    {
        return $this->typeCompte !== self::TYPE_COMPTE_PARTICULIER;
    }

    private static function texteCourt(?string $v, int $max): ?string
    {
        $v = $v !== null ? trim($v) : '';

        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }


    public function __construct()
    {
        $this->devis = new ArrayCollection();
        $this->publicId = Uuid::v7();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getRaisonSociale(): ?string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(
        ?string $raisonSociale
    ): static {
        $this->raisonSociale = $this->normaliserValeur(
            $raisonSociale
        );

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $this->normaliserValeur($nom);

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(?string $prenom): static
    {
        $this->prenom = $this->normaliserValeur($prenom);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        if ($telephone === null) {
            $this->telephone = null;

            return $this;
        }

        $telephone = trim($telephone);

        // Conserve uniquement les chiffres et éventuellement le + initial.
        $telephone = preg_replace(
            '/(?!^\+)[^\d]/',
            '',
            $telephone
        );

        $this->telephone = $telephone;

        return $this;
    }

    public function getTelephone2(): ?string
    {
        return $this->telephone2;
    }

    public function setTelephone2(?string $telephone2): static
    {
        $this->telephone2 = $this->normaliserValeur(
            $telephone2
        );

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $email = $this->normaliserValeur($email);

        $this->email = $email !== null
            ? strtolower($email)
            : null;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $this->normaliserValeur($adresse);

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = $this->normaliserValeur($ville);

        return $this;
    }

    public function getLatitude(): ?float
    {
        return null === $this->latitude ? null : (float) $this->latitude;
    }

    public function getLongitude(): ?float
    {
        return null === $this->longitude ? null : (float) $this->longitude;
    }

    public function estLocalise(): bool
    {
        return null !== $this->latitude && null !== $this->longitude;
    }

    /**
     * Enregistre la position, ou l'efface si l'une des deux valeurs manque.
     */
    public function localiser(?float $latitude, ?float $longitude, ?\DateTimeImmutable $le = null): static
    {
        if (null === $latitude || null === $longitude) {
            $this->latitude = $this->longitude = null;
            $this->localiseLe = null;

            return $this;
        }

        $this->latitude = number_format($latitude, 7, '.', '');
        $this->longitude = number_format($longitude, 7, '.', '');
        $this->localiseLe = $le ?? new \DateTimeImmutable();

        return $this;
    }

    public function getRepere(): ?string
    {
        return $this->repere;
    }

    public function setRepere(?string $repere): static
    {
        $this->repere = $this->normaliserValeur($repere);

        return $this;
    }

    public function getLocaliseLe(): ?\DateTimeImmutable
    {
        return $this->localiseLe;
    }

    public function getNif(): ?string
    {
        return $this->nif;
    }

    public function setNif(?string $nif): static
    {
        $this->nif = $this->normaliserValeur($nif);

        return $this;
    }

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): static
    {
        $this->rccm = $this->normaliserValeur($rccm);

        return $this;
    }

    public function getTypeClient(): string
    {
        return $this->typeClient;
    }

    public function setTypeClient(string $typeClient): static
    {
        $typeClient = strtoupper(trim($typeClient));

        $this->typeClient = in_array(
            $typeClient,
            ['B2C', 'B2B'],
            true
        ) ? $typeClient : 'B2C';

        return $this;
    }

    public function isB2B(): bool
    {
        return $this->typeClient === 'B2B';
    }

    public function isB2C(): bool
    {
        return $this->typeClient === 'B2C';
    }

    public function getGrilleTarifaire(): ?string
    {
        return $this->grilleTarifaire;
    }

    public function setGrilleTarifaire(?string $grille): static
    {
        $grille = null !== $grille ? strtolower(trim($grille)) : null;
        $this->grilleTarifaire = \in_array($grille, [self::GRILLE_PARTENAIRE, self::GRILLE_ASSOCIATION], true) ? $grille : null;

        return $this;
    }

    /**
     * Grille de prix du client : B2B (Revendeur), PARTENAIRE, ASSOCIATION
     * ou B2C (Standard). Le revendeur l'emporte sur une grille à remise.
     */
    public function getGrille(): string
    {
        if ($this->isB2B()) {
            return 'B2B';
        }

        return null !== $this->grilleTarifaire ? strtoupper($this->grilleTarifaire) : 'B2C';
    }

    public function getTypeCompte(): string
    {
        return $this->typeCompte;
    }

    public function setTypeCompte(string $typeCompte): static
    {
        $typeCompte = strtolower(trim($typeCompte));

        $this->typeCompte = in_array($typeCompte, self::TYPES_COMPTE, true)
            ? $typeCompte
            : self::TYPE_COMPTE_PARTICULIER;

        return $this;
    }

    public function getTypeCompteLabel(): string
    {
        return self::TYPES_COMPTE_LABELS[$this->typeCompte] ?? $this->typeCompte;
    }

    public function isEntreprise(): bool
    {
        return $this->typeCompte === self::TYPE_COMPTE_ENTREPRISE;
    }

    public function isParticulier(): bool
    {
        return $this->typeCompte === self::TYPE_COMPTE_PARTICULIER;
    }

    public function getPlafondCredit(): ?int
    {
        return $this->plafondCredit;
    }

    public function setPlafondCredit(
        ?int $plafondCredit
    ): static {
        $this->plafondCredit = $plafondCredit;

        return $this;
    }

    public function getSoldeCredit(): int
    {
        return $this->soldeCredit;
    }

    public function setSoldeCredit(int $soldeCredit): static
    {
        $this->soldeCredit = max(0, $soldeCredit);

        return $this;
    }

    public function getObservation(): ?string
    {
        return $this->observation;
    }

    public function setObservation(
        ?string $observation
    ): static {
        $this->observation = $this->normaliserValeur(
            $observation
        );

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(
        \DateTime $createdAt
    ): static {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(
        \DateTime $updatedAt
    ): static {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PrePersist]
    public function initialiserDates(): void
    {
        $maintenant = new \DateTime();

        if ($this->createdAt === null) {
            $this->createdAt = $maintenant;
        }

        $this->updatedAt = $maintenant;
    }

    #[ORM\PreUpdate]
    public function actualiserDate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /*
     * De nombreux templates appellent client.nomComplet en
     * s'attendant à un accesseur public (avec repli sur client.nom
     * si absent). Comme cette méthode n'existait pas, Twig
     * résolvait toujours silencieusement vers le repli, et le
     * prénom n'apparaissait donc jamais nulle part (PDF, listes...).
     */
    public function getNomComplet(): string
    {
        if (
            $this->isOrganisation()
            && $this->raisonSociale !== null
        ) {
            return $this->raisonSociale;
        }

        $nomComplet = trim(sprintf(
            '%s %s',
            $this->prenom ?? '',
            $this->nom ?? ''
        ));

        return $nomComplet !== ''
            ? $nomComplet
            : ($this->code ?? 'Client');
    }

    public function __toString(): string
    {
        return $this->getNomComplet();
    }

    private function normaliserValeur(
        ?string $valeur
    ): ?string {
        if ($valeur === null) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }

    public function isStatut(): ?bool
    {
        return $this->statut;
    }

    public function setStatut(bool $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getConsentementMarketingLe(): ?\DateTimeImmutable
    {
        return $this->consentementMarketingLe;
    }

    public function isAccepteMarketing(): bool
    {
        return null !== $this->consentementMarketingLe;
    }

    /**
     * Cocher garde la date du premier accord ; décocher l'efface.
     */
    public function setAccepteMarketing(bool $accepte): static
    {
        if (!$accepte) {
            $this->consentementMarketingLe = null;
        } elseif (null === $this->consentementMarketingLe) {
            $this->consentementMarketingLe = new \DateTimeImmutable();
        }

        return $this;
    }

    public function isRecevoirSms(): bool
    {
        return $this->recevoirSms;
    }

    public function setRecevoirSms(bool $recevoirSms): static
    {
        $this->recevoirSms = $recevoirSms;

        return $this;
    }

    public function genererCodeDepuisId(): void
    {
        if ($this->id === null) {
            throw new \LogicException(
                'Le client doit être enregistré avant de générer son code.'
            );
        }

        $this->code = sprintf('CLI-%06d', $this->id);
    }

    /**
     * @return Collection<int, Devis>
     */
    public function getDevis(): Collection
    {
        return $this->devis;
    }

    public function addDevi(Devis $devi): static
    {
        if (!$this->devis->contains($devi)) {
            $this->devis->add($devi);
            $devi->setClients($this);
        }

        return $this;
    }

    public function removeDevi(Devis $devi): static
    {
        if (
            $this->devis->removeElement($devi)
            && $devi->getClients() === $this
        ) {
            /*
         * La relation est obligatoire dans Devis.
         * Le devis doit être associé à un autre client
         * avant son retrait définitif.
         */
        }

        return $this;
    }
    public function getPublicId(): Uuid
    {
        return $this->publicId;
    }
}
