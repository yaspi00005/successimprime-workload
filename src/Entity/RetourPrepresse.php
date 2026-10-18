<?php

namespace App\Entity;

use App\Repository\RetourPrepresseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Travail renvoyé au prépresse par le machiniste (maquette OP04) : motifs,
 * commentaire et annotation dessinée sur l'aperçu du fichier. Ouvert tant
 * que le prépresse n'a pas renvoyé le fichier corrigé en production.
 */
#[ORM\Entity(repositoryClass: RetourPrepresseRepository::class)]
#[ORM\Table(name: 'retour_prepresse')]
#[ORM\Index(name: 'idx_retour_prepresse_ouvert', columns: ['corrige_le'])]
class RetourPrepresse
{
    public const MOTIFS = [
        'dimensions' => 'Dimensions incorrectes',
        'fond_perdu' => 'Fond perdu manquant',
        'texte_bord' => 'Texte trop près du bord',
        'resolution' => 'Résolution trop faible',
        'couleur' => 'Couleur',
        'version' => 'Mauvaise version',
        'autre' => 'Autre',
    ];

    /** Formes de l'annotation (coordonnées de 0 à 1 sur l'aperçu). */
    public const FORMES = ['cercle', 'fleche', 'texte'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrdreProduction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private OrdreProduction $ordre;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par;

    /** Graphiste qui a préparé le fichier (sinon toute la prépresse est prévenue). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $destinataire;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $motifs;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $annotation;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $corrigeLe = null;

    /**
     * @param list<string>                     $motifs
     * @param list<array<string, mixed>>|null  $annotation
     */
    public function __construct(OrdreProduction $ordre, ?User $par, ?User $destinataire, array $motifs, ?string $commentaire, ?array $annotation, \DateTimeImmutable $creeLe)
    {
        $motifs = array_values(array_unique(array_filter($motifs, static fn (string $m): bool => isset(self::MOTIFS[$m]))));
        $commentaire = trim((string) $commentaire);
        if ($motifs === []) {
            throw new \InvalidArgumentException('Choisissez au moins un motif.');
        }
        if (\in_array('autre', $motifs, true) && $commentaire === '') {
            throw new \InvalidArgumentException('Pour « Autre », expliquez le problème dans le commentaire.');
        }
        $this->ordre = $ordre;
        $this->par = $par;
        $this->destinataire = $destinataire;
        $this->motifs = $motifs;
        $this->commentaire = $commentaire !== '' ? mb_substr($commentaire, 0, 2000) : null;
        $this->annotation = self::nettoyerAnnotation($annotation ?? []) ?: null;
        $this->creeLe = $creeLe;
    }

    /**
     * Garde seulement des formes connues, aux coordonnées bornées : le JSON
     * vient du navigateur.
     *
     * @param list<mixed> $formes
     *
     * @return list<array<string, mixed>>
     */
    public static function nettoyerAnnotation(array $formes): array
    {
        $borne = static fn (mixed $v): float => round(max(0.0, min(1.0, is_numeric($v) ? (float) $v : 0.0)), 4);
        $propres = [];
        foreach (\array_slice($formes, 0, 30) as $f) {
            if (!\is_array($f) || !\in_array($f['type'] ?? null, self::FORMES, true)) {
                continue;
            }
            $forme = ['type' => $f['type'], 'x1' => $borne($f['x1'] ?? 0), 'y1' => $borne($f['y1'] ?? 0), 'x2' => $borne($f['x2'] ?? 0), 'y2' => $borne($f['y2'] ?? 0)];
            if ('texte' === $f['type']) {
                $texte = trim(mb_substr((string) ($f['texte'] ?? ''), 0, 80));
                if ('' === $texte) {
                    continue;
                }
                $forme['texte'] = $texte;
            }
            $propres[] = $forme;
        }

        return $propres;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrdre(): OrdreProduction
    {
        return $this->ordre;
    }

    public function getPar(): ?User
    {
        return $this->par;
    }

    public function getDestinataire(): ?User
    {
        return $this->destinataire;
    }

    /** @return list<string> */
    public function getMotifs(): array
    {
        return $this->motifs;
    }

    /** « Fond perdu manquant, texte trop près du bord ». */
    public function getLibelleMotifs(): string
    {
        $libelles = array_map(static fn (string $m): string => self::MOTIFS[$m] ?? $m, $this->motifs);

        return ucfirst(mb_strtolower(implode(', ', $libelles)));
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    /** @return list<array<string, mixed>> */
    public function getAnnotation(): array
    {
        return $this->annotation ?? [];
    }

    public function getCreeLe(): \DateTimeImmutable
    {
        return $this->creeLe;
    }

    public function getCorrigeLe(): ?\DateTimeImmutable
    {
        return $this->corrigeLe;
    }

    public function estOuvert(): bool
    {
        return $this->corrigeLe === null;
    }

    public function marquerCorrige(\DateTimeImmutable $le): static
    {
        $this->corrigeLe ??= $le;

        return $this;
    }

    /** Minutes passées en correction (jusqu'à maintenant tant que c'est ouvert). */
    public function minutesEnCorrection(\DateTimeImmutable $maintenant): int
    {
        return max(0, intdiv(($this->corrigeLe ?? $maintenant)->getTimestamp() - $this->creeLe->getTimestamp(), 60));
    }
}
