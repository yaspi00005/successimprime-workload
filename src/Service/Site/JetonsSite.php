<?php

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Jetons des adresses publiques du site vitrine (docs/site-vitrine.md,
 * « Adresses des produits ») : à la place d'un identifiant de la base
 * (?produit=12), l'adresse porte un jeton court et stable
 * (?produit=Qm3x0Zk1_aE), qu'on ne peut ni deviner ni fabriquer.
 *
 * Le jeton se calcule, sans colonne en base : 4 octets de sceau (HMAC du
 * type et de l'identifiant) puis l'identifiant masqué par un second HMAC
 * du sceau, le tout en base64 pour adresse (11 caractères). La clé vient
 * de APP_SECRET : changer APP_SECRET change tous les jetons (les anciens
 * liens tombent alors sur la page sans produit choisi).
 *
 * Chaque type a ses propres jetons : celui d'une configuration n'ouvre
 * pas une finition. Un jeton modifié ou inventé est refusé (null).
 */
final class JetonsSite
{
    public const CONFIGURATION = 'configuration';
    public const FINITION = 'finition';
    public const PRODUIT = 'produit';
    public const FORMAT = 'format';

    /** Paramètres d'adresse du calculateur et de la demande de devis : nom => type de jeton. */
    public const PARAMETRES_PRODUIT = [
        'produit' => self::CONFIGURATION,
        'precedent' => self::CONFIGURATION,
        'finitions' => self::FINITION,
    ];

    /** Longueur d'un jeton : 8 octets en base64 sans « = ». */
    public const LONGUEUR = 11;

    private const ID_MAX = 0xFFFFFFFF;

    private readonly string $cle;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        // Clé propre aux jetons : APP_SECRET ne sert jamais tel quel.
        $this->cle = hash_hmac('sha256', 'site-vitrine/jetons-adresses/v1', $secret, true);
    }

    public function encoder(string $type, int $id): string
    {
        if ($id < 0 || $id > self::ID_MAX) {
            throw new \InvalidArgumentException('Identifiant hors bornes.');
        }
        $sceau = $this->sceau($type, $id);
        $corps = pack('N', $id) ^ $this->masque($type, $sceau);

        return rtrim(strtr(base64_encode($sceau.$corps), '+/', '-_'), '=');
    }

    /** Identifiant du jeton, ou null s'il est illisible, d'un autre type, modifié ou inventé. */
    public function decoder(string $type, mixed $jeton): ?int
    {
        if (!\is_string($jeton) || 1 !== preg_match('/^[A-Za-z0-9_-]{'.self::LONGUEUR.'}$/', $jeton)) {
            return null;
        }
        $octets = base64_decode(strtr($jeton, '-_', '+/'), true);
        if (false === $octets || 8 !== \strlen($octets)) {
            return null;
        }
        $sceau = substr($octets, 0, 4);
        $id = unpack('N', substr($octets, 4, 4) ^ $this->masque($type, $sceau))[1];

        // Le jeton recalculé doit être exactement celui reçu (sceau juste, écriture unique).
        return hash_equals($this->encoder($type, $id), $jeton) ? $id : null;
    }

    /** Un identifiant de la base écrit en chiffres (ancienne adresse). */
    public static function estIdentifiant(mixed $valeur): bool
    {
        return (\is_int($valeur) && $valeur >= 0) || (\is_string($valeur) && 1 === preg_match('/^\s*\d{1,9}\s*$/', $valeur));
    }

    /**
     * Paramètres d'une adresse à générer : chaque identifiant (entier ou
     * chiffres) des clés du schéma devient son jeton ; le reste ne change
     * pas. Les listes (finitions) sont traitées élément par élément ;
     * « 3,5 » (ancienne écriture des finitions) aussi.
     *
     * @param array<string, mixed>  $parametres
     * @param array<string, string> $schema     nom du paramètre => type de jeton
     *
     * @return array<string, mixed>
     */
    public function encoderParametres(array $parametres, array $schema = self::PARAMETRES_PRODUIT): array
    {
        foreach ($schema as $nom => $type) {
            if (!\array_key_exists($nom, $parametres)) {
                continue;
            }
            $valeur = self::liste($parametres[$nom]);
            $parametres[$nom] = \is_array($valeur)
                ? array_map(fn (mixed $v): mixed => self::estIdentifiant($v) ? $this->encoder($type, (int) $v) : $v, $valeur)
                : (self::estIdentifiant($valeur) ? $this->encoder($type, (int) $valeur) : $valeur);
        }

        return $parametres;
    }

    /**
     * Paramètres reçus : chaque jeton des clés du schéma redevient son
     * identifiant. Un jeton refusé disparaît (comme un paramètre absent).
     * Les identifiants en chiffres ne sont gardés que si $accepterIdentifiants
     * (formulaire envoyé en POST, qui n'est pas une adresse).
     *
     * @param array<string, mixed>  $parametres
     * @param array<string, string> $schema
     *
     * @return array<string, mixed>
     */
    public function decoderParametres(array $parametres, array $schema = self::PARAMETRES_PRODUIT, bool $accepterIdentifiants = false): array
    {
        $lire = fn (string $type, mixed $v): ?int => $accepterIdentifiants && self::estIdentifiant($v) ? (int) $v : $this->decoder($type, \is_string($v) ? trim($v) : $v);
        foreach ($schema as $nom => $type) {
            if (!\array_key_exists($nom, $parametres)) {
                continue;
            }
            $valeur = self::liste($parametres[$nom]);
            if (\is_array($valeur)) {
                $parametres[$nom] = array_values(array_filter(array_map(static fn (mixed $v): ?int => $lire($type, $v), $valeur), static fn (?int $id): bool => null !== $id));
            } elseif (null === $id = $lire($type, $valeur)) {
                unset($parametres[$nom]);
            } else {
                $parametres[$nom] = $id;
            }
        }

        return $parametres;
    }

    /**
     * L'adresse porte encore un identifiant en chiffres (ancien lien) :
     * la page redirige alors (301) vers l'adresse avec jetons.
     *
     * @param array<string, mixed>  $parametres
     * @param array<string, string> $schema
     */
    public static function contientIdentifiants(array $parametres, array $schema = self::PARAMETRES_PRODUIT): bool
    {
        foreach (array_keys($schema) as $nom) {
            $valeur = self::liste($parametres[$nom] ?? null);
            foreach (\is_array($valeur) ? $valeur : [$valeur] as $v) {
                if (self::estIdentifiant($v)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** « 3,5 » devient une liste ; une liste est bornée (Calculateur::FINITIONS_MAX). */
    private static function liste(mixed $valeur): mixed
    {
        if (\is_string($valeur) && str_contains($valeur, ',')) {
            $valeur = explode(',', $valeur);
        }

        return \is_array($valeur) ? \array_slice(array_values($valeur), 0, Calculateur::FINITIONS_MAX) : $valeur;
    }

    private function sceau(string $type, int $id): string
    {
        return substr(hash_hmac('sha256', $type."\0".$id, $this->cle, true), 0, 4);
    }

    private function masque(string $type, string $sceau): string
    {
        return substr(hash_hmac('sha256', $type."\1".$sceau, $this->cle, true), 0, 4);
    }
}
