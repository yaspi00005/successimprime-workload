<?php

namespace App\Entity;

use App\Repository\CorbeilleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Corbeille (docs/corbeille.md) : une ligne par fiche supprimée, gardée
 * le temps réglé (30 jours par défaut) avant la suppression définitive.
 *
 * Les lignes d'une même suppression partagent un « groupe » : la fiche
 * supprimée (racine) et ce qui part avec elle (lignes d'un document,
 * fichiers d'un client…). Chaque ligne garde les colonnes de la table au
 * moment de la suppression (identifiants des fiches liées compris), sans
 * les secrets (mots de passe, codes, jetons). Une restauration remet les
 * lignes avec les mêmes identifiants.
 *
 * Mode « masque » : la fiche n'a pas quitté sa table, elle est seulement
 * cachée (devis et commandes supprimés, adresse ou fichier retiré par le
 * client) ; la restaurer remet le champ « restauration ».
 *
 * Écrite par App\Service\Corbeille\CaptureCorbeille (DBAL, après
 * l'enregistrement) : cette entité ne sert qu'à la lecture et au schéma.
 */
#[ORM\Entity(repositoryClass: CorbeilleRepository::class)]
#[ORM\Table(name: 'corbeille')]
#[ORM\Index(name: 'idx_corbeille_groupe', columns: ['groupe'])]
#[ORM\Index(name: 'idx_corbeille_liste', columns: ['racine', 'supprime_le'])]
#[ORM\Index(name: 'idx_corbeille_ligne', columns: ['table_nom', 'ligne_id'])]
class Corbeille
{
    public const MODE_LIGNE = 'ligne';
    public const MODE_MASQUE = 'masque';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $groupe = '';

    #[ORM\Column]
    private bool $racine = false;

    /** Ordre de remise en place dans le groupe (les fiches liées d'abord). */
    #[ORM\Column]
    private int $ordre = 0;

    #[ORM\Column(name: 'table_nom', length: 64)]
    private string $tableNom = '';

    /** Clé primaire de la ligne : « 42 », ou en JSON pour une clé à plusieurs colonnes. */
    #[ORM\Column(name: 'ligne_id', length: 190)]
    private string $ligneId = '';

    /** Nature de la fiche, en français (« Devis », « Client »). */
    #[ORM\Column(length: 80)]
    private string $type = '';

    #[ORM\Column(length: 255)]
    private string $libelle = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $donnees = [];

    /**
     * Fiches restées en place qui pointaient vers celle-ci (lien vidé à la
     * suppression) : [{table, valeurs, cle}], rebranchées à la restauration.
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $liens = null;

    #[ORM\Column(length: 10)]
    private string $mode = self::MODE_LIGNE;

    /** @var array<string, mixed>|null mode masque : champ => valeur remise à la restauration */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $restauration = null;

    #[ORM\Column(name: 'supprime_le')]
    private \DateTimeImmutable $supprimeLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'supprime_par_id', onDelete: 'SET NULL')]
    private ?User $supprimePar = null;

    /** Qui a supprimé, en clair (personne du personnel ou client de l'espace). */
    #[ORM\Column(name: 'supprime_par_nom', length: 190, nullable: true)]
    private ?string $supprimeParNom = null;

    public function __construct()
    {
        $this->supprimeLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getGroupe(): string { return $this->groupe; }
    public function isRacine(): bool { return $this->racine; }
    public function getOrdre(): int { return $this->ordre; }
    public function getTableNom(): string { return $this->tableNom; }
    public function getLigneId(): string { return $this->ligneId; }
    public function getType(): string { return $this->type; }
    public function getLibelle(): string { return $this->libelle; }
    /** @return array<string, mixed> */
    public function getDonnees(): array { return $this->donnees; }
    /** @return list<array<string, mixed>>|null */
    public function getLiens(): ?array { return $this->liens; }
    public function getMode(): string { return $this->mode; }
    /** @return array<string, mixed>|null */
    public function getRestauration(): ?array { return $this->restauration; }
    public function getSupprimeLe(): \DateTimeImmutable { return $this->supprimeLe; }
    public function getSupprimePar(): ?User { return $this->supprimePar; }
    public function getSupprimeParNom(): ?string { return $this->supprimeParNom; }
    public function estMasque(): bool { return self::MODE_MASQUE === $this->mode; }
}
