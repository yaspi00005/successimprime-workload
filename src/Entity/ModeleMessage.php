<?php

namespace App\Entity;

use App\Repository\ModeleMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Modèle de message réutilisable (campagne ou rappel de paiement),
 * pour un canal donné (SMS, email ou WhatsApp). Le contenu peut
 * utiliser des variables du type {{nom}}, {{montant}}, {{reste}},
 * {{numeroCommande}}, remplacées au moment de l'envoi (voir
 * CampagneService et RappelPaiementService).
 */
#[ORM\Entity(repositoryClass: ModeleMessageRepository::class)]
#[ORM\Table(name: 'modele_message')]
#[ORM\HasLifecycleCallbacks]
class ModeleMessage
{
    public const CANAL_SMS = 'sms';
    public const CANAL_EMAIL = 'email';
    public const CANAL_WHATSAPP = 'whatsapp';

    public const CANAUX = [
        self::CANAL_SMS,
        self::CANAL_EMAIL,
        self::CANAL_WHATSAPP,
    ];

    public const CANAUX_LABELS = [
        self::CANAL_SMS => 'SMS',
        self::CANAL_EMAIL => 'Email',
        self::CANAL_WHATSAPP => 'WhatsApp',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom du modèle est obligatoire.')]
    private string $nom = '';

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::CANAUX, message: 'Le canal sélectionné est invalide.')]
    private string $canal = self::CANAL_SMS;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sujet = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Le contenu du message est obligatoire.')]
    private string $contenu = '';

    #[ORM\Column]
    private bool $actif = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateCreation = null;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        if ($this->dateCreation === null) {
            $this->dateCreation = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function getCanalLabel(): string
    {
        return self::CANAUX_LABELS[$this->canal] ?? $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = $canal;

        if ($this->canal !== self::CANAL_EMAIL) {
            $this->sujet = null;
        }

        return $this;
    }

    public function getSujet(): ?string
    {
        return $this->sujet;
    }

    public function setSujet(?string $sujet): static
    {
        $this->sujet = $sujet !== null && trim($sujet) !== '' ? trim($sujet) : null;

        return $this;
    }

    public function getContenu(): string
    {
        return $this->contenu;
    }

    public function setContenu(string $contenu): static
    {
        $this->contenu = $contenu;

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

    public function getDateCreation(): ?\DateTimeImmutable
    {
        return $this->dateCreation;
    }

    /**
     * Remplace les variables {{cle}} du contenu (et du sujet, pour un
     * email) par les valeurs fournies. Une variable sans valeur
     * correspondante est laissée telle quelle, pour rester visible
     * et signaler l'oubli plutôt que de disparaître silencieusement.
     *
     * @param array<string, string> $variables
     */
    public function rendreContenu(array $variables): string
    {
        return $this->remplacerVariables($this->contenu, $variables);
    }

    /**
     * @param array<string, string> $variables
     */
    public function rendreSujet(array $variables): ?string
    {
        return $this->sujet !== null
            ? $this->remplacerVariables($this->sujet, $variables)
            : null;
    }

    /**
     * @param array<string, string> $variables
     */
    private function remplacerVariables(string $texte, array $variables): string
    {
        $recherche = [];
        $remplacement = [];

        foreach ($variables as $cle => $valeur) {
            $recherche[] = '{{' . $cle . '}}';
            $remplacement[] = $valeur;
        }

        return str_replace($recherche, $remplacement, $texte);
    }

    public function __toString(): string
    {
        return $this->nom !== '' ? $this->nom : 'Nouveau modèle';
    }
}
