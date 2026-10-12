<?php

namespace App\Service\Sauvegarde;

/**
 * Chiffrement des sauvegardes envoyées hors de la boutique (libsodium,
 * XChaCha20-Poly1305 en flux) : un fichier modifié, tronqué ou ouvert avec
 * une autre clé est refusé. Lu et écrit par morceaux de 1 Mo, quelle que
 * soit la taille de l'archive.
 *
 * La clé (SAUVEGARDE_CLE_HORS_SITE) reste au local : sans elle, la copie
 * hors site est illisible, y compris pour l'hébergeur.
 */
final class Chiffrement
{
    private const MARQUE = "SIHS1\n";
    private const MORCEAU = 1 << 20;

    public static function cleValable(string $cle): bool
    {
        return (bool) preg_match('/^[0-9a-f]{64}$/', $cle);
    }

    public static function nouvelleCle(): string
    {
        return bin2hex(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public static function chiffrer(string $source, string $cible, string $cle): void
    {
        $entree = self::ouvrir($source, 'rb');
        $sortie = self::ouvrir($cible, 'xb');
        try {
            [$etat, $entete] = sodium_crypto_secretstream_xchacha20poly1305_init_push(self::cleBinaire($cle));
            fwrite($sortie, self::MARQUE.$entete);
            $taille = (int) filesize($source);
            $lu = 0;
            do {
                $morceau = (string) fread($entree, self::MORCEAU);
                $lu += \strlen($morceau);
                $fin = $lu >= $taille;
                fwrite($sortie, sodium_crypto_secretstream_xchacha20poly1305_push($etat, $morceau, '', $fin
                    ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                    : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE));
            } while (!$fin);
        } finally {
            fclose($entree);
            fclose($sortie);
        }
    }

    public static function dechiffrer(string $source, string $cible, string $cle): void
    {
        $entree = self::ouvrir($source, 'rb');
        $sortie = self::ouvrir($cible, 'xb');
        $ok = false;
        try {
            if (fread($entree, \strlen(self::MARQUE)) !== self::MARQUE) {
                throw new \RuntimeException('Ce fichier n\'est pas une sauvegarde chiffrée de Succès Imprim.');
            }
            $entete = (string) fread($entree, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $etat = sodium_crypto_secretstream_xchacha20poly1305_init_pull($entete, self::cleBinaire($cle));
            $bloc = self::MORCEAU + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            do {
                $chiffre = (string) fread($entree, $bloc);
                $resultat = $chiffre === '' ? false : sodium_crypto_secretstream_xchacha20poly1305_pull($etat, $chiffre);
                if ($resultat === false) {
                    throw new \RuntimeException('Déchiffrement impossible : mauvaise clé, ou fichier abîmé ou incomplet.');
                }
                [$clair, $tag] = $resultat;
                fwrite($sortie, $clair);
            } while ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
            if (fread($entree, 1) !== '') {
                throw new \RuntimeException('Données en trop après la fin : fichier refusé.');
            }
            $ok = true;
        } finally {
            fclose($entree);
            fclose($sortie);
            if (!$ok) {
                @unlink($cible);
            }
        }
    }

    private static function cleBinaire(string $cle): string
    {
        if (!self::cleValable($cle)) {
            throw new \InvalidArgumentException('Clé de chiffrement invalide (64 caractères hexadécimaux attendus).');
        }

        return (string) hex2bin($cle);
    }

    /** @return resource */
    private static function ouvrir(string $chemin, string $mode)
    {
        $f = @fopen($chemin, $mode);
        if ($f === false) {
            throw new \RuntimeException(($mode === 'xb' ? 'Impossible de créer ' : 'Impossible de lire ').$chemin);
        }

        return $f;
    }
}
