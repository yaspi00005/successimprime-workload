<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;

/**
 * Jeton CSRF d'un enregistrement envoyé en JSON par une page de
 * l'administration (docs/securite.md, audit D6) : en-tête X-CSRF-TOKEN,
 * comme les autres appels du navigateur, ou champ « _token » du corps,
 * comme les fenêtres des réglages du catalogue. Sans jeton, un autre site
 * pourrait faire enregistrer une modification à un administrateur connecté.
 */
final class JetonJson
{
    public const ENTETE = 'X-CSRF-TOKEN';

    public const MESSAGE = 'Jeton de sécurité absent ou périmé : rechargez la page puis recommencez.';

    public static function lire(Request $requete, mixed $donnees): string
    {
        $entete = (string) $requete->headers->get(self::ENTETE, '');
        if ('' !== $entete) {
            return $entete;
        }

        return \is_array($donnees) && \is_string($donnees['_token'] ?? null) ? $donnees['_token'] : '';
    }
}
