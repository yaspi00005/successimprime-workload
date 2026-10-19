<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Notification;

/**
 * Règles anti-bruit et rythme des notifications (maquette NT02), sans base
 * de données : testées seules (tests/Unit/Service/NotificationsEquipe).
 *
 * - heures calmes : seules les critiques sonnent, s'affichent en petite
 *   fenêtre et partent sur le téléphone ; les autres attendent dans la liste ;
 * - petite fenêtre : à l'arrivée pour l'urgent et l'à faire, puis toutes les
 *   30 min pour l'urgent tant que rien n'est fait ; jamais pour l'info ;
 * - remontée : urgent non traité après N min au responsable du rôle, puis
 *   à la direction ; critique non traité après N min à la direction.
 */
final class ReglesNotifications
{
    /**
     * @param array{debut: string, fin: string, jours: list<int>, jours_entiers: list<int>} $calme
     */
    public static function estCalme(\DateTimeImmutable $moment, array $calme): bool
    {
        $jour = (int) $moment->format('w');
        if (\in_array($jour, $calme['jours_entiers'], true)) {
            return true;
        }
        $minute = (int) $moment->format('H') * 60 + (int) $moment->format('i');
        $debut = self::minutes($calme['debut']);
        $fin = self::minutes($calme['fin']);
        // Le soir d'un jour concerné…
        if (\in_array($jour, $calme['jours'], true) && $minute >= $debut) {
            return true;
        }
        // … et le matin qui suit, jusqu'à l'heure de fin (la nuit appartient à la veille).
        $veille = ($jour + 6) % 7;

        return $minute < $fin && (\in_array($veille, $calme['jours'], true) || \in_array($veille, $calme['jours_entiers'], true) || \in_array($jour, $calme['jours'], true));
    }

    /** Une notification de ce niveau dérange-t-elle (téléphone, son, petite fenêtre) à ce moment ? */
    public static function peutDeranger(string $niveau, bool $calme): bool
    {
        return Notification::CRITIQUE === $niveau || (!$calme && Notification::INFO !== $niveau);
    }

    /**
     * Petite fenêtre à montrer maintenant ? À l'arrivée pour l'urgent et
     * l'à faire ; l'urgent revient toutes les $rappel minutes tant qu'il
     * n'est pas traité. Le critique a sa bannière (le son seulement à
     * l'arrivée), l'info jamais.
     */
    public static function petiteFenetre(Notification $n, \DateTimeImmutable $maintenant, bool $calme, int $rappelMinutes): bool
    {
        if (!$n->estActive() || $n->estReportee($maintenant) || null === $n->getType()) {
            return false;
        }
        $niveau = $n->getNiveau();
        if (!\in_array($niveau, [Notification::URGENT, Notification::A_FAIRE], true) || $calme) {
            return false;
        }
        if (null === $n->getAlerteeLe()) {
            return true;
        }

        return Notification::URGENT === $niveau && $n->getAlerteeLe()->getTimestamp() + $rappelMinutes * 60 <= $maintenant->getTimestamp();
    }

    /** Son à l'arrivée d'une critique (une fois ; la bannière reste). */
    public static function sonCritique(Notification $n, \DateTimeImmutable $maintenant): bool
    {
        return Notification::CRITIQUE === $n->getNiveau() && $n->estActive() && !$n->estReportee($maintenant) && null === $n->getAlerteeLe();
    }

    /**
     * Prochaine remontée due, ou null : 1 vers le responsable du rôle
     * (urgent), 2 vers la direction (critique d'emblée, urgent ensuite).
     * Une notification reportée n'est pas remontée : quelqu'un l'a vue et a
     * décidé ; à la fin du report, le délai repart.
     *
     * @param array{urgent_minutes: int, critique_minutes: int, direction_minutes: int} $escalade
     */
    public static function remontee(Notification $n, \DateTimeImmutable $maintenant, array $escalade): ?int
    {
        if (!$n->estActive() || $n->estReportee($maintenant) || null === $n->getType()) {
            return null;
        }
        $depart = max($n->getDateCreation()?->getTimestamp() ?? $maintenant->getTimestamp(), $n->getReporteeJusqua()?->getTimestamp() ?? 0, $n->getEscaladeeLe()?->getTimestamp() ?? 0);
        $ecoule = intdiv($maintenant->getTimestamp() - $depart, 60);

        if (Notification::CRITIQUE === $n->getNiveau()) {
            return 0 === $n->getEscalade() && $escalade['critique_minutes'] > 0 && $ecoule >= $escalade['critique_minutes'] ? 2 : null;
        }
        if (Notification::URGENT !== $n->getNiveau()) {
            return null;
        }

        return match ($n->getEscalade()) {
            0 => $escalade['urgent_minutes'] > 0 && $ecoule >= $escalade['urgent_minutes'] ? 1 : null,
            1 => $escalade['direction_minutes'] > 0 && $ecoule >= $escalade['direction_minutes'] ? 2 : null,
            default => null,
        };
    }

    /** Ordre d'affichage : critique, urgent, à faire, info ; la plus récente d'abord. */
    public static function comparer(Notification $a, Notification $b): int
    {
        $rangs = array_flip(array_keys(Notification::NIVEAUX));

        return [$rangs[$a->getNiveau()], -($a->getDerniereFois()?->getTimestamp() ?? 0), -($a->getId() ?? 0)]
            <=> [$rangs[$b->getNiveau()], -($b->getDerniereFois()?->getTimestamp() ?? 0), -($b->getId() ?? 0)];
    }

    /**
     * Fin d'un report : « 1 h », « 3 h », « demain 9:00 » (ou lundi après
     * un samedi, l'imprimerie étant fermée le dimanche).
     */
    public static function finReport(string $duree, \DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        return match ($duree) {
            '1h' => $maintenant->modify('+1 hour'),
            '3h' => $maintenant->modify('+3 hours'),
            default => (static function () use ($maintenant): \DateTimeImmutable {
                $demain = $maintenant->modify('+1 day')->setTime(9, 0);

                return '0' === $demain->format('w') ? $demain->modify('+1 day') : $demain;
            })(),
        };
    }

    private static function minutes(string $heure): int
    {
        [$h, $m] = array_map('intval', explode(':', $heure.':0'));

        return $h * 60 + $m;
    }
}
