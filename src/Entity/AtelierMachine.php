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
 *
 * Production au rouleau (docs/production-rouleau.md, maquette PD01) : le
 * dossier de la machine sur le serveur de l'atelier (« SOLVANT-320 »), le
 * type de tête, les usages, le miroir par défaut et les profils
 * d'impression par support (ProfilsImpression).
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

    /** Nom du dossier de la machine sous la racine de production (« SOLVANT-320 ») : A-Z, 0-9 et tiret. */
    #[ORM\Column(length: 40, nullable: true)]
    private ?string $codeDossier = null;

    /** « 2 têtes i3200 ». */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $teteImpression = null;

    /** « bâches > 1,70 m, kakémonos, grands volumes ». */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $usages = null;

    /** Impression en miroir par défaut (DTF, transfert). */
    #[ORM\Column(options: ['default' => false])]
    private bool $miroir = false;

    /**
     * Profils d'impression par support (ProfilsImpression::normaliser).
     *
     * @var list<array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $profils = null;

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

    public function getCodeDossier(): ?string
    {
        return $this->codeDossier;
    }

    /** « solvant 320 » → « SOLVANT-320 » ; vide : aucun dossier. */
    public static function nettoyerCodeDossier(?string $code): ?string
    {
        $code = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $code)), '-'));

        return '' === $code ? null : substr($code, 0, 40);
    }

    public function setCodeDossier(?string $code): static
    {
        $this->codeDossier = self::nettoyerCodeDossier($code);

        return $this;
    }

    public function getTeteImpression(): ?string
    {
        return $this->teteImpression;
    }

    public function setTeteImpression(?string $tete): static
    {
        $tete = trim((string) $tete);
        $this->teteImpression = '' === $tete ? null : mb_substr($tete, 0, 80);

        return $this;
    }

    public function getUsages(): ?string
    {
        return $this->usages;
    }

    public function setUsages(?string $usages): static
    {
        $usages = trim((string) $usages);
        $this->usages = '' === $usages ? null : mb_substr($usages, 0, 160);

        return $this;
    }

    public function isMiroir(): bool
    {
        return $this->miroir;
    }

    public function setMiroir(bool $miroir): static
    {
        $this->miroir = $miroir;

        return $this;
    }

    /** @return list<array{support: string, profilIcc: string, mode: string, vitesseM2h: ?float, encreMlM2: ?float, chauffeC: ?int, sechageMin: ?int, miroir: bool}> */
    public function getProfils(): array
    {
        return \App\Service\Production\ProfilsImpression::normaliser($this->profils ?? []);
    }

    /** @param array<mixed> $profils */
    public function setProfils(array $profils): static
    {
        $propres = \App\Service\Production\ProfilsImpression::normaliser($profils);
        $this->profils = [] === $propres ? null : $propres;

        return $this;
    }
}
