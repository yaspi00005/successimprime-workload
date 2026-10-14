<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des imports de données de démarrage (docs/import-donnees.md) :
 * qui, quand, quel type, quel fichier (nom et empreinte SHA-256), combien
 * de lignes créées, mises à jour, écartées ou en erreur, et le résultat.
 *
 * Le fichier envoyé est gardé hors de public/ (var/uploads/imports), pour
 * l'audit ; seule la direction le relit. Le détail ligne par ligne garde
 * ce que l'import a créé ou changé, pour pouvoir l'annuler tant que rien
 * n'a servi.
 *
 * Table locale, non synchronisée avec le site en ligne.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_import_donnees_empreinte', columns: ['empreinte'])]
#[ORM\Index(name: 'idx_import_donnees_le', columns: ['le'])]
class ImportDonnees
{
    public const FAIT = 'fait';
    public const ECHEC = 'echec';
    public const ANNULE = 'annule';

    /** Résultat => [libellé, couleur de pastille]. */
    public const RESULTATS = [
        self::FAIT => ['Importé', 'vert'],
        self::ECHEC => ['Échec, rien d\'enregistré', 'rouge'],
        self::ANNULE => ['Annulé', 'gris'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $nomFichier;

    /** SHA-256 du fichier, en hexadécimal. */
    #[ORM\Column(length: 64)]
    private string $empreinte;

    /** Chemin relatif sous var/uploads/imports (AAAA/MM/<id>-<empreinte>.<ext>). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $chemin = null;

    #[ORM\Column]
    private int $taille = 0;

    /** Lignes de données lues dans le fichier (sans l'en-tête). */
    #[ORM\Column(options: ['default' => 0])]
    private int $lignes = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $crees = 0;

    /** « modifies » est un mot réservé de MariaDB. */
    #[ORM\Column(name: 'mis_a_jour', options: ['default' => 0])]
    private int $modifies = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $ignores = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $erreurs = 0;

    #[ORM\Column(length: 10)]
    private string $resultat = self::FAIT;

    /** Cause d'un échec, ou remarque. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $par = null;

    /** Nom de la personne, gardé même si son compte est supprimé. */
    #[ORM\Column(length: 120)]
    private string $parNom = '';

    #[ORM\Column]
    private \DateTimeImmutable $le;

    /**
     * Choix faits à l'envoi (date d'ouverture, compléter les fiches...).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $options = [];

    /**
     * Ce que l'import a fait, ligne par ligne : numéro de ligne, statut,
     * entité et identifiant, libellé, valeurs d'avant pour une mise à jour.
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $annuleLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $annulePar = null;

    public function __construct(string $type, string $nomFichier, string $empreinte, int $taille, ?User $par, string $parNom, \DateTimeImmutable $le)
    {
        $this->type = $type;
        $this->nomFichier = mb_substr($nomFichier, 0, 255);
        $this->empreinte = $empreinte;
        $this->taille = $taille;
        $this->par = $par;
        $this->parNom = mb_substr($parNom, 0, 120);
        $this->le = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getType(): string { return $this->type; }
    public function getNomFichier(): string { return $this->nomFichier; }
    public function getEmpreinte(): string { return $this->empreinte; }
    public function getChemin(): ?string { return $this->chemin; }
    public function setChemin(?string $c): static { $this->chemin = $c; return $this; }
    public function getTaille(): int { return $this->taille; }
    public function getLignes(): int { return $this->lignes; }
    public function getCrees(): int { return $this->crees; }
    public function getModifies(): int { return $this->modifies; }
    public function getIgnores(): int { return $this->ignores; }
    public function getErreurs(): int { return $this->erreurs; }

    /** @param array{lignes: int, crees: int, modifies: int, ignores: int, erreurs: int} $c */
    public function compter(array $c): static
    {
        $this->lignes = $c['lignes'];
        $this->crees = $c['crees'];
        $this->modifies = $c['modifies'];
        $this->ignores = $c['ignores'];
        $this->erreurs = $c['erreurs'];

        return $this;
    }

    public function getResultat(): string { return $this->resultat; }
    public function getResultatLibelle(): string { return self::RESULTATS[$this->resultat][0] ?? $this->resultat; }
    public function getResultatCouleur(): string { return self::RESULTATS[$this->resultat][1] ?? 'gris'; }
    public function estFait(): bool { return self::FAIT === $this->resultat; }

    public function echouer(string $message): static
    {
        $this->resultat = self::ECHEC;
        $this->message = mb_substr($message, 0, 2000);

        return $this;
    }

    public function getMessage(): ?string { return $this->message; }
    public function getPar(): ?User { return $this->par; }
    public function getParNom(): string { return $this->parNom; }
    public function getLe(): \DateTimeImmutable { return $this->le; }

    /** @return array<string, mixed> */
    public function getOptions(): array { return $this->options; }
    /** @param array<string, mixed> $o */
    public function setOptions(array $o): static { $this->options = $o; return $this; }

    /** @return list<array<string, mixed>> */
    public function getDetails(): array { return $this->details; }
    /** @param list<array<string, mixed>> $d */
    public function setDetails(array $d): static { $this->details = array_values($d); return $this; }

    /** @param array<string, mixed> $ligne */
    public function noter(array $ligne): static { $this->details[] = $ligne; return $this; }

    public function getAnnuleLe(): ?\DateTimeImmutable { return $this->annuleLe; }
    public function getAnnulePar(): ?User { return $this->annulePar; }

    public function annuler(?User $par, \DateTimeImmutable $le): static
    {
        $this->resultat = self::ANNULE;
        $this->annuleLe = $le;
        $this->annulePar = $par;

        return $this;
    }
}
