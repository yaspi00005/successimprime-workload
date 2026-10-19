<?php

namespace App\Entity;

use App\Repository\FournisseursRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FournisseursRepository::class)]
class Fournisseurs
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $nom = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $telephone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adresse = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $actif = true;

    /*
     * Fiche fournisseur (maquette SU02, docs/achats.md) : la personne à
     * joindre, d'où vient la marchandise, ce qu'il fournit et à quelles
     * conditions.
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $contactNom = null;

    /*
     * Numéro du contact avec l'indicatif (+221 77…), pour le lien WhatsApp.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $contactTelephone = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $ville = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $pays = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fournit = null;

    /*
     * En clair, par exemple « virement, 50 % à la commande ».
     */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $conditionsPaiement = null;

    /*
     * Part payée à la commande, reprise sur chaque commande d'achat.
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $acomptePourcentage = 0;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $transport = null;

    /*
     * Délai annoncé par le fournisseur, en jours, entre la commande et
     * l'arrivée à Bamako. Le délai réel se calcule sur ses livraisons.
     */
    #[ORM\Column(nullable: true)]
    private ?int $delaiPromisJours = null;

    /*
     * Couleur de la pastille aux initiales (#RRGGBB). Vide : tirée du nom.
     */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $couleur = null;

    /**
     * @var Collection<int, Achats>
     */
    #[ORM\OneToMany(
        targetEntity: Achats::class,
        mappedBy: 'fournisseur'
    )]
    private Collection $achats;

    public function __construct()
    {
        $this->achats = new ArrayCollection();
    }

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

    public function getTelephone(): ?int
    {
        return $this->telephone;
    }

    public function setTelephone(?int $telephone): static
    {
        $this->telephone = $telephone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(?string $adresse): static
    {
        $this->adresse = $adresse;

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

    public function getContactNom(): ?string
    {
        return $this->contactNom;
    }

    public function setContactNom(?string $contactNom): static
    {
        $this->contactNom = self::texte($contactNom);

        return $this;
    }

    public function getContactTelephone(): ?string
    {
        return $this->contactTelephone;
    }

    public function setContactTelephone(?string $contactTelephone): static
    {
        $this->contactTelephone = self::texte($contactTelephone);

        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(?string $ville): static
    {
        $this->ville = self::texte($ville);

        return $this;
    }

    public function getPays(): ?string
    {
        return $this->pays;
    }

    public function setPays(?string $pays): static
    {
        $this->pays = self::texte($pays);

        return $this;
    }

    /**
     * « Bamako · local », « Dakar, Sénégal » : la ville, et le pays s'il
     * n'est pas le Mali.
     */
    public function getOrigine(): ?string
    {
        $pays = $this->pays !== null && mb_strtolower($this->pays) !== 'mali' ? $this->pays : null;

        if ($this->ville === null) {
            return $pays;
        }

        return $pays !== null ? $this->ville.', '.$pays : $this->ville.' · local';
    }

    public function getFournit(): ?string
    {
        return $this->fournit;
    }

    public function setFournit(?string $fournit): static
    {
        $this->fournit = self::texte($fournit);

        return $this;
    }

    public function getConditionsPaiement(): ?string
    {
        return $this->conditionsPaiement;
    }

    public function setConditionsPaiement(?string $conditionsPaiement): static
    {
        $this->conditionsPaiement = self::texte($conditionsPaiement);

        return $this;
    }

    public function getAcomptePourcentage(): int
    {
        return $this->acomptePourcentage;
    }

    public function setAcomptePourcentage(?int $acomptePourcentage): static
    {
        $this->acomptePourcentage = max(0, min(100, (int) $acomptePourcentage));

        return $this;
    }

    public function getTransport(): ?string
    {
        return $this->transport;
    }

    public function setTransport(?string $transport): static
    {
        $this->transport = self::texte($transport);

        return $this;
    }

    public function getDelaiPromisJours(): ?int
    {
        return $this->delaiPromisJours;
    }

    public function setDelaiPromisJours(?int $delaiPromisJours): static
    {
        $this->delaiPromisJours = $delaiPromisJours !== null ? max(0, $delaiPromisJours) : null;

        return $this;
    }

    public function getCouleur(): ?string
    {
        return $this->couleur;
    }

    public function setCouleur(?string $couleur): static
    {
        $couleur = self::texte($couleur);
        $this->couleur = $couleur !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur) ? strtoupper($couleur) : null;

        return $this;
    }

    /**
     * Nom sans la mention « (démo) » des données de démonstration.
     */
    public function getNomCourt(): string
    {
        return trim((string) preg_replace('/\s*\(démo\)\s*$/u', '', (string) $this->nom));
    }

    /**
     * Deux lettres pour la pastille : « Dakar Signs » donne « DS ».
     */
    public function getInitiales(): string
    {
        $mots = preg_split('/[\s&\-]+/u', $this->getNomCourt(), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($mots === []) {
            return '?';
        }

        $initiales = mb_substr($mots[0], 0, 1).(isset($mots[1]) ? mb_substr($mots[1], 0, 1) : mb_substr($mots[0], 1, 1));

        return mb_strtoupper($initiales);
    }

    /**
     * La couleur choisie, sinon une couleur tirée du nom (toujours la même).
     */
    public function getCouleurPastille(): string
    {
        if ($this->couleur !== null) {
            return $this->couleur;
        }

        $palette = ['#0F4590', '#E95B35', '#D6336C', '#0F766E', '#7B3FC4', '#1F9D55', '#B7791F', '#2563EB'];

        return $palette[abs(crc32($this->getNomCourt())) % \count($palette)];
    }

    /**
     * Numéro pour wa.me : chiffres seuls, avec l'indicatif (223 par défaut
     * pour un numéro malien à 8 chiffres).
     */
    public function getNumeroWhatsapp(): ?string
    {
        $numero = preg_replace('/\D+/', '', (string) ($this->contactTelephone ?? $this->telephone ?? ''));

        if ($numero === '' || $numero === null) {
            return null;
        }

        return \strlen($numero) === 8 ? '223'.$numero : ltrim($numero, '0');
    }

    /**
     * @return Collection<int, Achats>
     */
    public function getAchats(): Collection
    {
        return $this->achats;
    }

    public function __toString(): string
    {
        return $this->nom ?? 'Fournisseur';
    }

    private static function texte(?string $valeur): ?string
    {
        $valeur = $valeur !== null ? trim($valeur) : null;

        return $valeur !== '' ? $valeur : null;
    }
}
