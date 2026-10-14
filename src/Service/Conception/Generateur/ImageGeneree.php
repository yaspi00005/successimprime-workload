<?php

namespace App\Service\Conception\Generateur;

/** Image rendue par un générateur, vérifiée (PNG, JPEG ou WebP lisible). */
final class ImageGeneree
{
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    private function __construct(
        public readonly string $contenu,
        public readonly string $type,
        public readonly int $largeur,
        public readonly int $hauteur,
    ) {
    }

    /**
     * @throws \RuntimeException si ce n'est pas une image attendue
     */
    public static function depuis(string $contenu): self
    {
        $infos = '' !== $contenu ? @getimagesizefromstring($contenu) : false;
        if (false === $infos || !isset(self::TYPES[$infos['mime'] ?? '']) || $infos[0] < 64 || $infos[1] < 64) {
            throw new \RuntimeException('Réponse du générateur qui n\'est pas une image PNG, JPEG ou WebP.');
        }

        return new self($contenu, $infos['mime'], (int) $infos[0], (int) $infos[1]);
    }

    public function extension(): string
    {
        return self::TYPES[$this->type];
    }
}
