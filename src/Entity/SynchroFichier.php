<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Fichier déjà envoyé au site (BAT montré au client), pour ne le renvoyer
 * que s'il change. « signature » : taille et date du fichier sur le disque,
 * pour ne recalculer l'empreinte que si besoin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synchro_fichier')]
class SynchroFichier
{
    #[ORM\Id]
    #[ORM\Column(name: 'public_id', length: 36)]
    private string $publicId;

    #[ORM\Column(length: 40)]
    private string $signature;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column]
    private \DateTimeImmutable $envoyeLe;

    public function __construct(string $publicId, string $signature, string $empreinte)
    {
        $this->publicId = $publicId;
        $this->signature = $signature;
        $this->empreinte = $empreinte;
        $this->envoyeLe = new \DateTimeImmutable();
    }

    public function getPublicId(): string { return $this->publicId; }
    public function getSignature(): string { return $this->signature; }
    public function getEmpreinte(): string { return $this->empreinte; }
    public function getEnvoyeLe(): \DateTimeImmutable { return $this->envoyeLe; }

    public function noterEnvoi(string $signature, string $empreinte): void
    {
        $this->signature = $signature;
        $this->empreinte = $empreinte;
        $this->envoyeLe = new \DateTimeImmutable();
    }
}
