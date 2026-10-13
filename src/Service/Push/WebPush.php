<?php

namespace App\Service\Push;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Notifications « push » du navigateur (écran verrouillé du téléphone),
 * sans bibliothèque externe : chiffrement du message (RFC 8291,
 * aes128gcm) et signature VAPID (RFC 8292) avec OpenSSL.
 *
 * Les clés VAPID se créent avec `php bin/console app:push:cles` et se
 * mettent dans .env.local sur le serveur, jamais dans le dépôt. Sans
 * clés, rien n'est envoyé.
 */
final class WebPush
{
    private const COURBE = 'prime256v1';
    /** En-tête DER d'une clé publique P-256 (SubjectPublicKeyInfo). */
    private const DER_PUBLIQUE = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $clePublique,
        private readonly string $clePrivee,
        private readonly string $sujet,
    ) {
    }

    public function estConfigure(): bool
    {
        return $this->clePublique !== '' && $this->clePrivee !== '';
    }

    public function getClePublique(): string
    {
        return $this->clePublique;
    }

    /**
     * Envoie sans attendre la réponse : l'appelant lit le statut plus tard
     * (404 ou 410 : l'abonnement n'existe plus).
     *
     * @param array<string, mixed> $contenu
     */
    public function envoyer(DestinatairePush $a, array $contenu, int $duree = 86400): ResponseInterface
    {
        $corps = self::chiffrer((string) json_encode($contenu, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), self::b64d($a->getClePublique()), self::b64d($a->getSecret()));
        $origine = parse_url($a->getAdresse(), \PHP_URL_SCHEME).'://'.parse_url($a->getAdresse(), \PHP_URL_HOST);

        return $this->http->request('POST', $a->getAdresse(), [
            'headers' => [
                'Authorization' => 'vapid t='.$this->jeton($origine, time() + 12 * 3600).', k='.$this->clePublique,
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string) $duree,
                'Urgency' => 'normal',
            ],
            'body' => $corps,
            'timeout' => 5,
        ]);
    }

    /** Jeton VAPID signé (JWT ES256) pour le service de notification. */
    public function jeton(string $origine, int $expire): string
    {
        $entete = self::b64e((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $charge = self::b64e((string) json_encode(['aud' => $origine, 'exp' => $expire, 'sub' => $this->sujet], \JSON_UNESCAPED_SLASHES));
        $prive = self::clePrivee(self::b64d($this->clePrivee), self::b64d($this->clePublique));
        openssl_sign($entete.'.'.$charge, $der, $prive, \OPENSSL_ALGO_SHA256);

        return $entete.'.'.$charge.'.'.self::b64e(self::signatureBrute($der));
    }

    /**
     * Corps chiffré « aes128gcm » (RFC 8291). La clé éphémère et le sel ne
     * se passent que pour les tests.
     *
     * @param array{0: string, 1: string}|null $ephemere clé privée et publique brutes
     */
    public static function chiffrer(string $message, string $clientPublique, string $secret, ?array $ephemere = null, ?string $sel = null): string
    {
        if ($ephemere === null) {
            $cle = openssl_pkey_new(['curve_name' => self::COURBE, 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
            $ephemere = self::brut($cle);
        }
        [$prive, $publique] = $ephemere;
        $sel ??= random_bytes(16);

        $partage = openssl_pkey_derive(self::clePubliqueOpenssl($clientPublique), self::clePrivee($prive, $publique), 32);
        if ($partage === false) {
            throw new \RuntimeException('Clé du navigateur invalide.');
        }
        $ikm = hash_hkdf('sha256', $partage, 32, "WebPush: info\0".$clientPublique.$publique, $secret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sel);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sel);
        $chiffre = openssl_encrypt($message."\x02", 'aes-128-gcm', $cek, \OPENSSL_RAW_DATA, $nonce, $tag);

        return $sel.pack('N', 4096).\chr(\strlen($publique)).$publique.$chiffre.$tag;
    }

    /** @return array{prive: string, publique: string} clés VAPID en base64url */
    public static function nouvellesCles(): array
    {
        [$prive, $publique] = self::brut(openssl_pkey_new(['curve_name' => self::COURBE, 'private_key_type' => \OPENSSL_KEYTYPE_EC]));

        return ['prive' => self::b64e($prive), 'publique' => self::b64e($publique)];
    }

    public static function b64e(string $octets): string
    {
        return rtrim(strtr(base64_encode($octets), '+/', '-_'), '=');
    }

    public static function b64d(string $texte): string
    {
        return (string) base64_decode(strtr($texte, '-_', '+/'), true);
    }

    /** @return array{0: string, 1: string} clé privée (32 octets) et publique (65 octets) */
    private static function brut(\OpenSSLAsymmetricKey|false $cle): array
    {
        if ($cle === false) {
            throw new \RuntimeException('OpenSSL ne sait pas créer de clé P-256.');
        }
        $ec = openssl_pkey_get_details($cle)['ec'];
        $p = static fn (string $x): string => str_pad($x, 32, "\0", \STR_PAD_LEFT);

        return [$p($ec['d']), "\x04".$p($ec['x']).$p($ec['y'])];
    }

    private static function clePubliqueOpenssl(string $publique): \OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::DER_PUBLIQUE).$publique), 64, "\n")."-----END PUBLIC KEY-----\n";
        $cle = openssl_pkey_get_public($pem);
        if ($cle === false || \strlen($publique) !== 65) {
            throw new \RuntimeException('Clé publique invalide.');
        }

        return $cle;
    }

    private static function clePrivee(string $prive, string $publique): \OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420').$prive.hex2bin('a00a06082a8648ce3d030107a144034200').$publique;
        $cle = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        if ($cle === false) {
            throw new \RuntimeException('Clé privée invalide.');
        }

        return $cle;
    }

    /** Signature ECDSA DER → r||s (64 octets), forme attendue par JWT. */
    private static function signatureBrute(string $der): string
    {
        $pos = 2;
        $parties = [];
        for ($i = 0; $i < 2; ++$i) {
            $longueur = \ord($der[$pos + 1]);
            $entier = substr($der, $pos + 2, $longueur);
            $parties[] = str_pad(ltrim($entier, "\0"), 32, "\0", \STR_PAD_LEFT);
            $pos += 2 + $longueur;
        }

        return $parties[0].$parties[1];
    }
}
