<?php

namespace App\Service\MessagerieInterne;

use App\Service\Parametres\Parametres;

/**
 * Réglages de la messagerie de l'équipe (Paramètres › Messagerie de
 * l'équipe, docs/messagerie-interne.md). Par défaut : la direction lit tous
 * les groupes sans en être membre, jamais les messages privés ; fichiers
 * envoyés jusqu'à 50 Mo, au-delà un lien vers le fichier du serveur.
 */
class ReglesMessagerieInterne
{
    public const TUILE = 'messagerie_interne';

    public function __construct(private readonly Parametres $parametres)
    {
    }

    public function directionLitGroupes(): bool
    {
        return false !== $this->parametres->valeur(self::TUILE, 'direction_lit_groupes');
    }

    /** Taille maximale d'un fichier envoyé, en octets. */
    public function tailleMax(): int
    {
        $mo = (int) ($this->parametres->valeur(self::TUILE, 'taille_max_mo') ?? 50);

        return max(1, min(200, $mo ?: 50)) * 1024 * 1024;
    }

    /** Phrase montrée à l'équipe dans la messagerie (règle de lecture en clair). */
    public function phraseConfidentialite(): string
    {
        return $this->directionLitGroupes()
            ? 'La direction peut lire les groupes, pas les messages privés.'
            : 'Seuls les membres lisent un groupe. Les messages privés ne sont lus que par les deux personnes.';
    }
}
