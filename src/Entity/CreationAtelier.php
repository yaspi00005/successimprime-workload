<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Modèle validé dans l'Atelier 3D (docs/atelier-3d.md) : l'article, ses
 * couleurs, les quantités par taille, le marquage de chaque face (image,
 * taille, hauteur, texte, police) et les images qui l'accompagnent : la
 * vue de face et de dos rendues par la page (maquette du client pour le
 * graphiste, qui prépare toujours le vrai BAT) et l'image d'origine de
 * chaque face.
 *
 * Écrite en ligne au moment de « Valider mon modèle », elle monte au
 * local avec la commande « à confirmer » du client connecté, ou avec la
 * demande de devis du visiteur (DemandeDevis::creation). Elle ne change
 * plus ensuite, sauf sa dernière commande quand le client la recommande.
 */
#[ORM\Entity]
#[ORM\Table(name: 'creation_atelier')]
#[ORM\Index(name: 'idx_creation_atelier_cree', columns: ['cree_le'])]
class CreationAtelier
{
    use PublicIdTrait;

    public const DEVANT = 'devant';
    public const DOS = 'dos';
    public const FACES = [self::DEVANT, self::DOS];

    /**
     * Polices proposées (public/vendor/polices-atelier, licence OFL). La
     * première est celle du studio et celle retenue pour un nom inconnu.
     */
    public const POLICES = ['Montserrat', 'Archivo Black', 'Bebas Neue', 'Pacifico', 'Figtree'];

    public const FAMILLE_VETEMENT = 'vetement';
    public const FAMILLE_CASQUETTE = 'casquette';

    /**
     * Emplacements du marquage (« Position du logo » du studio), par
     * famille d'article et par face : libellé, puis plus grande largeur et
     * hauteur imprimées en cm (null : celle de la zone d'impression de
     * l'article, qui borne toujours). Le premier emplacement de chaque face
     * est celui des créations faites avant le studio (logo centré).
     *
     * @var array<string, array<string, array<string, array{0: string, 1: ?float, 2: ?float}>>>
     */
    public const EMPLACEMENTS = [
        self::FAMILLE_VETEMENT => [
            self::DEVANT => [
                'devant-centre' => ['Devant centre', null, null],
                'poitrine-gauche' => ['Poitrine gauche', 10.0, 10.0],
                'poitrine-droite' => ['Poitrine droite', 10.0, 10.0],
            ],
            self::DOS => [
                'dos-centre' => ['Dos centre', null, null],
                'dos-haut' => ['Dos haut', null, 10.0],
            ],
        ],
        self::FAMILLE_CASQUETTE => [
            self::DEVANT => [
                'devant' => ['Devant', null, null],
            ],
            self::DOS => [
                'arriere' => ['Arrière', 8.0, 4.0],
                'cote' => ['Côté', 6.0, 4.0],
            ],
        ],
    ];

    /** Couleurs du texte : nom => couleur. */
    public const COULEURS_TEXTE = ['Blanc' => '#FFFFFF', 'Noir' => '#111111', 'Jaune' => '#FDC82A', 'Rouge' => '#DE1028', 'Bleu' => '#0F4590'];

    public const TEXTE_MAX = 28;

    /**
     * Avec un texte sur la face, l'image occupe ce rapport de sa taille
     * (le texte prend la place restante) : la page et le contrôle de
     * résolution utilisent la même règle.
     */
    public const REDUCTION_AVEC_TEXTE = 0.82;

    /** Pièces au plus par taille (au-delà : devis au comptoir). */
    public const QUANTITE_TAILLE_MAX = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** CR-0012 ; W-CR-0012 quand elle est écrite en ligne. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $numero = null;

    /** Client connecté ; vide pour un visiteur (la demande de devis porte son nom). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Clients $client = null;

    /** Personne qui l'a faite (compte de l'entreprise, ou nom du visiteur). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $auteur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProduitConfiguration $configuration = null;

    /** « T-shirt personnalisé DTF · Noir » au moment de la validation. */
    #[ORM\Column(length: 255)]
    private string $designation = '';

    /** Clé de ModeleAtelier::FORMES. */
    #[ORM\Column(length: 20)]
    private string $forme = ModeleAtelier::TSHIRT;

    /** @var array{nom: string, hex: string} */
    #[ORM\Column(type: 'json')]
    private array $couleur = ['nom' => '', 'hex' => '#FFFFFF'];

