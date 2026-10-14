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

    /** Motifs de refus renvoyés par le site avec la réponse 401. */
    public const REFUS_ENTETES = 'entetes_absents';
    public const REFUS_HEURE = 'heure';
    public const REFUS_SIGNATURE = 'signature';

    public static function calculer(string $cle, string $methode, string $chemin, int $horodatage, string $corps): string
    {
        return hash_hmac('sha256', strtoupper($methode)."\n".$chemin."\n".$horodatage."\n".hash('sha256', $corps), $cle);
    }

    public static function verifier(string $cle, string $methode, string $chemin, string $horodatage, string $corps, string $signature, int $maintenant): bool
    {
        return self::motifRefus($cle, $methode, $chemin, $horodatage, $corps, $signature, $maintenant) === null;
    }

    /**
     * Pourquoi une requête est refusée, null si elle est acceptée. Le motif
     * aide à régler l'installation et ne dit rien de la clé.
     *
     * @return self::REFUS_*|null
     */
    public static function motifRefus(string $cle, string $methode, string $chemin, string $horodatage, string $corps, string $signature, int $maintenant): ?string
    {
        if (\strlen($cle) < self::LONGUEUR_CLE_MIN) {
            return self::REFUS_SIGNATURE;
        }
        if ($horodatage === '' || $signature === '') {
            return self::REFUS_ENTETES;
        }
        if (!ctype_digit($horodatage) || abs($maintenant - (int) $horodatage) > self::ECART_MAX_SECONDES) {
            return self::REFUS_HEURE;
        }

        return hash_equals(self::calculer($cle, $methode, $chemin, (int) $horodatage, $corps), $signature) ? null : self::REFUS_SIGNATURE;
    }

    /** Début de l'empreinte SHA-256 de la clé : pour comparer les deux machines sans montrer la clé. */
    public static function empreinte(string $cle): string
    {
        return substr(hash('sha256', $cle), 0, 8);
    }

    public static function cleValable(string $cle): bool
    {
        return \strlen($cle) >= self::LONGUEUR_CLE_MIN;
    }
}
