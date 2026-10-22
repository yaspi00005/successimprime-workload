<?php

namespace App\Service\Messagerie;

/**
 * Nombre de SMS (parties) qu'un texte occupe, pour suivre le crédit
 * consommé sans garder le texte.
 *
 * Règle des opérateurs (GSM 03.38) : 160 caractères en un SMS avec
 * l'alphabet GSM (lettres sans accent, é è ù ì ò à, chiffres, ponctuation
 * courante), 153 par partie au-delà ; un seul caractère hors de cet
 * alphabet (ê, ç minuscule, ’, emoji…) fait passer tout le message à 70,
 * puis 67 par partie. Les caractères € [ ] { } \ ~ ^ | comptent double.
 *
 * Orange ne publie pas sa façon de compter : c'est une estimation, que
 * le solde lu chez Orange corrige quand les clés sont posées.
 */
final class SegmentsSms
{
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_ETENDU = '€[]{}\\~^|';

    public static function compter(string $texte): int
    {
        if ('' === $texte) {
            return 0;
        }
        $caracteres = mb_str_split($texte);
        $longueur = 0;
        $gsm = true;
        foreach ($caracteres as $c) {
            if (str_contains(self::GSM_ETENDU, $c)) {
                $longueur += 2;
            } elseif (str_contains(self::GSM, $c)) {
                ++$longueur;
            } else {
                $gsm = false;
                break;
            }
        }
        if (!$gsm) {
            // UCS-2 : chaque caractère compte un (un emoji en compte deux).
            $longueur = (int) (\strlen((string) mb_convert_encoding($texte, 'UTF-16BE', 'UTF-8')) / 2);

            return $longueur <= 70 ? 1 : (int) ceil($longueur / 67);
        }

        return $longueur <= 160 ? 1 : (int) ceil($longueur / 153);
    }
}
