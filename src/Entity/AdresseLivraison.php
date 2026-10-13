<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Adresse de livraison gardée par le client dans son espace (maquette
 * 19). Elle reste sur le site : la commande en reçoit une copie en
 * texte (Commandes::adresseLivraison), qui part au local.
 */
#[ORM\Entity]
#[ORM\Table(name: 'adresse_livraison')]
class AdresseLivraison
{
    public const MAX_PAR_CLIENT = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Clients $client = null;

    /** « Boutique ACI 2000 », « Entrepôt »... */
    #[ORM\Column(length: 60)]
    private string $libelle = '';

    #[ORM\Column(length: 255)]
    private string $adresse = '';

    /** Point de repère pour le livreur. */
    #[ORM\Column(length: 160, nullable: true)]
    private ?string $repere = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $contact = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $telephone = null;

    #[ORM\Column]
    private bool $parDefaut = false;

    #[ORM\Column]
    private bool $actif = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Clients
    {
        return $this->client;
    }

    public function setClient(Clients $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = mb_substr(trim($libelle), 0, 60);

        return $this;
    }

    public function getAdresse(): string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): static
    {
        $this->adresse = mb_substr(trim($adresse), 0, 255);

        return $this;
    }

    public function getRepere(): ?string
    {
        return $this->repere;
    }

    public function setRepere(?string $repere): static
    {
        $this->repere = self::vide($repere, 160);

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): static
    {
        $this->contact = self::vide($contact, 80);

        return $this;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }

    public function setTelephone(?string $telephone): static
    {
        $this->telephone = self::vide(null !== $telephone ? preg_replace('/[^\d+ ]/', '', $telephone) : null, 20);

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

    /** Texte copié dans la commande, lu par le livreur. */
    public function texte(): string
    {
        $lignes = [$this->libelle.' : '.$this->adresse];
        if (null !== $this->repere) {
            $lignes[] = 'Repère : '.$this->repere;
        }
        if (null !== $this->contact || null !== $this->telephone) {
            $lignes[] = 'Contact : '.trim(($this->contact ?? '').' '.($this->telephone ?? ''));
        }

        return implode("\n", $lignes);
    }

    private static function vide(?string $texte, int $max): ?string
    {
        $texte = null !== $texte ? mb_substr(trim($texte), 0, $max) : '';

        return '' === $texte ? null : $texte;
    }
}
