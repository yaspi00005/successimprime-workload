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
 * points_soiree, points_nuit, points_oubli (ancien barème).
 *
 * Avec « points » (valeurs de Paramètres › Points du pointage, posées par
 * Pointages::reglesDe à partir de la date de début), les points de la
 * journée suivent le nouveau barème « heures faites » (pointsHeures) ;
 * ouverture et fermeture sont alors celles de l'horaire de la personne.
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
     * @return array{presence: ?int, retard: int, sup: int, statut: string, points: list<array{libelle: string, points: int}>, total: int, pause: int, bonusEnAttente: ?string, bareme: string, faites: ?int, dues: ?int, raison: string, horaire: string}
     */
    public static function presence(\DateTimeImmutable $arrivee, ?\DateTimeImmutable $depart, array $regles, \DateTimeImmutable $maintenant, bool $heuresSupValidees = false, int $ajustement = 0): array
    {
        // Jour de repos (Paramètres › Horaires) : ni retard ni ponctualité,
        // tout le temps passé compte en heures sup.
        $ouvre = !isset($regles['jours']) || \in_array((int) $arrivee->format('N'), self::joursOuvres((string) $regles['jours']), true);
        $prevue = self::a($arrivee, (string) $regles['ouverture']);
        $retard = $ouvre ? max(0, intdiv($arrivee->getTimestamp() - $prevue->getTimestamp(), 60)) : 0;
        $enRetard = $retard > (int) $regles['tolerance_minutes'];
        /** @var array<string, mixed>|null $heures nouveau barème (tuile Points du pointage) */
        $heures = $regles['points'] ?? null;

        $points = match (true) {
            $heures !== null, !$ouvre => [],
            $enRetard => [['libelle' => 'Retard de '.self::duree($retard), 'points' => -(int) $regles['points_retard']]],
            default => [['libelle' => 'À l\'heure', 'points' => (int) $regles['points_heure']]],
        };

        $presence = null;
        $pause = 0;
        $sup = 0;
        $bonusEnAttente = null;

        if ($depart === null) {
            $alerte = self::apres($arrivee, (string) $regles['alerte_depart'], $prevue);
            $ouverteTropLongtemps = $maintenant->getTimestamp() - $arrivee->getTimestamp() > self::HEURES_MAX_PRESENCE * 3600;
            $statut = $maintenant >= $alerte || $ouverteTropLongtemps ? self::STATUT_NON_POINTE : self::STATUT_EN_COURS;
            if ($statut === self::STATUT_NON_POINTE && $heures === null) {
                $points[] = ['libelle' => 'Oubli de pointage', 'points' => -(int) $regles['points_oubli']];
            }
        } else {
            $pause = self::chevauchement($arrivee, $depart, self::apres($arrivee, (string) $regles['pause_debut'], $prevue), self::apres($arrivee, (string) $regles['pause_fin'], $prevue));
            $brut = intdiv($depart->getTimestamp() - $arrivee->getTimestamp(), 60);
            $presence = max(0, $brut - $pause);
            $sup = $ouvre ? max(0, intdiv($depart->getTimestamp() - self::apres($arrivee, (string) $regles['fermeture'], $prevue)->getTimestamp(), 60)) : $presence;

            // Une seule ligne de bonus par journée, la plus haute.
            $bonus = match (true) {
                $brut >= 24 * 60 => ['libelle' => 'Nuit au bureau', 'points' => (int) $regles['points_nuit']],
                $sup >= 60 => ['libelle' => 'Au moins 1 h après la fin', 'points' => (int) $regles['points_soiree']],
                default => null,
            };
            if ($bonus !== null && $heures === null) {
                // Le bonus n'est acquis qu'une fois les heures validées.
                $heuresSupValidees ? $points[] = $bonus : $bonusEnAttente = $bonus['libelle'].' (+'.$bonus['points'].') à valider';
            }

            $statut = match (true) {
                $enRetard => self::STATUT_RETARD,
                $sup >= self::MINUTES_SUP_AFFICHEES => self::STATUT_HEURES_SUP,
                default => self::STATUT_COMPLET,
            };
        }

        $faites = null;
        $dues = null;
        if ($heures !== null) {
            $journee = self::pointsHeures($arrivee, $depart, $regles, $heures, $prevue, $ouvre, $enRetard ? $retard : 0, $statut, $heuresSupValidees);
            ['points' => $points, 'enAttente' => $bonusEnAttente, 'faites' => $faites, 'dues' => $dues, 'raison' => $raison] = $journee;
        } else {
            $raison = implode(' · ', array_column($points, 'libelle')) ?: self::STATUTS[$statut];
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
            'bareme' => $heures !== null ? 'heures' : 'ancien',
            'faites' => $faites,
            'dues' => $dues,
            'raison' => $raison,
            'horaire' => $regles['ouverture'].' – '.$regles['fermeture'],
        ];
    }

    /**
     * Barème « heures faites » (Yaya, 16/10/2026 ; docs/pointage.md,
     * « Calcul des points ») pour une journée :
     * - heures dues faites (à l'heure, ou retard rattrapé en partant plus
     *   tard) : + points_heures_faites ;
     * - en retard et heures pas faites : − points_retard ;
     * - à l'heure mais parti avant la fin : 0 ;
     * - chaque heure entière au-delà des heures dues : + points_heure_en_plus ;
     * - longue journée (longue_heures ou plus, garde de 24 h) :
     *   points_longue pour la journée, à la place du reste ;
     * - départ non pointé : 0, la journée est à corriger par la RH.
     * La tolérance de retard sert aussi de marge sur les heures dues. Un
     * jour de repos n'a pas d'heures dues : seules les heures en plus
     * comptent. Avec valider_en_plus, les points des heures en plus et de
     * la longue journée attendent la validation des heures par la RH.
     *
     * @param array<string, mixed> $regles
     * @param array<string, mixed> $p      valeurs de la tuile Points du pointage
     *
     * @return array{points: list<array{libelle: string, points: int}>, enAttente: ?string, faites: ?int, dues: int, raison: string}
     */
    public static function pointsHeures(\DateTimeImmutable $arrivee, ?\DateTimeImmutable $depart, array $regles, array $p, \DateTimeImmutable $prevue, bool $ouvre, int $retard, string $statut, bool $heuresValidees): array
    {
        $pauseDebut = self::apres($arrivee, (string) $regles['pause_debut'], $prevue);
        $pauseFin = self::apres($arrivee, (string) $regles['pause_fin'], $prevue);
        $deduire = (bool) $p['pause_deduite'];
        $finPrevue = self::apres($arrivee, (string) $regles['fermeture'], $prevue);
        $dues = $ouvre ? max(0, self::minutes($prevue, $finPrevue) - ($deduire ? self::chevauchement($prevue, $finPrevue, $pauseDebut, $pauseFin) : 0)) : 0;
        $enRetard = $retard > 0 ? 'Retard de '.self::duree($retard) : null;

        if ($depart === null) {
            return ['points' => [], 'enAttente' => null, 'faites' => null, 'dues' => $dues, 'raison' => $statut === self::STATUT_NON_POINTE
                ? 'Départ non pointé : pas de points tant que la RH n\'a pas corrigé le départ.'
                : ($enRetard !== null ? $enRetard.', en cours' : 'En cours').' : les points sont comptés au départ.'];
        }

        $faites = max(0, self::minutes($arrivee, $depart) - ($deduire ? self::chevauchement($arrivee, $depart, $pauseDebut, $pauseFin) : 0));
        $sur = '('.self::duree($faites).' sur '.self::duree($dues).')';

        [$base, $phrase] = match (true) {
            !$ouvre => [null, 'Jour de repos travaillé'],
            $faites >= $dues - (int) $regles['tolerance_minutes'] => [
                ['libelle' => $enRetard !== null ? $enRetard.' rattrapé, heures faites' : 'Heures faites', 'points' => (int) $p['points_heures_faites']],
                ($enRetard !== null ? $enRetard.' rattrapé : heures faites ' : 'À l\'heure, heures faites ').$sur,
            ],
            $enRetard !== null => [
                ['libelle' => $enRetard.' et heures pas faites', 'points' => -(int) $p['points_retard']],
                $enRetard.' et heures pas faites '.$sur,
            ],
            default => [
                ['libelle' => 'Parti avant la fin, heures pas faites', 'points' => 0],
                'À l\'heure mais parti avant la fin '.$sur,
            ],
        };

        $enPlus = intdiv(max(0, $faites - $dues), 60);
        $longue = $faites >= (int) $p['longue_heures'] * 60;
        $bonus = match (true) {
            $longue => ['libelle' => 'Longue journée de '.self::duree($faites), 'points' => (int) $p['points_longue']],
            $enPlus > 0 && (int) $p['points_heure_en_plus'] > 0 => ['libelle' => $enPlus.' h en plus', 'points' => $enPlus * (int) $p['points_heure_en_plus']],
            default => null,
        };
        $acquis = $bonus === null || $heuresValidees || !$p['valider_en_plus'];

        $points = match (true) {
            $bonus !== null && $acquis && $longue => [$bonus],
            $bonus !== null && $acquis => array_values(array_filter([$base, $bonus])),
            default => array_values(array_filter([$base])),
        };

        $suite = match (true) {
            $bonus === null => !$ouvre ? 'moins d\'une heure, pas de points' : null,
            $longue => 'longue journée de '.self::duree($faites).' : '.$bonus['points'].' points pour la journée'.($acquis ? '' : ' une fois les heures validées par la RH'),
            default => $bonus['libelle'].' : +'.$bonus['points'].($acquis ? '' : ' une fois les heures validées par la RH'),
        };
        $enAttente = $acquis ? null : ($longue
            ? 'Longue journée de '.self::duree($faites).' : '.$bonus['points'].' points pour la journée (au lieu de '.array_sum(array_column($points, 'points')).') à valider'
            : $bonus['libelle'].' (+'.$bonus['points'].') à valider');

        return [
            'points' => $points,
            'enAttente' => $enAttente,
            'faites' => $faites,
            'dues' => $dues,
            'raison' => $phrase.($suite !== null ? ' ; '.$suite : '').'.',
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
     * La journée compte-t-elle dans les « jours travaillés » de la note ?
     * Ancien barème : toute journée pointée. Nouveau calcul : un jour
     * travaillé dont une présence est finie (ou sans départ pointé) ; un
     * jour de repos travaillé n'abaisse pas la note (ses points en plus
     * s'ajoutent), la journée en cours non plus.
     *
     * @param list<array{bareme: string, statut: string}> $calculs les présences du jour
     */
    public static function compteDansLaNote(bool $ouvre, array $calculs): bool
    {
        if ($calculs === []) {
            return false;
        }
        if ($calculs[0]['bareme'] !== 'heures') {
            return true;
        }

        return $ouvre && array_filter($calculs, static fn (array $c): bool => $c['statut'] !== self::STATUT_EN_COURS) !== [];
    }

    /**
     * Jours ouvrés d'une plage (« lun-sam » → 1 à 6).
     *
     * @return list<int>
     */
    public static function joursOuvres(string $plage): array
    {
        // Jours propres à un employé (fiche E02) : « 1,2,4,5,6,7 ».
        if (preg_match('/^[1-7](,[1-7])*$/', $plage)) {
            return array_map('intval', explode(',', $plage));
        }

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

    /** « 09:00 » → « 9 h », « 08:30 » → « 8 h 30 ». */
    public static function heure(?string $heure): string
    {
        if ($heure === null || !preg_match('/^(\d{1,2}):(\d{2})$/', $heure, $m)) {
            return '—';
        }

        return (int) $m[1].' h'.($m[2] !== '00' ? ' '.$m[2] : '');
    }

    private static function a(\DateTimeImmutable $jour, string $heure): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $heure) + [1 => '0']);

        return $jour->setTime($h, $m);
    }

    /**
     * Heure de la journée de travail commencée à l'arrivée : avant
     * l'heure d'ouverture, c'est le lendemain (poste de nuit, 19 h – 7 h).
     */
    private static function apres(\DateTimeImmutable $arrivee, string $heure, \DateTimeImmutable $ouverture): \DateTimeImmutable
    {
        $moment = self::a($arrivee, $heure);

        return $moment < $ouverture ? $moment->modify('+1 day') : $moment;
    }

    private static function minutes(\DateTimeImmutable $de, \DateTimeImmutable $a): int
    {
        return intdiv($a->getTimestamp() - $de->getTimestamp(), 60);
    }

    private static function chevauchement(\DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $pauseDebut, \DateTimeImmutable $pauseFin): int
    {
        $de = max($debut->getTimestamp(), $pauseDebut->getTimestamp());
        $a = min($fin->getTimestamp(), $pauseFin->getTimestamp());

        return max(0, intdiv($a - $de, 60));
    }
}
