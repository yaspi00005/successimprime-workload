<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Identifiant public stable (UUID v7), cree des l'origine de l'enregistrement.
 * Il sert a la synchronisation entre l'application locale et l'application en
 * ligne : un meme enregistrement garde le meme identifiant des deux cotes, et
 * un envoi rejoue ne cree pas de doublon.
 */
trait PublicIdTrait
{
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $publicId;

    public function getPublicId(): Uuid
    {
        return $this->publicId;
    }

    private function initialiserPublicId(): void
    {
        $this->publicId = Uuid::v7();
    }
}
