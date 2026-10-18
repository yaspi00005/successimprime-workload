<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ce que l'atelier de production ajoute à une machine (docs/atelier-production.md) :
 * opérateurs affectés, niveaux d'encre saisis à la main, ordre de la file
 * choisi par le chef d'atelier, date de pose des têtes. Table à part pour ne
 * pas toucher la fiche technique (Machines) ni l'immobilisation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'atelier_machine')]
class AtelierMachine
{
    /** Couleurs d'encre proposées à la saisie, dans l'ordre d'affichage. */
    public const ENCRES = ['C' => 'Cyan', 'M' => 'Magenta', 'J' => 'Jaune', 'N' => 'Noir', 'B' => 'Blanc'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Machines $machine;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'atelier_machine_operateur')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $operateurs;

    /** Niveaux en % par couleur (C, M, J, N, B), saisis par l'opérateur. Null : encre non suivie. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $niveauxEncre = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $niveauxEncreLe = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $niveauxEncrePar = null;

    /** Identifiants des ordres de production dans l'ordre choisi (file d'attente). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $ordreFile = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $tetesPoseesLe = null;

    public function __construct(Machines $machine)
    {
        $this->machine = $machine;
        $this->operateurs = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMachine(): Machines
    {
        return $this->machine;
    }

    /** @return Collection<int, User> */
    public function getOperateurs(): Collection
    {
        return $this->operateurs;
    }

    public function ajouterOperateur(User $user): static
    {
        if (!$this->operateurs->contains($user)) {
            $this->operateurs->add($user);
        }

        return $this;
    }

    public function retirerOperateur(User $user): static
    {
        $this->operateurs->removeElement($user);

        return $this;
    }

    /** @return array<string, int> */
    public function getNiveauxEncre(): array
    {
        return $this->niveauxEncre ?? [];
    }

    /**
     * @param array<string, mixed> $niveaux couleur => %, les valeurs vides sont ignorées
     */
    public function saisirNiveauxEncre(array $niveaux, User $par, \DateTimeImmutable $le): static
    {
        $propres = [];
        foreach (self::ENCRES as $code => $libelle) {
            if (isset($niveaux[$code]) && is_numeric($niveaux[$code])) {
                $propres[$code] = max(0, min(100, (int) round((float) $niveaux[$code])));
            }
        }
        $this->niveauxEncre = [] === $propres ? null : $propres;
        $this->niveauxEncreLe = $le;
        $this->niveauxEncrePar = $par;

        return $this;
    }

    public function getNiveauxEncreLe(): ?\DateTimeImmutable
    {
        return $this->niveauxEncreLe;
    }

    public function getNiveauxEncrePar(): ?User
    {
        return $this->niveauxEncrePar;
    }

    /** @return list<int> */
    public function getOrdreFile(): array
    {
        return array_values(array_map('intval', $this->ordreFile ?? []));
    }

    /** @param list<int> $ids */
    public function setOrdreFile(array $ids): static
    {
        $this->ordreFile = [] === $ids ? null : array_values(array_unique(array_map('intval', $ids)));

        return $this;
    }

    public function getTetesPoseesLe(): ?\DateTimeImmutable
    {
        return $this->tetesPoseesLe;
    }

    public function setTetesPoseesLe(?\DateTimeImmutable $le): static
    {
        $this->tetesPoseesLe = $le;

        return $this;
    }
}
