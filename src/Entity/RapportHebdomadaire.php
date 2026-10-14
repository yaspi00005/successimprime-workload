<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace de l'envoi du rapport d'une semaine (app:rapport-hebdomadaire) :
 * une ligne par semaine ISO, posée avant les notifications, pour que la
 * tâche relancée (chaque heure par le cron, ou à la main) n'envoie
 * jamais deux fois le même rapport. Le contenu n'est pas gardé : chaque
 * personne recalcule sa version, selon ses droits, en ouvrant la page.
 * Table locale, non synchronisée.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_rapport_hebdomadaire_semaine', columns: ['semaine'])]
class RapportHebdomadaire
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** « 2026-W41 ». */
    #[ORM\Column(length: 8)]
    private string $semaine;

    /** Lundi de la semaine. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $debut;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeLe;

    #[ORM\Column]
    private int $destinataires = 0;

    #[ORM\Column]
    private int $courriels = 0;

    public function __construct(string $semaine, \DateTimeImmutable $debut, \DateTimeImmutable $envoyeLe)
    {
        $this->semaine = $semaine;
        $this->debut = $debut->setTime(0, 0);
        $this->envoyeLe = $envoyeLe;
    }

    public function getId(): ?int { return $this->id; }
    public function getSemaine(): string { return $this->semaine; }
    public function getDebut(): \DateTimeImmutable { return $this->debut; }
    public function getEnvoyeLe(): \DateTimeImmutable { return $this->envoyeLe; }
    public function getDestinataires(): int { return $this->destinataires; }
    public function getCourriels(): int { return $this->courriels; }

    public function terminer(int $destinataires, int $courriels): static
    {
        $this->destinataires = $destinataires;
        $this->courriels = $courriels;

        return $this;
    }
}
