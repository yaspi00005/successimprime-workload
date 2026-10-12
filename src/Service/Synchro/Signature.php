<?php

namespace App\Service\Synchro;

/**
 * Signature HMAC-SHA256 des échanges entre l'agent local et le site.
 *
 * La clé (SYNC_CLE) est la même des deux côtés, dans .env.local seulement.
 * La signature couvre la méthode, le chemin, l'heure et le contenu : une
 * requête modifiée ou rejouée plus de 5 minutes après est refusée. Les
 * réponses du site sont signées de la même façon.
 */
final class Signature
{
    public const ENTETE = 'X-SI-Signature';
    public const ENTETE_HEURE = 'X-SI-Horodatage';
    public const ECART_MAX_SECONDES = 300;
    public const LONGUEUR_CLE_MIN = 32;

    public static function calculer(string $cle, string $methode, string $chemin, int $horodatage, string $corps): string
    {
        return hash_hmac('sha256', strtoupper($methode)."\n".$chemin."\n".$horodatage."\n".hash('sha256', $corps), $cle);
    }

    public static function verifier(string $cle, string $methode, string $chemin, string $horodatage, string $corps, string $signature, int $maintenant): bool
    {
        if (\strlen($cle) < self::LONGUEUR_CLE_MIN || !ctype_digit($horodatage) || abs($maintenant - (int) $horodatage) > self::ECART_MAX_SECONDES) {
            return false;
        }

        return hash_equals(self::calculer($cle, $methode, $chemin, (int) $horodatage, $corps), $signature);
    }

    public static function cleValable(string $cle): bool
    {
        return \strlen($cle) >= self::LONGUEUR_CLE_MIN;
    }
}
