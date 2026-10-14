<?php

namespace App\Entity;

use App\Entity\Trait\PublicIdTrait;
use App\Repository\SocieteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Société au sens juridique (docs/societes.md) : une raison sociale, un
 * RCCM, un NIF, une comptabilité. Madial Group SARL (Succès Imprim est son
 * nom commercial) est la société principale ; DREPA TECHNOLOGIE a la
 * sienne ; la boutique de consommables aura la sienne.
 *
 * Chaque entreprise émettrice (en-tête des documents) appartient à une
 * société, et chaque document qui touche à l'argent ou au stock aussi
 * (AppartientASociete). Le code court servira à la numérotation
 * (FAC-IMP-…, FAC-BTQ-…) quand elle passera par la société.
 */
#[ORM\Entity(repositoryClass: SocieteRepository::class)]
#[ORM\Table(name: 'societe')]
class Societe
{
    use PublicIdTrait;

    /**
     * Identifiant de la société principale, créée la première par la
     * migration : valeur par défaut des colonnes societe_id, pour qu'un
     * INSERT écrit à la main (fichier de démo, import) reste valable.
     */
    public const ID_PRINCIPALE = 1;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /*
     * Code court en majuscules : IMP, DREPA, BTQ…
     */
    #[ORM\Column(length: 10, unique: true)]
    private string $code = '';

    #[ORM\Column(length: 150)]
    private string $raisonSociale = '';

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $rccm = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $nif = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adresse = null;

    /*
     * Société des documents qui n'ont ni émetteur ni document lié
     * (SocieteCourante::parDefaut).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $parDefaut = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    public function __construct()
    {
        $this->initialiserPublicId();
    }

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
        $this->code = mb_substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)), 0, 10);

        return $this;
    }

    public function getRaisonSociale(): string
    {
        return $this->raisonSociale;
    }

    public function setRaisonSociale(string $raisonSociale): static
    {
        $this->raisonSociale = trim($raisonSociale);

        return $this;
    }

    public function getRccm(): ?string
    {
        return $this->rccm;
    }

    public function setRccm(?string $rccm): static
    {
        $this->rccm = self::texte($rccm);

        return $this;
    }

    public function getNif(): ?string
    {
        return $this->nif;
    }

    public function setNif(?string $nif): static
    {
        $this->nif = self::texte($nif);

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = self::texte($adresse);

        return $this;
    }

    public function isParDefaut(): bool
    {
        return $this->parDefaut;
    }

    public function setParDefaut(bool $parDefaut): static
    {
        $this->parDefaut = $parDefaut;

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

    /**
     * « Madial Group SARL (IMP) » dans les listes de choix.
     */
    public function getLibelle(): string
    {
        return $this->raisonSociale.' ('.$this->code.')';
    }

    private static function texte(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}
