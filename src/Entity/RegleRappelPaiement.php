<?php

namespace App\Entity;

use App\Repository\RegleRappelPaiementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Règle de relance automatique pour une commande impayée/partiellement
 * payée : tous les delaiJours jours depuis la date de la commande
 * (J+N, J+2N, J+3N...), envoie un rappel en utilisant le modèle de
 * message indiqué, et ce tant que la commande n'est pas soldée.
 * Plusieurs règles peuvent coexister (ex. tous les 7 jours, tous les
 * 30 jours). La commande app:executer-rappels-paiement (tâche
 * planifiée) est responsable de l'envoi réel — cet écran ne fait que
 * définir QUOI et QUAND (voir RappelPaiementService::estDue()).
 */
#[ORM\Entity(repositoryClass: RegleRappelPaiementRepository::class)]
#[ORM\Table(name: 'regle_rappel_paiement')]
#[ORM\HasLifecycleCallbacks]
class RegleRappelPaiement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Le nom de la règle est obligatoire.')]
    private string $nom = '';

    #[ORM\Column]
    #[Assert\Positive(message: 'Le délai doit être supérieur à zéro.')]
    private int $delaiJours = 7;

    #[ORM\ManyToOne(targetEntity: ModeleMessage::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Le modèle de message est obligatoire.')]
    private ?ModeleMessage $modeleMessage = null;

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

    public function getDelaiJours(): int
    {
        return $this->delaiJours;
    }

    public function setDelaiJours(int $delaiJours): static
    {
        $this->delaiJours = max(1, $delaiJours);

        return $this;
    }

    public function getModeleMessage(): ?ModeleMessage
    {
        return $this->modeleMessage;
    }

    public function setModeleMessage(?ModeleMessage $modeleMessage): static
    {
        $this->modeleMessage = $modeleMessage;

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
}
