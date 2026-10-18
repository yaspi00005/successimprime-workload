<?php

namespace App\Service\Rapports;

/**
 * Quand part chaque rapport (docs/rapports-mail.md), règles pures :
 *  - du jour : le jour même, à l'heure des Réglages (20 h), seulement les
 *    jours d'ouverture de l'atelier si la case est cochée ; une heure
 *    avant midi (7 h) l'envoie le lendemain matin, pour la veille
 *    (journée finie), et non pour une journée à peine commencée ;
 *  - de la semaine : la semaine suivante, le jour et à l'heure des
 *    Réglages (lundi 7 h), pour la semaine passée ;
 *  - du mois : le mois suivant, le jour du mois et à l'heure des Réglages
 *    (le 1er à 7 h), pour le mois passé.
 *
 * La tâche planifiée tourne souvent (chaque quart d'heure) : elle envoie
 * la dernière période dont l'heure est passée, si elle ne l'a pas déjà
 * été. Un ordinateur éteint à 20 h envoie donc le rapport du jour à son
 * redémarrage, jamais deux fois, et jamais une pile de vieux rapports.
 */
final class Planification
{
    /**
     * Retard au-delà duquel un rapport manqué ne part plus : le rapport du
     * samedi soir part encore le lundi matin, celui de la semaine jusqu'au
     * jeudi, celui du mois pendant une semaine.
     */
    public const RATTRAPAGE = [
        PeriodeRapport::JOUR => '+2 days',
        PeriodeRapport::SEMAINE => '+3 days',
        PeriodeRapport::MOIS => '+7 days',
    ];

    /** Avant cette heure, le rapport du jour part le lendemain matin, pour la veille. */
    public const MIDI = 12;

    /**
     * @param string    $heure       « 20:00 »
     * @param int       $jourSemaine 1 (lundi) à 7, pour le rapport de la semaine
     * @param int       $jourMois    1 à 28, pour le rapport du mois
     */
    public function __construct(
        public readonly string $heure,
        public readonly int $jourSemaine = 1,
        public readonly int $jourMois = 1,
    ) {
    }

    /** Moment où part le rapport de cette période. */
    public function moment(PeriodeRapport $p): \DateTimeImmutable
    {
        [$h, $m] = self::lireHeure($this->heure);

        return match ($p->type) {
            PeriodeRapport::JOUR => (self::lendemain($this->heure) ? $p->fin : $p->debut)->setTime($h, $m),
            PeriodeRapport::SEMAINE => $p->fin->modify(\sprintf('+%d days', max(1, min(7, $this->jourSemaine)) - 1))->setTime($h, $m),
            default => $p->fin->modify(\sprintf('+%d days', max(1, min(28, $this->jourMois)) - 1))->setTime($h, $m),
        };
    }

    /**
     * La dernière période dont le rapport aurait dû partir à ce moment.
     * Pour le rapport du jour, $ouvert écarte les jours de fermeture
     * (dimanche, jours fériés : tuile Horaires) ; null : tous les jours.
     *
     * @param (\Closure(\DateTimeImmutable): bool)|null $ouvert
     */
    public function derniereDue(string $type, \DateTimeImmutable $maintenant, ?\Closure $ouvert = null): PeriodeRapport
    {
        $p = PeriodeRapport::contenant($type, $maintenant);
        // Au plus deux semaines en arrière : un atelier fermé tous les jours reste sans rapport.
        for ($i = 0; $i < 14; ++$i) {
            if (self::compte($p, $ouvert) && $this->moment($p) <= $maintenant) {
                return $p;
            }
            $p = $p->avant();
        }

        return $p;
    }

    /**
     * La période à envoyer maintenant par la tâche planifiée : la dernière
     * due, tant que son heure n'est pas trop loin (RATTRAPAGE). Un ERP
     * remis en route après une longue coupure, ou une première
     * installation, n'envoie pas un rapport vieux de plusieurs jours.
     *
     * @param (\Closure(\DateTimeImmutable): bool)|null $ouvert
     */
    public function aEnvoyer(string $type, \DateTimeImmutable $maintenant, ?\Closure $ouvert = null): ?PeriodeRapport
    {
        $p = $this->derniereDue($type, $maintenant, $ouvert);
        $moment = $this->moment($p);
        if ($moment > $maintenant || !self::compte($p, $ouvert)) {
            return null;
        }

        return $moment->modify(self::RATTRAPAGE[$type]) > $maintenant ? $p : null;
    }

    /**
     * Prochain envoi après ce moment, pour l'écran (« samedi 17/10 à 20 h »).
     *
     * @param (\Closure(\DateTimeImmutable): bool)|null $ouvert
     */
    public function prochain(string $type, \DateTimeImmutable $maintenant, ?\Closure $ouvert = null): \DateTimeImmutable
    {
        $p = PeriodeRapport::contenant($type, $maintenant)->avant();
        for ($i = 0; $i < 16; ++$i) {
            if (self::compte($p, $ouvert) && $this->moment($p) > $maintenant) {
                return $this->moment($p);
            }
            $p = $p->apres();
        }

        return $this->moment($p);
    }

    /** Un jour de fermeture n'a pas de rapport du jour ; les semaines et les mois en ont toujours un. */
    private static function compte(PeriodeRapport $p, ?\Closure $ouvert): bool
    {
        return PeriodeRapport::JOUR !== $p->type || null === $ouvert || $ouvert($p->debut);
    }

    /**
     * Le rapport du jour réglé avant midi (« 07:00 ») part le lendemain
     * matin pour la veille ; réglé l'après-midi ou le soir (« 20:00 »), le
     * jour même pour la journée en cours.
     */
    public static function lendemain(string $heure): bool
    {
        return self::lireHeure($heure)[0] < self::MIDI;
    }

    /**
     * « 08:30 » → [8, 30] ; une heure illisible vaut minuit.
     *
     * @return array{0: int, 1: int}
     */
    public static function lireHeure(string $heure): array
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $heure, $m)) {
            return [0, 0];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** « 20 h », « 7 h 30 ». */
    public static function heureLisible(string $heure): string
    {
        [$h, $m] = self::lireHeure($heure);

        return $h.' h'.($m > 0 ? \sprintf(' %02d', $m) : '');
    }
}
