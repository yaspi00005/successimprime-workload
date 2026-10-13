<?php

namespace App\Service\Finances;

/**
 * Le mois affiché par les écrans Finances (« Période : ce mois ») :
 * lecture du paramètre AAAA-MM, bornes et libellés en français.
 */
final class Periode
{
    public const MOIS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    public const MOIS_COURTS = [1 => 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];
    public const JOURS_COURTS = [1 => 'Lun.', 'Mar.', 'Mer.', 'Jeu.', 'Ven.', 'Sam.', 'Dim.'];

    /** Le mois demandé (AAAA-MM), jamais dans le futur ; ce mois-ci sinon. */
    public static function moisDemande(string $periode, \DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        $courant = $maintenant->modify('first day of this month')->setTime(0, 0);
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periode, $m)) {
            return $courant;
        }
        $mois = $courant->setDate((int) $m[1], (int) $m[2], 1);

        return $mois > $courant ? $courant : $mois;
    }

    /**
     * Les 12 derniers mois pour le sélecteur « Période ».
     *
     * @return array<string, string>
     */
    public static function choix(\DateTimeImmutable $maintenant): array
    {
        $courant = $maintenant->modify('first day of this month')->setTime(0, 0);
        $periodes = [];
        for ($i = 0; $i < 12; ++$i) {
            $mois = $courant->modify(\sprintf('-%d months', $i));
            $periodes[$mois->format('Y-m')] = match ($i) {
                0 => 'ce mois',
                1 => 'mois dernier',
                default => self::libelle($mois),
            };
        }

        return $periodes;
    }

    /**
     * Premier jour du mois et premier jour du mois suivant.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public static function bornes(\DateTimeImmutable $jour): array
    {
        $debut = $jour->modify('first day of this month')->setTime(0, 0);

        return [$debut, $debut->modify('+1 month')];
    }

    /** « octobre 2026 ». */
    public static function libelle(\DateTimeImmutable $mois): string
    {
        return self::MOIS[(int) $mois->format('n')].' '.$mois->format('Y');
    }

    /** « Mar. 20/10 ». */
    public static function jourCourt(\DateTimeImmutable $jour): string
    {
        return self::JOURS_COURTS[(int) $jour->format('N')].' '.$jour->format('d/m');
    }
}
