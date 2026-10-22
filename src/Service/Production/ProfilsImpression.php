<?php

namespace App\Service\Production;

/**
 * Profils d'impression d'une machine (maquette PD01, « Chaque machine a
 * son dossier et ses réglages ») : pour un support, le profil couleur du
 * RIP, le mode, la vitesse, l'encre au m², la chauffe, le séchage et le
 * miroir. Rangés en JSON sur AtelierMachine::profils.
 *
 * Le profil d'un tirage est celui dont le support correspond (identifiant
 * d'article, code ou mot du nom), sinon le premier de la machine, sinon
 * aucun. Classe pure.
 */
final class ProfilsImpression
{
    public const MAX = 12;

    /**
     * Profils propres (valeurs bornées, chaînes coupées) ; une ligne sans
     * support ni fichier est ignorée.
     *
     * @param mixed $brut tableau venu du JSON ou du formulaire
     *
     * @return list<array{support: string, profilIcc: string, mode: string, vitesseM2h: ?float, encreMlM2: ?float, chauffeC: ?int, sechageMin: ?int, miroir: bool}>
     */
    public static function normaliser(mixed $brut): array
    {
        if (!\is_array($brut)) {
            return [];
        }
        $profils = [];
        foreach ($brut as $p) {
            if (!\is_array($p)) {
                continue;
            }
            $support = self::texte($p['support'] ?? '', 60);
            $icc = self::texte($p['profilIcc'] ?? '', 120);
            if ('' === $support && '' === $icc) {
                continue;
            }
            $profils[] = [
                'support' => $support,
                'profilIcc' => $icc,
                'mode' => self::texte($p['mode'] ?? '', 80),
                'vitesseM2h' => self::nombre($p['vitesseM2h'] ?? null, 0.1, 500),
                'encreMlM2' => self::nombre($p['encreMlM2'] ?? null, 0.1, 200),
                'chauffeC' => null !== ($c = self::nombre($p['chauffeC'] ?? null, 0, 120)) ? (int) round($c) : null,
                'sechageMin' => null !== ($s = self::nombre($p['sechageMin'] ?? null, 0, 600)) ? (int) round($s) : null,
                'miroir' => \in_array($p['miroir'] ?? false, [true, 1, '1', 'on', 'oui'], true),
            ];
            if (\count($profils) >= self::MAX) {
                break;
            }
        }

        return $profils;
    }

    /**
     * Le profil d'un support : identifiant d'article (« 900020 »), code
     * (« BACHE440 ») ou mot du nom (« bâche 440 »), sinon le premier.
     *
     * @param list<array<string, mixed>> $profils
     *
     * @return array<string, mixed>|null
     */
    public static function choisir(array $profils, ?int $articleId, string $libelleSupport, string $codeSupport): ?array
    {
        $libelle = ' '.self::simplifier($libelleSupport).' ';
        $code = strtoupper($codeSupport);
        foreach ($profils as $p) {
            $cle = trim((string) ($p['support'] ?? ''));
            if ('' === $cle) {
                continue;
            }
            if (ctype_digit($cle) && null !== $articleId && (int) $cle === $articleId) {
                return $p;
            }
            if ('' !== $code && strtoupper(str_replace([' ', '-'], '', $cle)) === $code) {
                return $p;
            }
            $mot = self::simplifier($cle);
            if ('' !== $mot && !ctype_digit($cle) && str_contains($libelle, ' '.$mot.' ')) {
                return $p;
            }
        }

        return $profils[0] ?? null;
    }

    private static function texte(mixed $valeur, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F]+/', ' ', \is_scalar($valeur) ? (string) $valeur : '')), 0, $max);
    }

    private static function nombre(mixed $valeur, float $min, float $max): ?float
    {
        if (!\is_scalar($valeur)) {
            return null;
        }
        $texte = str_replace([' ', ','], ['', '.'], trim((string) $valeur));
        if ('' === $texte || !is_numeric($texte)) {
            return null;
        }
        $n = (float) $texte;

        return $n < $min || $n > $max ? null : round($n, 2);
    }

    private static function simplifier(string $texte): string
    {
        $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($texte));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', false !== $sans ? $sans : $texte));
    }
}
