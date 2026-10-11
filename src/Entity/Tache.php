<?php

namespace App\Entity;

use App\Repository\TacheRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une chose à faire un jour donné (T01, T04). Créée seule ou par une
 * échéance récurrente, dont elle recopie titre, catégorie, responsable et
 * étapes. « En retard » est calculé : date passée et pas faite.
 */
#[ORM\Entity(repositoryClass: TacheRepository::class)]
#[ORM\Index(name: 'idx_tache_prevue', columns: ['prevue_le'])]
#[ORM\UniqueConstraint(name: 'uniq_tache_occurrence', columns: ['echeance_id', 'date_origine'])]
class Tache
{
    public const A_FAIRE = 'a_faire';
    public const EN_COURS = 'en_cours';
    public const FAIT = 'fait';
    public const ANNULEE = 'annulee';

    public const STATUTS = [self::A_FAIRE => 'À faire', self::EN_COURS => 'En cours', self::FAIT => 'Fait', self::ANNULEE => 'Annulée'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Echeance $echeance = null;

    #[ORM\Column(length: 150)]
    private string $titre = '';

    #[ORM\Column(length: 20)]
    private string $categorie = 'administratif';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $responsable = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $copie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Entreprise $entreprise = null;

    /** Date effective, après le décalage week-end ou férié. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $prevueLe;

    /** Date de la règle quand elle a été décalée (« dimanche → avancé au 23 »). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dateOrigine = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $raisonDecalage = null;

    #[ORM\Column(length: 5, nullable: true)]
    private ?string $heure = null;

    #[ORM\Column(length: 20)]
    private string $statut = self::A_FAIRE;

    /** @var list<array{libelle: string, fait: bool}> */
    #[ORM\Column(type: Types::JSON)]
    private array $etapes = [];

    #[ORM\Column(nullable: true)]
    private ?int $montantEstime = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $faitLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $faitPar = null;

    /** Rappels déjà envoyés (j5, veille, jour). @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $rappelsEnvoyes = [];

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    public function __construct(\DateTimeImmutable $prevueLe, ?\DateTimeImmutable $creeLe = null)
    {
        $this->prevueLe = $prevueLe->setTime(0, 0);
        $this->creeLe = $creeLe ?? new \DateTimeImmutable();
    }

    /** Recopie la règle (titre, catégorie, responsable, étapes, montant). */
    public function reprendre(Echeance $e): static
    {
        $this->echeance = $e;
        $this->titre = $e->getTitre();
        $this->categorie = $e->getCategorie();
        $this->responsable = $e->getResponsable();
        $this->copie = $e->getCopie();
        $this->entreprise = $e->getEntreprise();
        $this->heure = $e->getHeure();
        $this->montantEstime = $e->getMontantEstime();
        $this->etapes = array_map(static fn (string $l): array => ['libelle' => $l, 'fait' => false], $e->getEtapes());

        return $this;
    }

    public function estFaite(): bool { return $this->statut === self::FAIT; }

    public function enRetard(\DateTimeInterface $maintenant): bool
    {
        return \in_array($this->statut, [self::A_FAIRE, self::EN_COURS], true) && $this->prevueLe < \DateTimeImmutable::createFromInterface($maintenant)->setTime(0, 0);
    }

    /** Pas encore touchée : la règle peut la réécrire. */
    public function intacte(): bool
    {
        return $this->statut === self::A_FAIRE && array_filter(array_column($this->etapes, 'fait')) === [] && $this->note === null;
    }

    public function cocher(User $par, \DateTimeImmutable $le): void
    {
        $this->statut = self::FAIT;
        $this->faitLe = $le;
        $this->faitPar = $par;
    }

    public function decocher(): void
    {
        $this->statut = $this->etapesFaites() > 0 ? self::EN_COURS : self::A_FAIRE;
        $this->faitLe = null;
        $this->faitPar = null;
    }

    public function basculerEtape(int $index): void
    {
        if (!isset($this->etapes[$index])) {
            return;
        }
        $this->etapes[$index]['fait'] = !$this->etapes[$index]['fait'];
        if ($this->statut !== self::FAIT) {
            $this->statut = $this->etapesFaites() > 0 ? self::EN_COURS : self::A_FAIRE;
        }
    }

    public function etapesFaites(): int
    {
        return \count(array_filter(array_column($this->etapes, 'fait')));
    }

    public function rappelEnvoye(string $cle): bool { return \in_array($cle, $this->rappelsEnvoyes, true); }
    public function noterRappel(string $cle): void { $this->rappelsEnvoyes[] = $cle; $this->rappelsEnvoyes = array_values(array_unique($this->rappelsEnvoyes)); }

    /** Le responsable ou la copie. */
    public function concerne(User $u): bool { return $this->responsable === $u || $this->copie === $u; }

    public function getId(): ?int { return $this->id; }
    public function getEcheance(): ?Echeance { return $this->echeance; }
    public function getTitre(): string { return $this->titre; }
    public function setTitre(string $t): static { $this->titre = mb_substr(trim($t), 0, 150); return $this; }
    public function getCategorie(): string { return $this->categorie; }
    public function setCategorie(string $c): static { $this->categorie = isset(Echeance::CATEGORIES[$c]) ? $c : 'administratif'; return $this; }
    public function getResponsable(): ?User { return $this->responsable; }
    public function setResponsable(?User $u): static { $this->responsable = $u; return $this; }
    public function getCopie(): ?User { return $this->copie; }
    public function getEntreprise(): ?Entreprise { return $this->entreprise; }
    public function getPrevueLe(): \DateTimeImmutable { return $this->prevueLe; }
    public function setPrevueLe(\DateTimeImmutable $d): static { $this->prevueLe = $d->setTime(0, 0); return $this; }
    public function getDateOrigine(): ?\DateTimeImmutable { return $this->dateOrigine; }
    public function setDateOrigine(?\DateTimeImmutable $d): static { $this->dateOrigine = $d?->setTime(0, 0); return $this; }
    public function getRaisonDecalage(): ?string { return $this->raisonDecalage; }
    public function setRaisonDecalage(?string $r): static { $this->raisonDecalage = $r !== null ? mb_substr($r, 0, 80) : null; return $this; }
    public function estDecalee(): bool { return $this->dateOrigine !== null && $this->raisonDecalage !== null && $this->dateOrigine->format('Y-m-d') !== $this->prevueLe->format('Y-m-d'); }
    public function getHeure(): ?string { return $this->heure; }
    public function getStatut(): string { return $this->statut; }
    public function annuler(): static { $this->statut = self::ANNULEE; return $this; }
    /** @return list<array{libelle: string, fait: bool}> */
    public function getEtapes(): array { return $this->etapes; }
    public function getMontantEstime(): ?int { return $this->montantEstime; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $n): static { $this->note = $n !== null && trim($n) !== '' ? trim($n) : null; return $this; }
    public function getFaitLe(): ?\DateTimeImmutable { return $this->faitLe; }
    public function getFaitPar(): ?User { return $this->faitPar; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
}
