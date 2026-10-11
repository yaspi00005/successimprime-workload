<?php

namespace App\Service\Pointage;

/**
 * Règles du pointage (docs/pointage.md), sans base de données.
 *
 * Une présence va d'une arrivée au départ suivant, même le lendemain
 * (nuit au bureau). Elle est rattachée au jour de l'arrivée.
 *
 * $regles reprend Paramètres › Pointage et › Horaires :
 * ouverture, fermeture (HH:MM), tolerance_minutes, anti_doublon_minutes,
 * pause_debut, pause_fin, alerte_depart, points_heure, points_retard,
 * points_soiree, points_nuit, points_oubli.
 */
final class CalculPointage
{
    public const ARRIVEE = 'arrivee';
    public const DEPART = 'depart';
    public const DOUBLON = 'doublon';

    public const STATUT_EN_COURS = 'en_cours';
    public const STATUT_COMPLET = 'complet';
    public const STATUT_RETARD = 'retard';
    public const STATUT_HEURES_SUP = 'heures_sup';
    public const STATUT_NON_POINTE = 'depart_non_pointe';

    public const STATUTS = [
        self::STATUT_EN_COURS => 'En cours',
        self::STATUT_COMPLET => 'Complet',
        self::STATUT_RETARD => 'Retard',
        self::STATUT_HEURES_SUP => 'Heures sup.',
        self::STATUT_NON_POINTE => 'Départ non pointé',
    ];

    /** Au-delà, une présence sans départ est « départ non pointé ». */
    public const HEURES_MAX_PRESENCE = 26;

    /** Heures sup. affichées à partir de 30 min après la fermeture. */
    public const MINUTES_SUP_AFFICHEES = 30;

    /**
     * Ce que fait un nouveau pointage.
     *
     * @param \DateTimeImmutable|null $arriveeOuverte arrivée de la présence encore ouverte
     * @param \DateTimeImmutable|null $dernier        dernier pointage enregistré (arrivée ou départ)
     */
    public static function decider(?\DateTimeImmutable $arriveeOuverte, ?\DateTimeImmutable $dernier, \DateTimeImmutable $maintenant, int $antiDoublonMinutes): string
    {
        if ($dernier !== null && $maintenant->getTimestamp() - $dernier->getTimestamp() < $antiDoublonMinutes * 60) {
            return self::DOUBLON;
        }

        if ($arriveeOuverte !== null && $maintenant->getTimestamp() - $arriveeOuverte->getTimestamp() <= self::HEURES_MAX_PRESENCE * 3600) {
            return self::DEPART;
        }

        // Pas de présence ouverte, ou une présence trop ancienne qui reste à régulariser.
        return self::ARRIVEE;
    }

