<?php

namespace App\Entity;

use App\Repository\RappelPaiementEnvoyeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace qu'une règle de rappel de paiement a bien été ENVOYÉE (avec
 * succès) pour une commande donnée. Une règle "tous les N jours"
 * doit se redéclencher indéfiniment tant que la commande reste non
 * soldée (J+N, J+2N, J+3N...) : il peut donc exister PLUSIEURS lignes
 * pour un même couple (commande, règle) -- pas de contrainte
 * d'unicité ici. RappelPaiementService vérifie seulement qu'aucun
 * envoi n'a déjà eu lieu AUJOURD'HUI pour ce couple, pour ne pas
 * doubler un envoi si la tâche planifiée tournait deux fois le même
 * jour.
 *
 * Un échec d'envoi (API SMS/WhatsApp indisponible...) ne crée
 * volontairement AUCUNE ligne ici : comme pour les décaissements
 * automatiques (voir DecaissementRecurrentService), un échec ne doit
 * pas empêcher la tentative suivante, seulement notifier les admins.
 */
#[ORM\Entity(repositoryClass: RappelPaiementEnvoyeRepository::class)]
#[ORM\Table(name: 'rappel_paiement_envoye')]
#[ORM\Index(name: 'idx_rappel_commande_regle', columns: ['commande_id', 'regle_id'])]
#[ORM\HasLifecycleCallbacks]
class RappelPaiementEnvoye
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Commandes::class)]
    #[ORM\JoinColumn(name: 'commande_id', nullable: false, onDelete: 'CASCADE')]
    private ?Commandes $commande = null;

    #[ORM\ManyToOne(targetEntity: RegleRappelPaiement::class)]
    #[ORM\JoinColumn(name: 'regle_id', nullable: false, onDelete: 'CASCADE')]
    private ?RegleRappelPaiement $regle = null;

    #[ORM\Column(length: 20)]
    private string $canal = ModeleMessage::CANAL_SMS;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $dateEnvoi = null;

    #[ORM\PrePersist]
    public function initialiser(): void
    {
        if ($this->dateEnvoi === null) {
            $this->dateEnvoi = new \DateTimeImmutable();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCommande(): ?Commandes
    {
        return $this->commande;
    }

    public function setCommande(?Commandes $commande): static
    {
        $this->commande = $commande;

        return $this;
    }

    public function getRegle(): ?RegleRappelPaiement
    {
        return $this->regle;
    }

    public function setRegle(?RegleRappelPaiement $regle): static
    {
        $this->regle = $regle;

        return $this;
    }

    public function getCanal(): string
    {
        return $this->canal;
    }

    public function setCanal(string $canal): static
    {
        $this->canal = $canal;

        return $this;
    }

    public function getDateEnvoi(): ?\DateTimeImmutable
    {
        return $this->dateEnvoi;
    }
}
