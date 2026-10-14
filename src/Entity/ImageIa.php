<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Image générée par l'IA pour une demande de conception (maquette DC04).
 * Tenue en ligne seulement : le fichier est rangé hors de public/ et
 * n'est servi qu'à son client. L'image choisie part au local comme
 * fichier de la commande d'impression.
 */
#[ORM\Entity]
#[ORM\Table(name: 'image_ia')]
#[ORM\Index(name: 'idx_image_ia_statut', columns: ['statut', 'cree_le'])]
class ImageIa
{
    public const EN_COURS = 'en_cours';
    public const PRETE = 'prete';
    public const ECHOUEE = 'echouee';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DemandeConception $demande;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Clients $client;

    /** Image 1, 2… de la demande. */
    #[ORM\Column]
    private int $numero;

    /** Ce que le client a ajouté (« Modifier la consigne »). */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $consigne;

    /** Consigne envoyée au fournisseur, construite par le serveur. */
    #[ORM\Column(type: Types::TEXT)]
    private string $prompt;

    #[ORM\Column(length: 20)]
    private string $fournisseur;

    #[ORM\Column(length: 60)]
    private string $modele;

    #[ORM\Column(length: 10)]
    private string $statut = self::EN_COURS;

    /** Nom du fichier dans var/uploads/ia. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $fichier = null;

    #[ORM\Column(nullable: true)]
    private ?int $largeur = null;

    #[ORM\Column(nullable: true)]
    private ?int $hauteur = null;

    /** Débité du crédit IA, en F (remboursé si la génération échoue). */
    #[ORM\Column]
    private int $prix;

    #[ORM\Column]
    private \DateTimeImmutable $creeLe;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $termineeLe = null;

    /** Raison technique d'un échec (pour l'équipe, jamais montrée au client). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $erreur = null;

    public function __construct(DemandeConception $demande, int $numero, ?string $consigne, string $prompt, string $fournisseur, string $modele, int $prix, \DateTimeImmutable $creeLe)
    {
        $this->demande = $demande;
        $this->client = $demande->getClient() ?? throw new \LogicException('Demande sans client');
        $this->numero = $numero;
        $this->consigne = null !== $consigne && '' !== trim($consigne) ? mb_substr(trim($consigne), 0, 300) : null;
        $this->prompt = $prompt;
        $this->fournisseur = mb_substr($fournisseur, 0, 20);
        $this->modele = mb_substr($modele, 0, 60);
        $this->prix = $prix;
        $this->creeLe = $creeLe;
    }

    public function reussir(string $fichier, int $largeur, int $hauteur, \DateTimeImmutable $le): void
    {
        $this->statut = self::PRETE;
        $this->fichier = $fichier;
        $this->largeur = $largeur;
        $this->hauteur = $hauteur;
        $this->termineeLe = $le;
    }

    public function echouer(string $erreur, \DateTimeImmutable $le): void
    {
        $this->statut = self::ECHOUEE;
        $this->erreur = mb_substr($erreur, 0, 255);
        $this->termineeLe = $le;
    }

    public function getId(): ?int { return $this->id; }
    public function getDemande(): DemandeConception { return $this->demande; }
    public function getClient(): Clients { return $this->client; }
    public function getNumero(): int { return $this->numero; }
    public function getConsigne(): ?string { return $this->consigne; }
    public function getPrompt(): string { return $this->prompt; }
    public function getFournisseur(): string { return $this->fournisseur; }
    public function getModele(): string { return $this->modele; }
    public function getStatut(): string { return $this->statut; }
    public function estPrete(): bool { return self::PRETE === $this->statut; }
    public function getFichier(): ?string { return $this->fichier; }
    public function getLargeur(): ?int { return $this->largeur; }
    public function getHauteur(): ?int { return $this->hauteur; }
    public function getPrix(): int { return $this->prix; }
    public function getCreeLe(): \DateTimeImmutable { return $this->creeLe; }
    public function getTermineeLe(): ?\DateTimeImmutable { return $this->termineeLe; }
    public function getErreur(): ?string { return $this->erreur; }
}
