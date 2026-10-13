<?php

namespace App\Entity;

use App\Repository\RoleUtilisateurRepository;
use App\Security\Droits;
use Doctrine\ORM\Mapping as ORM;

/**
 * Role de base d'un utilisateur et les droits qu'il donne (maquette
 * U03). Cocher ou decocher un droit ici change le role pour toutes les
 * personnes qui l'ont ; pour une seule personne, on passe par sa fiche.
 */
#[ORM\Entity(repositoryClass: RoleUtilisateurRepository::class)]
#[ORM\Table(name: 'role_utilisateur')]
class RoleUtilisateur
{
    public const CODE_ADMIN = 'ROLE_ADMIN';

    /**
     * Forme du menu des personnes qui ont ce role (maquettes R01 a R03) :
     * tous les modules en volets, un menu court a plat, ou le menu court
     * replie en icones pour laisser la place au travail.
     */
    public const MENU_VOLETS = 'volets';
    public const MENU_COURT = 'court';
    public const MENU_REPLIE = 'replie';

    public const MENUS = [
        self::MENU_VOLETS => 'Tous les modules en volets',
        self::MENU_COURT => 'Menu court',
        self::MENU_REPLIE => 'Menu replié en icônes',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Role Symfony porte par les personnes qui ont ce role
     * (ROLE_CAISSE_COMMANDE...), ROLE_PERSO_... pour un role cree ici.
     */
    #[ORM\Column(length: 60, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 80)]
    private string $nom = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $droits = [];

    /** Cree par l'application (non supprimable). */
    #[ORM\Column(options: ['default' => false])]
    private bool $systeme = false;

    #[ORM\Column(options: ['default' => 0])]
    private int $ordre = 0;

    #[ORM\Column(length: 10, options: ['default' => self::MENU_COURT])]
    private string $menu = self::MENU_COURT;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = trim($nom);

        return $this;
    }

    public function estAdmin(): bool
    {
        return self::CODE_ADMIN === $this->code;
    }

    /**
     * @return list<string>
     */
    public function getDroits(): array
    {
        return $this->estAdmin() ? Droits::sansRestriction() : Droits::filtrer($this->droits);
    }

    /**
     * @param array<mixed> $droits
     */
    public function setDroits(array $droits): static
    {
        $this->droits = Droits::filtrer($droits);

        return $this;
    }

    public function aLeDroit(string $droit): bool
    {
        return \in_array($droit, $this->getDroits(), true);
    }

    public function isSysteme(): bool
    {
        return $this->systeme;
    }

    public function setSysteme(bool $systeme): static
    {
        $this->systeme = $systeme;

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

    public function getMenu(): string
    {
        return $this->estAdmin() ? self::MENU_VOLETS : $this->menu;
    }

    public function setMenu(string $menu): static
    {
        $this->menu = isset(self::MENUS[$menu]) ? $menu : self::MENU_COURT;

        return $this;
    }
}