    /**
     * Calcul d'une présence.
     *
     * @param array<string, mixed> $regles
     *
     * @return array{presence: ?int, retard: int, sup: int, statut: string, points: list<array{libelle: string, points: int}>, total: int, pause: int, bonusEnAttente: ?string}
     */
    public static function presence(\DateTimeImmutable $arrivee, ?\DateTimeImmutable $depart, array $regles, \DateTimeImmutable $maintenant, bool $heuresSupValidees = false, int $ajustement = 0): array
    {
        // Jour de repos (Paramètres › Horaires) : ni retard ni ponctualité,
        // tout le temps passé compte en heures sup.
        $ouvre = !isset($regles['jours']) || \in_array((int) $arrivee->format('N'), self::joursOuvres((string) $regles['jours']), true);
        $prevue = self::a($arrivee, (string) $regles['ouverture']);
        $retard = $ouvre ? max(0, intdiv($arrivee->getTimestamp() - $prevue->getTimestamp(), 60)) : 0;
        $enRetard = $retard > (int) $regles['tolerance_minutes'];

        $points = match (true) {
            !$ouvre => [],
            $enRetard => [['libelle' => 'Retard de '.self::duree($retard), 'points' => -(int) $regles['points_retard']]],
            default => [['libelle' => 'À l\'heure', 'points' => (int) $regles['points_heure']]],
        };

        $presence = null;
        $pause = 0;
        $sup = 0;
        $bonusEnAttente = null;

        if ($depart === null) {
            $alerte = self::a($arrivee, (string) $regles['alerte_depart']);
            $ouverteTropLongtemps = $maintenant->getTimestamp() - $arrivee->getTimestamp() > self::HEURES_MAX_PRESENCE * 3600;
            $statut = $maintenant >= $alerte || $ouverteTropLongtemps ? self::STATUT_NON_POINTE : self::STATUT_EN_COURS;
            if ($statut === self::STATUT_NON_POINTE) {
                $points[] = ['libelle' => 'Oubli de pointage', 'points' => -(int) $regles['points_oubli']];
            }
        } else {
            $pause = self::chevauchement($arrivee, $depart, self::a($arrivee, (string) $regles['pause_debut']), self::a($arrivee, (string) $regles['pause_fin']));
            $brut = intdiv($depart->getTimestamp() - $arrivee->getTimestamp(), 60);
            $presence = max(0, $brut - $pause);
            $sup = $ouvre ? max(0, intdiv($depart->getTimestamp() - self::a($arrivee, (string) $regles['fermeture'])->getTimestamp(), 60)) : $presence;

            // Une seule ligne de bonus par journée, la plus haute.
            $bonus = match (true) {
                $brut >= 24 * 60 => ['libelle' => 'Nuit au bureau', 'points' => (int) $regles['points_nuit']],
                $sup >= 60 => ['libelle' => 'Au moins 1 h après la fin', 'points' => (int) $regles['points_soiree']],
                default => null,
            };
            if ($bonus !== null) {
                // Le bonus n'est acquis qu'une fois les heures validées.
                $heuresSupValidees ? $points[] = $bonus : $bonusEnAttente = $bonus['libelle'].' (+'.$bonus['points'].') à valider';
            }

            $statut = match (true) {
                $enRetard => self::STATUT_RETARD,
                $sup >= self::MINUTES_SUP_AFFICHEES => self::STATUT_HEURES_SUP,
                default => self::STATUT_COMPLET,
            };
        }

        if ($ajustement !== 0) {
            $points[] = ['libelle' => 'Ajustement', 'points' => $ajustement];
        }

        return [
            'presence' => $presence,
            'retard' => $enRetard ? $retard : 0,
            'sup' => $sup,
            'statut' => $statut,
            'points' => $points,
            'total' => array_sum(array_column($points, 'points')),
            'pause' => $pause,
            'bonusEnAttente' => $bonusEnAttente,
        ];
    }

    /**
     * Note de ponctualité sur 100 : points ÷ (points « à l'heure » × jours
     * travaillés), entre 0 et 100. Null sans journée travaillée.
     */
    public static function note(int $points, int $jours, int $pointsHeure = 10): ?int
    {
        if ($jours <= 0 || $pointsHeure <= 0) {
            return null;
        }

        return max(0, min(100, (int) round($points / ($pointsHeure * $jours) * 100)));
    }

    /**
     * Jours ouvrés d'une plage (« lun-sam » → 1 à 6).
     *
     * @return list<int>
     */
    public static function joursOuvres(string $plage): array
    {
        return match ($plage) {
            'lun-ven' => [1, 2, 3, 4, 5],
            'lun-dim' => [1, 2, 3, 4, 5, 6, 7],
            default => [1, 2, 3, 4, 5, 6],
        };
    }

    /** « 9 h 14 », « 18 min ». */
    public static function duree(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h '.str_pad((string) ($minutes % 60), 2, '0', \STR_PAD_LEFT);
    }

    private static function a(\DateTimeImmutable $jour, string $heure): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $heure) + [1 => '0']);

        return $jour->setTime($h, $m);
    }

    private static function chevauchement(\DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $pauseDebut, \DateTimeImmutable $pauseFin): int
    {
        $de = max($debut->getTimestamp(), $pauseDebut->getTimestamp());
        $a = min($fin->getTimestamp(), $pauseFin->getTimestamp());

        return max(0, intdiv($a - $de, 60));
    }
}
