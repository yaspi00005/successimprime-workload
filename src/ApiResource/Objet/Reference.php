<?php

namespace App\ApiResource\Objet;

/** Renvoi court vers une autre ressource (client d'une commande...). */
final class Reference
{
    public function __construct(
        public readonly int $id,
        public readonly string $nom,
    ) {
    }
}
