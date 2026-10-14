<?php

namespace App\Entity;

use App\Repository\ReleveCompteurRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relevé mensuel des compteurs d'une machine (M02) : pour la Canon, les
 * compteurs A4 et A3 en couleur et en noir et blanc, tels qu'affichés par
 * la machine (totaux depuis l'installation) ; pour les autres, un seul
 * compteur dans l'unité de la machine. Le relevé d'un mois se saisit à
 * la fin du mois ou au début du suivant, avec la photo du compteur.
 *
 * Une page A3 compte pour 2 pages A4. Table locale, non synchronisée.
 */
#[ORM\Entity(repositoryClass: ReleveCompteurRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_releve_machine_mois', columns: ['machine_id', 'mois'])]
class ReleveCompteur
{
    /** Compteurs A4 / A3 : clé => libellé, coefficient A4 et couleur. */
    public const COMPTEURS = [
        'a4Couleur' => ['A4 couleur', 1, true],
        'a4Noir' => ['A4 noir et blanc', 1, false],
        'a3Couleur' => ['A3 couleur', 2, true],
        'a3Noir' => ['A3 noir et blanc', 2, false],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Machines $machine;

    /** Premier jour du mois relevé. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $mois;

    #[ORM\Column(nullable: true)]
    private ?int $a4Couleur = null;

    #[ORM\Column(nullable: true)]
    private ?int $a4Noir = null;

    #[ORM\Column(nullable: true)]
    private ?int $a3Couleur = null;

    #[ORM\Column(nullable: true)]
    private ?int $a3Noir = null;

    /** Compteur unique (machine sans compteurs A4 / A3). */
    #[ORM\Column(nullable: true)]
    private ?float $compteur = null;

    /** Photo du compteur, hors du dossier public. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column]
    private \DateTimeImmutable $saisiLe;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $saisiPar = null;

    public function __construct(Machines $machine, \DateTimeImmutable $mois)
    {
        $this->machine = $machine;
        $this->mois = $mois->modify('first day of this month')->setTime(0, 0);
        $this->saisiLe = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMachine(): Machines { return $this->machine; }
    public function getMois(): \DateTimeImmutable { return $this->mois; }
    public function getA4Couleur(): ?int { return $this->a4Couleur; }
    public function getA4Noir(): ?int { return $this->a4Noir; }
    public function getA3Couleur(): ?int { return $this->a3Couleur; }
    public function getA3Noir(): ?int { return $this->a3Noir; }
    public function getCompteur(): ?float { return $this->compteur; }
    public function getPhoto(): ?string { return $this->photo; }
    public function getSaisiLe(): \DateTimeImmutable { return $this->saisiLe; }
    public function getSaisiPar(): ?User { return $this->saisiPar; }

    /** @param array<string, ?int> $compteurs a4Couleur, a4Noir, a3Couleur, a3Noir */
    public function saisirA4A3(array $compteurs): static
    {
        foreach (array_keys(self::COMPTEURS) as $cle) {
            $this->{$cle} = isset($compteurs[$cle]) ? max(0, (int) $compteurs[$cle]) : null;
        }

        return $this;
    }

    public function saisirCompteur(?float $valeur): static
    {
        $this->compteur = null !== $valeur ? max(0.0, $valeur) : null;

        return $this;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function signer(?User $par, \DateTimeImmutable $le): static
    {
        $this->saisiPar = $par;
        $this->saisiLe = $le;

        return $this;
    }

    /** Valeur d'un compteur A4 / A3 par sa clé. */
    public function valeur(string $cle): ?int
    {
        return isset(self::COMPTEURS[$cle]) ? $this->{$cle} : null;
    }

    /**
     * Total en unité d'usage : pages A4-équivalent (A3 = 2 A4) pour une
     * machine à compteurs A4 / A3, sinon le compteur unique.
     */
    public function equivalent(): ?float
    {
        if (null !== $this->compteur) {
            return $this->compteur;
        }
        $total = null;
        foreach (self::COMPTEURS as $cle => [, $coefficient]) {
            if (null !== $this->{$cle}) {
                $total = ($total ?? 0) + $this->{$cle} * $coefficient;
            }
        }

        return null !== $total ? (float) $total : null;
    }
}
