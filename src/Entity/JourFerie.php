<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fête mobile du Mali (Ramadan, Tabaski, Maouloud…), saisie chaque année
 * par l'admin quand la date est annoncée. Les fêtes fixes sont dans
 * App\Service\Planning\Calendrier.
 */
#[ORM\Entity]
class JourFerie
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, unique: true)]
    private \DateTimeImmutable $jour;

    #[ORM\Column(length: 80)]
    private string $libelle;

    public function __construct(\DateTimeImmutable $jour, string $libelle)
    {
        $this->jour = $jour->setTime(0, 0);
        $this->libelle = mb_substr(trim($libelle), 0, 80);
    }

    public function getId(): ?int { return $this->id; }
    public function getJour(): \DateTimeImmutable { return $this->jour; }
    public function getLibelle(): string { return $this->libelle; }
}
