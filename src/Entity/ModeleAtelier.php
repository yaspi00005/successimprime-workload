<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use Doctrine\ORM\Mapping as ORM;

/**
 * Article proposé dans l'Atelier 3D (docs/atelier-3d.md) : un produit
 * textile du catalogue (sa configuration donne le prix et la grille B2B),
 * le modèle 3D qui le montre, ses couleurs, ses tailles et la zone
 * d'impression qui sert à juger la résolution de l'image du client.
 *
 * Le catalogue n'a pas de variantes de couleur ni de taille : elles sont
 * réglées ici, au local (Réglages › Atelier 3D), puis envoyées au site.
 */
#[ORM\Entity]
#[ORM\Table(name: 'modele_atelier')]
class ModeleAtelier
{
    use PublicIdTrait;

    public const TSHIRT = 'tshirt';
    public const POLO = 'polo';
    public const CASQUETTE = 'casquette';

    /** Modèles 3D de la page (dessinés par le code, ou public/modeles-3d/<forme>.glb s'il existe). */
    public const FORMES = [self::TSHIRT => 'T-shirt', self::POLO => 'Polo', self::CASQUETTE => 'Casquette'];

    /** Couleurs du prototype validé par Yaya : proposées pour un nouvel article. */
    public const COULEURS_DEFAUT = [
        ['nom' => 'Blanc', 'hex' => '#F4F4F1'],
        ['nom' => 'Noir', 'hex' => '#1F2026'],
        ['nom' => 'Bleu Succès', 'hex' => '#0F4590'],
        ['nom' => 'Rouge', 'hex' => '#DE1028'],
        ['nom' => 'Jaune', 'hex' => '#FDC82A'],
        ['nom' => 'Orange', 'hex' => '#E95B35'],
        ['nom' => 'Rose', 'hex' => '#E8398A'],
        ['nom' => 'Vert', 'hex' => '#1F8A4C'],
        ['nom' => 'Ciel', 'hex' => '#6FB3E6'],
        ['nom' => 'Gris chiné', 'hex' => '#9AA0AA'],
        ['nom' => 'Bordeaux', 'hex' => '#6D1A2A'],
    ];

    public const TAILLES_DEFAUT = ['S', 'M', 'L', 'XL', 'XXL'];

    /** Zone d'impression devant (largeur, hauteur en cm) proposée selon la forme. */
    public const ZONES_DEFAUT = [self::TSHIRT => [28.0, 35.0], self::POLO => [24.0, 30.0], self::CASQUETTE => [10.0, 6.0]];

    public const COULEURS_MAX = 24;
    public const TAILLES_MAX = 12;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Produit du catalogue : son prix public, son prix B2B, ses quantités mini et maxi. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ProduitConfiguration $configuration = null;

    /** Clé de FORMES. */
    #[ORM\Column(length: 20)]
    private string $forme = self::TSHIRT;

    /** @var list<array{nom: string, hex: string}> */
    #[ORM\Column(type: 'json')]
    private array $couleurs = self::COULEURS_DEFAUT;

    /** @var list<string> vide : une seule quantité (casquette) */
    #[ORM\Column(type: 'json')]
    private array $tailles = self::TAILLES_DEFAUT;

    #[ORM\Column]
    private float $zoneLargeurCm = 28.0;

