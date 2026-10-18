<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\ProduitClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Produit d'un client (« Mes produits », docs/mes-produits.md). Créé par
 * l'atelier (format, BAT, prix négocié) ou par le client lui-même depuis
 * son espace (image, nom, type, dimensions : maquette MP01). Le prépresse
 * y lie le fichier traité du dossier de l'atelier, une fois par taille
 * validée (MP02) : le produit est alors « Prêt à imprimer » et une commande
 * à une taille validée part directement en impression (MP06).
 */
#[ORM\Entity(repositoryClass: ProduitClientRepository::class)]
#[ORM\Index(columns: ['client_id', 'actif'])]
class ProduitClient
{
    use PublicIdTrait;

    public const UNITE_PIECE = 'piece';
    public const UNITE_M2 = 'm2';
    public const UNITE_PIECE_OU_M2 = 'piece_ou_m2';
    public const UNITE_LOT = 'lot';

    public const ORIGINE_ATELIER = 'atelier';
    public const ORIGINE_CLIENT = 'client';

    /** Lien du fichier traité (MP02) : à préparer, lié, suspendu (nouvelle image du client). */
    public const LIEN_A_PREPARER = 'a_preparer';
    public const LIEN_LIE = 'lie';
    public const LIEN_SUSPENDU = 'suspendu';

    /**
     * Types proposés au client quand il ajoute ses produits (MP01), avec la
     * famille du filtre de « Mes produits » (MP03).
     */
    public const TYPES = [
        'Étiquette adhésive' => 'Étiquettes',
        'Étiquette carton' => 'Étiquettes',
        'Étiquette satin' => 'Étiquettes',
        'Étiquette tissée' => 'Étiquettes',
        'Sticker' => 'Étiquettes',
        'Carte' => 'Cartes',
        'Carte de visite' => 'Cartes',
        'Flyer' => 'Imprimés',
        'Affiche' => 'Imprimés',
        'Emballage' => 'Imprimés',
        'Bâche' => 'Grand format',
        'Autocollant vitrine' => 'Grand format',
        'Roll-up' => 'Grand format',
        'Autre' => 'Autres',
    ];

    /** Écart toléré entre deux tailles, en mm. */
    public const TOLERANCE_MM = 0.5;

