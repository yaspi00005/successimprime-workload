<?php

namespace App\Routing;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Chiffre les numéros des fiches dans les URL (docs/ids-chiffres.md) :
 * « /commandes/4217 » devient « /commandes/k3XpQ9aZt ».
 *
 * Le numéro (32 bits) passe par un chiffrement de Feistel à 4 tours
 * (HMAC-SHA256 avec la clé ID_CLE), puis reçoit une signature de 21 bits :
 * deux fiches qui se suivent donnent des codes sans rapport, et un code
 * inventé est refusé. Le code fait toujours 9 caractères [0-9A-Za-z].
 *
 * ID_CLE doit être la même sur l'ERP local et sur le site en ligne, sinon
 * les liens envoyés de l'un ne s'ouvrent pas sur l'autre. Sans ID_CLE, la
 * clé de l'application (APP_SECRET) sert.
 */
final class CodeId
{
    public const LONGUEUR = 9;
    public const MOTIF = '[0-9A-Za-z]{9}';

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const TOURS = 4;
    /** 32 + 21 = 53 bits : tient dans 9 caractères en base 62. */
    private const BITS_SIGNATURE = 21;

    private readonly string $cle;

    /** @var array<int, string> */
    private array $codes = [];

    /** @var array<string, ?int> */
    private array $ids = [];

    public function __construct(#[Autowire('%env(default:kernel.secret:ID_CLE)%')] string $cle)
    {
        if ('' === $cle) {
            throw new \LogicException('ID_CLE (ou APP_SECRET) est vide : les numéros des URL ne peuvent pas être chiffrés.');
        }
        $this->cle = 'succes-imprim-id|'.$cle;
    }

    /** Le code d'un numéro de fiche (1 à 4 294 967 295). */
    public function coder(int $id): string
    {
        if (isset($this->codes[$id])) {
            return $this->codes[$id];
        }
        if ($id < 0 || $id > 0xFFFFFFFF) {
            throw new \InvalidArgumentException(\sprintf('Numéro hors limites pour une URL : %d.', $id));
        }
        $gauche = $id >> 16;
        $droite = $id & 0xFFFF;
        for ($tour = 0; $tour < self::TOURS; ++$tour) {
            [$gauche, $droite] = [$droite, $gauche ^ $this->ronde($tour, $droite)];
        }
        $melange = ($gauche << 16) | $droite;
        $nombre = ($melange << self::BITS_SIGNATURE) | $this->signature($melange);

        $code = '';
        for ($i = 0; $i < self::LONGUEUR; ++$i) {
            $code = self::ALPHABET[$nombre % 62].$code;
            $nombre = intdiv($nombre, 62);
        }

        return $this->codes[$id] = $code;
    }

    /** Le numéro d'un code, ou null si le code n'est pas valide. */
    public function decoder(string $code): ?int
    {
        if (\array_key_exists($code, $this->ids)) {
            return $this->ids[$code];
        }
        if (!self::estCode($code)) {
            return null;
        }
        $nombre = 0;
        for ($i = 0; $i < self::LONGUEUR; ++$i) {
            $nombre = $nombre * 62 + strpos(self::ALPHABET, $code[$i]);
        }
        if ($nombre >= 1 << (32 + self::BITS_SIGNATURE)) {
            return $this->ids[$code] = null;
        }
        $melange = $nombre >> self::BITS_SIGNATURE;
        if (!hash_equals(\sprintf('%06x', $this->signature($melange)), \sprintf('%06x', $nombre & ((1 << self::BITS_SIGNATURE) - 1)))) {
            return $this->ids[$code] = null;
        }
        $gauche = $melange >> 16;
        $droite = $melange & 0xFFFF;
        for ($tour = self::TOURS - 1; $tour >= 0; --$tour) {
            [$gauche, $droite] = [$droite ^ $this->ronde($tour, $gauche), $gauche];
        }

        return $this->ids[$code] = ($gauche << 16) | $droite;
    }

    /**
     * Une chaîne qui a la forme d'un code : 9 caractères [0-9A-Za-z]. Les
     * numéros des fiches ont 8 chiffres au plus, ils ne s'y confondent pas.
     */
    public static function estCode(string $valeur): bool
    {
        return 1 === preg_match('/^'.self::MOTIF.'$/', $valeur);
    }

    private function ronde(int $tour, int $moitie): int
    {
        return hexdec(substr(hash_hmac('sha256', $tour.':'.$moitie, $this->cle), 0, 4));
    }

    private function signature(int $melange): int
    {
        return hexdec(substr(hash_hmac('sha256', 'sig:'.$melange, $this->cle), 0, 6)) & ((1 << self::BITS_SIGNATURE) - 1);
    }
}