    #[ORM\Column]
    private float $zoneHauteurCm = 35.0;

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column]
    private int $ordre = 10;

    public function __construct()
    {
        $this->initialiserPublicId();
    }

    /** Nouvel article pour un produit du catalogue, avec les valeurs du prototype. */
    public static function pour(ProduitConfiguration $configuration, string $forme): self
    {
        $m = new self();
        $m->configuration = $configuration;
        $m->setForme($forme);
        [$m->zoneLargeurCm, $m->zoneHauteurCm] = self::ZONES_DEFAUT[$m->forme];
        $m->tailles = self::CASQUETTE === $m->forme ? [] : self::TAILLES_DEFAUT;

        return $m;
    }

    /** Forme devinée d'après le nom du produit (« Polo piqué », « Casquette 6 panneaux »). */
    public static function formeDevinee(string $nom): string
    {
        $nom = mb_strtolower($nom);

        return match (true) {
            str_contains($nom, 'casquette') || 1 === preg_match('/\bcap\b/u', $nom) => self::CASQUETTE, // pas « capuche »
            str_contains($nom, 'polo') => self::POLO,
            default => self::TSHIRT,
        };
    }

    /**
     * Couleurs tapées dans les réglages : « Nom #RRGGBB » par ligne ; les
     * lignes illisibles et les doublons sont écartés.
     *
     * @return list<array{nom: string, hex: string}>
     */
    public static function lireCouleurs(string $texte): array
    {
        $couleurs = [];
        foreach (preg_split('/\R/u', $texte) ?: [] as $ligne) {
            if (!preg_match('/^\s*(.+?)\s*[:;,]?\s*#?([0-9A-Fa-f]{6})\s*$/u', $ligne, $m)) {
                continue;
            }
            $nom = mb_substr(trim($m[1]), 0, 30);
            if ('' === $nom || isset($couleurs[mb_strtolower($nom)])) {
                continue;
            }
            $couleurs[mb_strtolower($nom)] = ['nom' => $nom, 'hex' => '#'.strtoupper($m[2])];
        }

        return \array_slice(array_values($couleurs), 0, self::COULEURS_MAX);
    }

    /** @return list<string> */
    public static function lireTailles(string $texte): array
    {
        $tailles = [];
        foreach (preg_split('/[\s,;]+/u', $texte) ?: [] as $t) {
            $t = mb_strtoupper(mb_substr(trim($t), 0, 8));
            if ('' !== $t && !\in_array($t, $tailles, true)) {
                $tailles[] = $t;
            }
        }

        return \array_slice($tailles, 0, self::TAILLES_MAX);
    }

    /** La couleur de ce nom, ou null si l'article ne la propose pas. */
    public function couleur(?string $nom): ?array
    {
        foreach ($this->couleurs as $c) {
            if ($c['nom'] === $nom) {
                return $c;
            }
        }

        return null;
    }

    public function aDesTailles(): bool
    {
        return [] !== $this->tailles;
    }

    public function getLibelleForme(): string
    {
        return self::FORMES[$this->forme] ?? $this->forme;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getForme(): string
    {
        return $this->forme;
    }

    public function setForme(string $forme): static
    {
        $this->forme = isset(self::FORMES[$forme]) ? $forme : self::TSHIRT;

        return $this;
    }

    /** @return list<array{nom: string, hex: string}> */
    public function getCouleurs(): array
    {
        return $this->couleurs;
    }

    /** @param list<array{nom: string, hex: string}> $couleurs */
    public function setCouleurs(array $couleurs): static
    {
        $this->couleurs = array_values($couleurs);

        return $this;
    }

    /** @return list<string> */
    public function getTailles(): array
    {
        return $this->tailles;
    }

    /** @param list<string> $tailles */
    public function setTailles(array $tailles): static
    {
        $this->tailles = array_values($tailles);

        return $this;
    }

    public function getZoneLargeurCm(): float
    {
        return $this->zoneLargeurCm;
    }

    public function setZoneLargeurCm(float $cm): static
    {
        $this->zoneLargeurCm = max(1.0, min(100.0, $cm));

        return $this;
    }

    public function getZoneHauteurCm(): float
    {
        return $this->zoneHauteurCm;
    }

    public function setZoneHauteurCm(float $cm): static
    {
        $this->zoneHauteurCm = max(1.0, min(100.0, $cm));

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

    public function getOrdre(): int
    {
        return $this->ordre;
    }

    public function setOrdre(int $ordre): static
    {
        $this->ordre = $ordre;

        return $this;
    }
}