    /**
     * Seconde couleur : visière d'une casquette, bords (col et manches)
     * d'un polo ; vide pour un t-shirt ou un polo sans bord de couleur.
     *
     * @var array{nom: string, hex: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $couleurVisiere = null;

    /** @var array<string, int> taille => pièces (vide pour une casquette) */
    #[ORM\Column(type: 'json')]
    private array $tailles = [];

    #[ORM\Column]
    private int $quantite = 0;

    /**
     * Marquage par face (devant, dos) : image (oui/non et nom), taille en %,
     * hauteur, texte, police, couleur et place du texte.
     *
     * @var array<string, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $reglages = [];

    /** Vues rendues par la page (PNG réencodé) : maquette du client. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $renduDevant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $renduDos = null;

    /** Image d'origine du client pour chaque face (réencodée, sans métadonnées). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $imageDevant = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CommandeDetailFichier $imageDos = null;

    /** Dernière commande passée avec ce modèle (client connecté). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Commandes $commande = null;

    #[ORM\Column]
    private int $nombreCommandes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $derniereCommandeLe = null;

    /** Sans argument : la synchronisation crée la copie puis la remplit. */
    public function __construct()
    {
        $this->initialiserPublicId();
        $this->creeLe = new \DateTimeImmutable();
    }

    /**
     * Réglages d'une face envoyés par la page, ramenés aux valeurs permises.
     * Une face sans image ni texte n'est pas gardée.
     *
     * @param array<string, mixed> $brut
     *
     * @return array{image: bool, nomImage: ?string, position: string, taille: int, hauteur: int, texte: string, police: string, gras: bool, couleurTexte: string, placeTexte: string}|null
     */
    public static function reglagesFace(array $brut, bool $image, ?string $nomImage, string $forme, string $face): ?array
    {
        $texte = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($brut['texte'] ?? '')));
        $texte = mb_substr($texte, 0, self::TEXTE_MAX);
        if (!$image && '' === $texte) {
            return null;
        }
        $police = (string) ($brut['police'] ?? '');
        $couleur = (string) ($brut['couleurTexte'] ?? '');

        return [
            'image' => $image,
            'nomImage' => $image && null !== $nomImage ? mb_substr($nomImage, 0, 120) : null,
            'position' => self::emplacement($forme, $face, $brut['position'] ?? null),
            'taille' => max(15, min(100, (int) ($brut['taille'] ?? 60))),
            'hauteur' => max(-40, min(40, (int) ($brut['hauteur'] ?? 0))),
            'texte' => $texte,
            'police' => \in_array($police, self::POLICES, true) ? $police : self::POLICES[0],
            'gras' => true === ($brut['gras'] ?? false),
            'couleurTexte' => isset(self::COULEURS_TEXTE[$couleur]) ? $couleur : 'Blanc',
            'placeTexte' => 'dessus' === ($brut['placeTexte'] ?? null) ? 'dessus' : 'dessous',
        ];
    }

    /** « casquette » ou « vetement » (t-shirt, polo) : les emplacements en dépendent. */
    public static function famille(string $forme): string
    {
        return ModeleAtelier::CASQUETTE === $forme ? self::FAMILLE_CASQUETTE : self::FAMILLE_VETEMENT;
    }

    /**
     * Emplacement permis pour cette face, ou le premier de la face (celui
     * des créations faites avant le studio).
     */
    public static function emplacement(string $forme, string $face, mixed $position): string
    {
        $permis = self::EMPLACEMENTS[self::famille($forme)][self::DOS === $face ? self::DOS : self::DEVANT];

        return \is_string($position) && isset($permis[$position]) ? $position : (string) array_key_first($permis);
    }

    /**
     * Taille imprimée la plus grande à cet emplacement (largeur, hauteur en
     * cm) : celle de l'emplacement, bornée par la zone d'impression de
     * l'article. Le contrôle de résolution part de là.
     *
     * @return array{0: float, 1: float}
     */
    public static function zoneImpression(string $forme, string $face, ?string $position, float $zoneLargeurCm, float $zoneHauteurCm): array
    {
        $e = self::EMPLACEMENTS[self::famille($forme)][self::DOS === $face ? self::DOS : self::DEVANT][self::emplacement($forme, $face, $position)];

        return [
            null !== $e[1] ? min($e[1], $zoneLargeurCm) : $zoneLargeurCm,
            null !== $e[2] ? min($e[2], $zoneHauteurCm) : $zoneHauteurCm,
        ];
    }

    /**
     * Quantités par taille envoyées par la page : seulement les tailles de
     * l'article, entre 0 et QUANTITE_TAILLE_MAX, sans les tailles à zéro.
     *
     * @param array<mixed>  $brut
     * @param list<string>  $taillesPermises
     *
     * @return array<string, int>
     */
    public static function quantitesParTaille(array $brut, array $taillesPermises): array
    {
        $quantites = [];
        foreach ($taillesPermises as $t) {
            $n = max(0, min(self::QUANTITE_TAILLE_MAX, (int) ($brut[$t] ?? 0)));
            if ($n > 0) {
                $quantites[$t] = $n;
            }
        }

        return $quantites;
    }

    /**
     * Taille de l'image en % de la zone d'impression, une fois la place du
     * texte retirée (réglages d'une face, voir reglagesFace()).
     *
     * @param array<string, mixed> $reglages
     */
    public static function tailleImage(array $reglages): int
    {
        $taille = max(15, min(100, (int) ($reglages['taille'] ?? 60)));

        return '' !== trim((string) ($reglages['texte'] ?? '')) ? (int) round($taille * self::REDUCTION_AVEC_TEXTE) : $taille;
    }

    /** Faces marquées (devant, dos), dans cet ordre. @return list<string> */
    public function facesMarquees(): array
    {
        return array_values(array_filter(self::FACES, fn (string $f): bool => isset($this->reglages[$f])));
    }

    /** « S × 5, M × 10 » ; « 20 pièces » pour une casquette. */
    public function resumeTailles(): string
    {
        if ([] === $this->tailles) {
            return \sprintf('%d pièce%s', $this->quantite, $this->quantite > 1 ? 's' : '');
        }

        return implode(', ', array_map(static fn (string $t, int $n): string => $t.' × '.$n, array_keys($this->tailles), $this->tailles));
    }

    /** « Poitrine gauche : image « logo.png » à 60 % + texte « BAMAKO » (Montserrat gras, blanc, dessous) ». @return list<string> */
    public function resumeMarquage(): array
    {
        $lignes = [];
        foreach ($this->facesMarquees() as $f) {
            $r = $this->reglages[$f];
            $morceaux = [];
            if ($r['image'] ?? false) {
                $morceaux[] = 'image'.(null !== ($r['nomImage'] ?? null) ? ' « '.$r['nomImage'].' »' : '').' à '.($r['taille'] ?? 60).' %';
            }
            if ('' !== ($r['texte'] ?? '')) {
                $morceaux[] = \sprintf('texte « %s » (%s%s, %s, %s)', $r['texte'], $r['police'] ?? '', ($r['gras'] ?? false) ? ' gras' : '', mb_strtolower((string) ($r['couleurTexte'] ?? '')), 'dessus' === ($r['placeTexte'] ?? '') ? 'au-dessus' : 'dessous');
            }
            $lignes[] = $this->libelleEmplacement($f).' : '.implode(' + ', $morceaux);
        }

        return $lignes;
    }

    /**
     * « Devant », « Dos », ou « Arrière » pour une casquette (« Côté »
     * quand le marquage de l'arrière est sur le côté) : nom de la vue.
     */
    public function libelleFace(string $face): string
    {
        if (self::DOS !== $face) {
            return 'Devant';
        }
        if (ModeleAtelier::CASQUETTE !== $this->forme) {
            return 'Dos';
        }

        return 'cote' === ($this->reglages[self::DOS]['position'] ?? null) ? 'Côté' : 'Arrière';
    }

    /**
     * Emplacement du marquage de la face (« Poitrine gauche ») ; le nom de
     * la face pour une création faite avant le studio.
     */
    public function libelleEmplacement(string $face): string
    {
        $position = $this->reglages[$face]['position'] ?? null;
        if (!\is_string($position)) {
            return $this->libelleFace($face);
        }

        return self::EMPLACEMENTS[self::famille($this->forme)][self::DOS === $face ? self::DOS : self::DEVANT][self::emplacement($this->forme, $face, $position)][0];
    }

    /** Couleur lisible : « Noir », « Rouge · visière Noir », « Blanc · bords Orange ». */
    public function libelleCouleur(): string
    {
        if (null === $this->couleurVisiere) {
            return $this->couleur['nom'];
        }

        return $this->couleur['nom'].(ModeleAtelier::POLO === $this->forme ? ' · bords ' : ' · visière ').$this->couleurVisiere['nom'];
    }

    /** Note pour l'atelier, sur la ligne de commande ou la demande (sans montant). */
    public function note(): string
    {
        return mb_substr(implode("\n", [
            \sprintf('Atelier 3D %s : %s, %s.', $this->numero ?? '', $this->libelleCouleur(), $this->resumeTailles()),
            ...$this->resumeMarquage(),
            'Vues de face et de dos jointes : maquette du client, le BAT reste à préparer par le graphiste.',
        ]), 0, 2000);
    }

    public function noterCommande(Commandes $commande, \DateTimeImmutable $le): static
    {
        $this->commande = $commande;
        ++$this->nombreCommandes;
        $this->derniereCommandeLe = $le;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumero(): ?string
    {
        return $this->numero;
    }

    public function setNumero(?string $numero): static
    {
        $this->numero = $numero;

        return $this;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(?Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getAuteur(): ?string
    {
        return $this->auteur;
    }

    public function setAuteur(?string $auteur): static
    {
        $this->auteur = null === $auteur ? null : mb_substr(trim($auteur), 0, 120);

        return $this;
    }

    public function getConfiguration(): ?ProduitConfiguration
    {
        return $this->configuration;
    }

    public function setConfiguration(?ProduitConfiguration $configuration): static
    {
        $this->configuration = $configuration;

        return $this;
    }

    public function getDesignation(): string
    {
        return $this->designation;
    }

    public function setDesignation(string $designation): static
    {
        $this->designation = mb_substr($designation, 0, 255);

        return $this;
    }

    public function getForme(): string
    {
        return $this->forme;
    }

    public function setForme(string $forme): static
    {
        $this->forme = isset(ModeleAtelier::FORMES[$forme]) ? $forme : ModeleAtelier::TSHIRT;

        return $this;
    }

    /** @return array{nom: string, hex: string} */
    public function getCouleur(): array
    {
        return $this->couleur;
    }

    /** @param array{nom: string, hex: string} $couleur */
    public function setCouleur(array $couleur): static
    {
        $this->couleur = $couleur;

        return $this;
    }

    /** @return array{nom: string, hex: string}|null */
    public function getCouleurVisiere(): ?array
    {
        return $this->couleurVisiere;
    }

    /** @param array{nom: string, hex: string}|null $couleur */
    public function setCouleurVisiere(?array $couleur): static
    {
        $this->couleurVisiere = $couleur;

        return $this;
    }

    /** @return array<string, int> */
    public function getTailles(): array
    {
        return $this->tailles;
    }

    /** @param array<string, int> $tailles */
    public function setTailles(array $tailles): static
    {
        $this->tailles = $tailles;

        return $this;
    }

    public function getQuantite(): int
    {
        return $this->quantite;
    }

    public function setQuantite(int $quantite): static
    {
        $this->quantite = max(0, $quantite);

        return $this;
    }

    /** @return array<string, array<string, mixed>> */
    public function getReglages(): array
    {
        return $this->reglages;
    }

    /** @param array<string, array<string, mixed>> $reglages */
    public function setReglages(array $reglages): static
    {
        $this->reglages = array_intersect_key($reglages, array_flip(self::FACES));

        return $this;
    }

    public function getRenduDevant(): ?CommandeDetailFichier
    {
        return $this->renduDevant;
    }

    public function setRenduDevant(?CommandeDetailFichier $f): static
    {
        $this->renduDevant = $f;

        return $this;
    }

    public function getRenduDos(): ?CommandeDetailFichier
    {
        return $this->renduDos;
    }

    public function setRenduDos(?CommandeDetailFichier $f): static
    {
        $this->renduDos = $f;

        return $this;
    }

    public function getImageDevant(): ?CommandeDetailFichier
    {
        return $this->imageDevant;
    }

    public function setImageDevant(?CommandeDetailFichier $f): static
    {
        $this->imageDevant = $f;

        return $this;
    }

    public function getImageDos(): ?CommandeDetailFichier
    {
        return $this->imageDos;
    }

    public function setImageDos(?CommandeDetailFichier $f): static
    {
        $this->imageDos = $f;

        return $this;
    }

    /** Le fichier demandé (rendu-devant, rendu-dos, image-devant, image-dos), ou null. */
    public function fichier(string $quoi): ?CommandeDetailFichier
    {
        return match ($quoi) {
            'rendu-devant' => $this->renduDevant,
            'rendu-dos' => $this->renduDos,
            'image-devant' => $this->imageDevant,
            'image-dos' => $this->imageDos,
            default => null,
        };
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

    public function getNombreCommandes(): int
    {
        return $this->nombreCommandes;
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function setCreeLe(\DateTimeImmutable $creeLe): static
    {
        $this->creeLe = $creeLe;

        return $this;
    }

    public function getDerniereCommandeLe(): ?\DateTimeImmutable
    {
        return $this->derniereCommandeLe;
    }
}
