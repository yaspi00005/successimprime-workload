<?php

namespace App\Security;

/**
 * Adresse de retour envoyée par un formulaire (« retour ») : seulement une
 * page de ce site. « //autre-site.com », « /\autre-site.com », une adresse
 * complète ou un caractère invisible (que le navigateur retire, ce qui
 * redonnerait « // ») renverraient ailleurs que sur le site.
 */
final class RetourInterne
{
    public static function chemin(string $retour): ?string
    {
        return preg_match('#^/(?![/\\\\])[A-Za-z0-9/._~%!$&\'()*+,;=:@?\#\[\]-]*$#', $retour) ? $retour : null;
    }
}