    public const UNITES_LABELS = [
        self::UNITE_PIECE => 'À la pièce',
        self::UNITE_M2 => 'Au m²',
        self::UNITE_PIECE_OU_M2 => 'Pièce ou m², au choix',
        self::UNITE_LOT => 'Par lot',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Clients $client = null;

    /*
     * Reference lisible, ex. PC-AWA-01, reprise sur la ligne de commande.
     */
    #[ORM\Column(length: 30, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $nom = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Produits $produit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Supports $support = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Machines $machine = null;

    /*
     * Format en metres, comme les lignes de commande. Une hauteur vide signifie « longueur libre » (vinyle,
     * bache au metre) : la largeur est alors celle du rouleau.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $largeur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4, nullable: true)]
    private ?string $hauteur = null;

    /**
     * @var Collection<int, Finition>
     */
    #[ORM\ManyToMany(targetEntity: Finition::class)]
    #[ORM\JoinTable(name: 'produit_client_finition')]
    private Collection $finitions;

    #[ORM\Column(length: 20, options: ['default' => self::UNITE_PIECE])]
    #[Assert\Choice(choices: [self::UNITE_PIECE, self::UNITE_M2, self::UNITE_PIECE_OU_M2, self::UNITE_LOT])]
    private string $uniteVente = self::UNITE_PIECE;

    /*
     * Prix negocie TTC par unite de vente : la piece, le m2 ou le lot.
     */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $prixUnitaire = 0;

    #[ORM\Column(options: ['default' => 1])]
    #[Assert\Positive]
    private int $quantiteMinimum = 1;

    #[ORM\Column(nullable: true)]
    private ?int $tailleLot = null;

    #[ORM\Column(nullable: true)]
    private ?int $posesParFeuille = null;

    /*
     * En m2 sur un format fixe : true = toujours le nombre de pieces
     * superieur ; false = le client choisit entre superieur et inferieur.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $arrondiSuperieurImpose = false;

    /*
     * Fichier et BAT valides, figes : une commande de ce produit part en
     * production sans repasser par la PAO.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $fichier = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?ControlePrePresse $batValide = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CommandesDetails $ligneOrigine = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?self $remplace = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    #[ORM\Column(length: 10, options: ['default' => self::ORIGINE_ATELIER])]
    private string $origine = self::ORIGINE_ATELIER;

    /** Type donné par le client (TYPES), ou par l'atelier. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $type = null;

    /** Forme ronde : largeur = hauteur = diamètre (« Ø 60 mm »). */
    #[ORM\Column(options: ['default' => false])]
    private bool $rond = false;

    /*
     * Image envoyée par le client (MP01) : le visuel de la carte et la base
     * du fichier que le prépresse prépare.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $image = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imageRemplaceeLe = null;

    #[ORM\Column(length: 12, options: ['default' => self::LIEN_A_PREPARER])]
    private string $etatLien = self::LIEN_A_PREPARER;

    /*
     * Fichiers traités liés, un par taille validée (MP02). Seulement la
     * référence : nom relatif au dossier « Produits » du client, empreinte
     * SHA-256, poids, taille en mm, date ; au local aussi le contrôle et qui
     * a lié. Le fichier ne quitte jamais le serveur de l'atelier.
     *
     * @var list<array{l: float, h: float, nom: string, empreinte: string, octets: int, le: string, par?: ?string, controles?: list<array{libelle: string, valeur: string, etat: string}>}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fichiersLies = null;

    /*
     * Prix par quantité (MP04) : [{q: 500, prix: 40}] = 40 F l'unité à
     * partir de 500. Vide : le prix unitaire pour toute quantité.
     *
     * @var list<array{q: int, prix: int}>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $paliers = null;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct()
    {
        $this->initialiserPublicId();
        $this->finitions = new ArrayCollection();
        $this->creeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function getProduit(): ?Produits
    {
        return $this->produit;
    }

    public function setProduit(?Produits $produit): static
    {
        $this->produit = $produit;

        return $this;
    }

    public function getSupport(): ?Supports
    {
        return $this->support;
    }

    public function setSupport(?Supports $support): static
    {
        $this->support = $support;

        return $this;
    }

    public function getMachine(): ?Machines
    {
        return $this->machine;
    }

    public function setMachine(?Machines $machine): static
    {
        $this->machine = $machine;

        return $this;
    }

    public function getLargeur(): ?float
    {
        return null === $this->largeur ? null : (float) $this->largeur;
    }

    public function getHauteur(): ?float
    {
        return null === $this->hauteur ? null : (float) $this->hauteur;
    }

    /**
     * Dimensions en metres ; une hauteur nulle = longueur libre sur rouleau.
     */
    public function setFormat(?float $largeur, ?float $hauteur): static
    {
        $this->largeur = null === $largeur ? null : number_format($largeur, 4, '.', '');
        $this->hauteur = null === $hauteur ? null : number_format($hauteur, 4, '.', '');

        return $this;
    }

    public function estFormatFixe(): bool
    {
        return null !== $this->largeur && null !== $this->hauteur;
    }

    public function estLongueurLibre(): bool
    {
        return null !== $this->largeur && null === $this->hauteur;
    }

    /**
     * @return Collection<int, Finition>
     */
    public function getFinitions(): Collection
    {
        return $this->finitions;
    }

    public function addFinition(Finition $finition): static
    {
        if (!$this->finitions->contains($finition)) {
            $this->finitions->add($finition);
        }

        return $this;
    }

    public function removeFinition(Finition $finition): static
    {
        $this->finitions->removeElement($finition);

        return $this;
    }

    public function getUniteVente(): string
    {
        return $this->uniteVente;
    }

    public function setUniteVente(string $uniteVente): static
    {
        $this->uniteVente = $uniteVente;

        return $this;
    }

    public function accepteLesPieces(): bool
    {
        return \in_array($this->uniteVente, [self::UNITE_PIECE, self::UNITE_PIECE_OU_M2], true);
    }

    public function accepteLesM2(): bool
    {
        return \in_array($this->uniteVente, [self::UNITE_M2, self::UNITE_PIECE_OU_M2], true);
    }

    public function getPrixUnitaire(): int
    {
        return $this->prixUnitaire;
    }

    public function setPrixUnitaire(int $prixUnitaire): static
    {
        $this->prixUnitaire = max(0, $prixUnitaire);

        return $this;
    }

    public function getQuantiteMinimum(): int
    {
        return $this->quantiteMinimum;
    }

    public function setQuantiteMinimum(int $quantiteMinimum): static
    {
        $this->quantiteMinimum = max(1, $quantiteMinimum);

        return $this;
    }

    public function getTailleLot(): ?int
    {
        return $this->tailleLot;
    }

    public function setTailleLot(?int $tailleLot): static
    {
        $this->tailleLot = $tailleLot;

        return $this;
    }

    public function getPosesParFeuille(): ?int
    {
        return $this->posesParFeuille;
    }

    public function setPosesParFeuille(?int $posesParFeuille): static
    {
        $this->posesParFeuille = $posesParFeuille;

        return $this;
    }

    public function isArrondiSuperieurImpose(): bool
    {
        return $this->arrondiSuperieurImpose;
    }

    public function setArrondiSuperieurImpose(bool $arrondiSuperieurImpose): static
    {
        $this->arrondiSuperieurImpose = $arrondiSuperieurImpose;

        return $this;
    }

    public function getFichier(): ?CommandeDetailFichier
    {
        return $this->fichier;
    }

    public function setFichier(?CommandeDetailFichier $fichier): static
    {
        $this->fichier = $fichier;

        return $this;
    }

    public function getBatValide(): ?ControlePrePresse
    {
        return $this->batValide;
    }

    public function setBatValide(?ControlePrePresse $batValide): static
    {
        $this->batValide = $batValide;

        return $this;
    }

    public function getLigneOrigine(): ?CommandesDetails
    {
        return $this->ligneOrigine;
    }

    public function setLigneOrigine(?CommandesDetails $ligneOrigine): static
    {
        $this->ligneOrigine = $ligneOrigine;

        return $this;
    }

    public function getRemplace(): ?self
    {
        return $this->remplace;
    }

    public function setRemplace(?self $remplace): static
    {
        $this->remplace = $remplace;

        return $this;
    }

    public function isActif(): bool
    {
        return $this->actif;
    }

    public function setActif(bool $actif): static
    {
        $this->actif = $actif;

        return $this;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getOrigine(): string
    {
        return $this->origine;
    }

    public function setOrigine(string $origine): static
    {
        $this->origine = self::ORIGINE_CLIENT === $origine ? self::ORIGINE_CLIENT : self::ORIGINE_ATELIER;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $type = null === $type ? null : trim($type);
        $this->type = '' === $type || null === $type ? null : mb_substr($type, 0, 60);

        return $this;
    }

    /** Famille du filtre de « Mes produits » : celle du type, sinon la catégorie du catalogue. */
    public function getFamille(): ?string
    {
        return self::TYPES[$this->type ?? ''] ?? $this->produit?->getCategorieProduit()?->getNom();
    }

    public function isRond(): bool
    {
        return $this->rond;
    }

    public function setRond(bool $rond): static
    {
        $this->rond = $rond;

        return $this;
    }

    public function getImage(): ?CommandeDetailFichier
    {
        return $this->image;
    }

    public function setImage(?CommandeDetailFichier $image): static
    {
        $this->image = $image;

        return $this;
    }

    /**
     * Le client remplace l'image (règle « Nouvelle image ») : le lien est
     * suspendu et le produit repasse au prépresse.
     */
    public function remplacerImage(CommandeDetailFichier $image, \DateTimeImmutable $maintenant): static
    {
        $this->image = $image;
        $this->imageRemplaceeLe = $maintenant;
        if (self::LIEN_LIE === $this->etatLien) {
            $this->etatLien = self::LIEN_SUSPENDU;
        }

        return $this;
    }

    public function getImageRemplaceeLe(): ?\DateTimeImmutable
    {
        return $this->imageRemplaceeLe;
    }

    public function getEtatLien(): string
    {
        return $this->etatLien;
    }

    public function setEtatLien(string $etat): static
    {
        $this->etatLien = \in_array($etat, [self::LIEN_A_PREPARER, self::LIEN_LIE, self::LIEN_SUSPENDU], true) ? $etat : self::LIEN_A_PREPARER;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getFichiersLies(): array
    {
        return array_values(array_filter($this->fichiersLies ?? [], 'is_array'));
    }

    /** @param list<array<string, mixed>> $fichiers */
    public function setFichiersLies(array $fichiers): static
    {
        $this->fichiersLies = [] === $fichiers ? null : array_values($fichiers);

        return $this;
    }

    /**
     * Lie (ou remplace) le fichier d'une taille. Le produit devient « lié ».
     *
     * @param array{l: float, h: float, nom: string, empreinte: string, octets: int, le: string, par?: ?string, controles?: list<array<string, string>>} $fichier
     */
    public function lierFichier(array $fichier): static
    {
        // Après une nouvelle image, les anciens fichiers ne valent plus : seul le nouveau lien compte.
        $anciens = self::LIEN_SUSPENDU === $this->etatLien ? [] : $this->getFichiersLies();
        $liste = array_values(array_filter($anciens, fn (array $f): bool => !self::memeTaille((float) $f['l'], (float) $f['h'], (float) $fichier['l'], (float) $fichier['h'])));
        $liste[] = $fichier;
        usort($liste, static fn (array $a, array $b): int => [$a['l'] * $a['h'], $a['l']] <=> [$b['l'] * $b['h'], $b['l']]);
        $this->fichiersLies = $liste;
        $this->etatLien = self::LIEN_LIE;

        return $this;
    }

    public function delierTaille(float $lMm, float $hMm): static
    {
        $this->setFichiersLies(array_values(array_filter($this->getFichiersLies(), fn (array $f): bool => !self::memeTaille((float) $f['l'], (float) $f['h'], $lMm, $hMm))));
        if ([] === $this->getFichiersLies() && self::LIEN_LIE === $this->etatLien) {
            $this->etatLien = self::LIEN_A_PREPARER;
        }

        return $this;
    }

    /** Le fichier lié pour cette taille (mm), s'il y en a un et que le lien n'est pas suspendu. */
    public function fichierPourTaille(float $lMm, float $hMm): ?array
    {
        if (self::LIEN_LIE !== $this->etatLien) {
            return null;
        }
        foreach ($this->getFichiersLies() as $f) {
            if (self::memeTaille((float) $f['l'], (float) $f['h'], $lMm, $hMm)) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Prêt à imprimer (MP03) : un fichier traité lié, ou (produit enregistré
     * par l'atelier avant les maquettes du 18/10) un BAT validé.
     */
    public function estPret(): bool
    {
        if (self::LIEN_SUSPENDU === $this->etatLien) {
            return false;
        }

        return (self::LIEN_LIE === $this->etatLien && [] !== $this->getFichiersLies()) || null !== $this->batValide;
    }

    /**
     * Tailles validées pour une commande directe, en mm : celles des fichiers
     * liés, et le format du produit quand il a un BAT validé.
     *
     * @return list<array{l: float, h: float}>
     */
    public function taillesValidees(): array
    {
        $tailles = [];
        if (self::LIEN_LIE === $this->etatLien) {
            foreach ($this->getFichiersLies() as $f) {
                $tailles[] = ['l' => (float) $f['l'], 'h' => (float) $f['h']];
            }
        }
        if (null !== $this->batValide && self::LIEN_SUSPENDU !== $this->etatLien && $this->estFormatFixe() && null === $this->tailleDans($tailles, $this->getLargeurMm(), $this->getHauteurMm())) {
            $tailles[] = ['l' => $this->getLargeurMm(), 'h' => $this->getHauteurMm()];
        }

        return $tailles;
    }

    public function estTailleValidee(float $lMm, float $hMm): bool
    {
        return null !== $this->tailleDans($this->taillesValidees(), $lMm, $hMm);
    }

    public function getLargeurMm(): ?float
    {
        return null === $this->largeur ? null : round((float) $this->largeur * 1000, 1);
    }

    public function getHauteurMm(): ?float
    {
        return null === $this->hauteur ? null : round((float) $this->hauteur * 1000, 1);
    }

    /** @return list<array{q: int, prix: int}> du plus petit palier au plus grand */
    public function getPaliers(): array
    {
        $paliers = array_values(array_filter($this->paliers ?? [], static fn ($p): bool => \is_array($p) && (int) ($p['q'] ?? 0) > 0 && (int) ($p['prix'] ?? -1) >= 0));
        usort($paliers, static fn (array $a, array $b): int => (int) $a['q'] <=> (int) $b['q']);

        return array_map(static fn (array $p): array => ['q' => (int) $p['q'], 'prix' => (int) $p['prix']], $paliers);
    }

    /** @param list<array{q: int, prix: int}> $paliers */
    public function setPaliers(array $paliers): static
    {
        $propres = [];
        foreach ($paliers as $p) {
            $q = (int) ($p['q'] ?? 0);
            $prix = (int) ($p['prix'] ?? -1);
            if ($q > 0 && $prix >= 0) {
                $propres[$q] = ['q' => $q, 'prix' => $prix];
            }
        }
        ksort($propres);
        $this->paliers = [] === $propres ? null : array_values(\array_slice($propres, 0, 8));

        return $this;
    }

    /** Prix unitaire TTC pour ce nombre de pièces : le palier atteint, sinon le prix unitaire. */
    public function prixPour(int $pieces): int
    {
        $prix = $this->prixUnitaire;
        foreach ($this->getPaliers() as $p) {
            if ($pieces >= $p['q']) {
                $prix = $p['prix'];
            }
        }

        return $prix;
    }

    /** « 50 × 30 mm », « Ø 60 mm » */
    public static function libelleTaille(float $lMm, float $hMm, bool $rond = false): string
    {
        $n = static fn (float $v): string => rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',');

        return $rond && abs($lMm - $hMm) < self::TOLERANCE_MM ? 'Ø '.$n($lMm).' mm' : $n($lMm).' × '.$n($hMm).' mm';
    }

    public static function memeTaille(float $l1, float $h1, float $l2, float $h2): bool
    {
        return abs($l1 - $l2) <= self::TOLERANCE_MM && abs($h1 - $h2) <= self::TOLERANCE_MM;
    }

    /**
     * Pour la synchronisation : le site ne reçoit que la référence des
     * fichiers liés (nom, empreinte, taille, date), ni le contrôle ni le nom
     * de l'employé.
     *
     * @param list<array<string, mixed>>|null $fichiers
     *
     * @return list<array<string, mixed>>|null
     */
    public static function fichiersLiesPublics(?array $fichiers): ?array
    {
        if (null === $fichiers) {
            return null;
        }

        return array_values(array_map(static fn (array $f): array => array_intersect_key($f, array_flip(['l', 'h', 'nom', 'empreinte', 'octets', 'le'])), array_filter($fichiers, 'is_array')));
    }

    /** @param list<array{l: float, h: float}> $tailles */
    private function tailleDans(array $tailles, ?float $lMm, ?float $hMm): ?array
    {
        if (null === $lMm || null === $hMm) {
            return null;
        }
        foreach ($tailles as $t) {
            if (self::memeTaille($t['l'], $t['h'], $lMm, $hMm)) {
                return $t;
            }
        }

        return null;
    }
}
