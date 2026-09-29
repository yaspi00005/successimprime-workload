<?php

namespace App\Entity;

use App\Repository\EmployesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmployesRepository::class)]
class Employes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $nom = null;

    #[ORM\Column(length: 50)]
    private ?string $prenom = null;

    #[ORM\Column(length: 255)]
    private ?string $fonction = null;

    #[ORM\Column(length: 50)]
    private ?string $telephone = null;

    #[ORM\Column(length: 255)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $photos = null;

    #[ORM\Column(length: 255)]
    private ?string $CIN = null;

    #[ORM\Column]
    private ?int $salaires = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $dateNaissances = null;

    #[ORM\Column(length: 100)]
    private ?string $matricules = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $dateEmbauches = null;

    #[ORM\Column(length: 255)]
    private ?string $adresses = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    public function getFonction(): ?string
    {
        return $this->fonction;
    }

    public function setFonction(string $fonction): static
    {
        $this->fonction = $fonction;

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(string $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhotos(): ?string
    {
        return $this->photos;
    }

    public function setPhotos(string $photos): static
    {
        $this->photos = $photos;

        return $this;
    }

    public function getCIN(): ?string
    {
        return $this->CIN;
    }

    public function setCIN(string $CIN): static
    {
        $this->CIN = $CIN;

        return $this;
    }

    public function getSalaires(): ?int
    {
        return $this->salaires;
    }

    public function setSalaires(int $salaires): static
    {
        $this->salaires = $salaires;

        return $this;
    }

    public function getDateNaissances(): ?\DateTime
    {
        return $this->dateNaissances;
    }

    public function setDateNaissances(\DateTime $dateNaissances): static
    {
        $this->dateNaissances = $dateNaissances;

        return $this;
    }

    public function getMatricules(): ?string
    {
        return $this->matricules;
    }

    public function setMatricules(string $matricules): static
    {
        $this->matricules = $matricules;

        return $this;
    }

    public function getDateEmbauches(): ?\DateTime
    {
        return $this->dateEmbauches;
    }

    public function setDateEmbauches(\DateTime $dateEmbauches): static
    {
        $this->dateEmbauches = $dateEmbauches;

        return $this;
    }

    public function getAdresses(): ?string
    {
        return $this->adresses;
    }

    public function setAdresses(string $adresses): static
    {
        $this->adresses = $adresses;

        return $this;
    }

 

#[ORM\OneToOne(
    targetEntity: User::class,
    mappedBy: 'employe'
)]
private ?User $user = null;


public function getUser(): ?User
{
    return $this->user;
}

public function setUser(?User $user): static
{
    $this->user = $user;

    if ($user !== null && $user->getEmploye() !== $this) {
        $user->setEmploye($this);
    }

    return $this;
}


}
