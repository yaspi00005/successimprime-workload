<?php

namespace App\Service\Atelier;

use App\Entity\OrdreProduction;

/**
 * Calculs de l'atelier de production (docs/atelier-production.md), sans base
 * de données, testés seuls : avancement et fin estimée d'un travail, retard
 * sur la date promise, taux de gâche, enchaînement d'une file sur les heures
 * d'ouverture, ordre automatique de la file, charge d'une machine.
 *
 * Réutilisable par le poste machiniste : tout part des champs existants de
 * l'ordre (démarrage, pauses, durée estimée, date limite).
 */
final class AvancementOrdre
{
    /** Durée d'un travail sans estimation ni vitesse de machine. */
    public const DUREE_PAR_DEFAUT = 60;
    /** Objectif de gâche de l'atelier (%), celui du barème des points. */
    public const OBJECTIF_GACHE = 3.0;
    public const HEURE_OUVERTURE = 8;
    public const HEURE_FERMETURE = 19;

    /** Durée prévue en minutes : l'estimation de l'ordre, sinon surface ÷ vitesse de la machine, sinon une heure. */
    public static function dureeEstimee(OrdreProduction $ordre): int
    {
        if (null !== $ordre->getDureeEstimeeMinutes() && $ordre->getDureeEstimeeMinutes() > 0) {
            return $ordre->getDureeEstimeeMinutes();
        }
        $vitesse = (float) ($ordre->getMachine()?->getVitesseM2Heure() ?? 0);
        $surface = self::surface($ordre);

        return $vitesse > 0 && null !== $surface && $surface > 0 ? max(15, (int) ceil(60 * $surface / $vitesse)) : self::DUREE_PAR_DEFAUT;
    }

    /** Surface du travail en m² (largeur × longueur × quantité), null sans dimensions. */
    public static function surface(OrdreProduction $ordre): ?float
    {
        $ligne = $ordre->getCommandeDetail();
        if (null === $ligne) {
            return null;
        }
        $largeur = (float) ($ligne->getLargeur() ?? 0);
        $longueur = (float) ($ligne->getLongueur() ?? 0);
        if ($largeur <= 0 || $longueur <= 0) {
            return null;
        }

        return round($largeur * $longueur * ($ordre->getQuantite() ?? $ligne->getQuantite()), 2);
    }

    /** Minutes de travail effectif depuis le démarrage, pauses retirées (la pause en cours aussi). */
    public static function minutesEffectives(OrdreProduction $ordre, \DateTimeImmutable $maintenant): int
    {
        $debut = $ordre->getDemarreLe();
        if (null === $debut) {
            return 0;
        }
        $fin = $ordre->getTermineLe() ?? $maintenant;
        $pause = $ordre->getDureePauseMinutes() * 60;
        if (null !== $ordre->getPauseDebuteLe() && null === $ordre->getTermineLe()) {
            $pause += max(0, $maintenant->getTimestamp() - $ordre->getPauseDebuteLe()->getTimestamp());
        }

        return max(0, intdiv($fin->getTimestamp() - $debut->getTimestamp() - $pause, 60));
    }

    /**
     * Avancement d'un travail : pourcentage (99 au plus tant qu'il n'est pas
     * terminé), minutes restantes et fin estimée. Un travail en pause garde
     * son reste : sa fin estimée recule avec la pause.
     *
     * @return array{pourcent: int, restantes: int, finEstimee: ?\DateTimeImmutable, effectives: int, duree: int}
     */
    public static function avancement(OrdreProduction $ordre, \DateTimeImmutable $maintenant): array
    {
        $duree = self::dureeEstimee($ordre);
        if ($ordre->estTermine()) {
            return ['pourcent' => 100, 'restantes' => 0, 'finEstimee' => $ordre->getTermineLe(), 'effectives' => self::minutesEffectives($ordre, $maintenant), 'duree' => $duree];
        }
        if (null === $ordre->getDemarreLe()) {
            return ['pourcent' => 0, 'restantes' => $duree, 'finEstimee' => null, 'effectives' => 0, 'duree' => $duree];
        }
        $effectives = self::minutesEffectives($ordre, $maintenant);
        // Un travail qui dépasse sa durée prévue garde 5 minutes de reste : il n'est pas fini tant qu'on ne l'a pas dit.
        $restantes = max(5, $duree - $effectives);

        return [
            'pourcent' => min(99, (int) floor(100 * $effectives / max(1, $duree))),
            'restantes' => $restantes,
            'finEstimee' => $maintenant->modify('+'.$restantes.' minutes'),
            'effectives' => $effectives,
            'duree' => $duree,
        ];
    }

    /** Date promise au client : la date limite de l'ordre, sinon la livraison prévue de la commande. */
    public static function datePromise(OrdreProduction $ordre): ?\DateTimeImmutable
    {
        $date = $ordre->getDateLimite() ?? $ordre->getCommandeDetail()?->getCommande()?->getDateLivraison();

        return null !== $date ? \DateTimeImmutable::createFromInterface($date) : null;
    }

    /**
     * Retard en minutes sur la date promise : déjà dépassée, ou fin estimée
     * après elle. Une date promise sans heure vaut jusqu'à la fermeture.
     */
    public static function retardMinutes(?\DateTimeImmutable $promis, ?\DateTimeImmutable $finEstimee, \DateTimeImmutable $maintenant, bool $termine = false): int
    {
        if ($termine || null === $promis) {
            return 0;
        }
        if ('00:00' === $promis->format('H:i')) {
            $promis = $promis->setTime(self::HEURE_FERMETURE, 0);
        }
        $reference = null !== $finEstimee && $finEstimee > $maintenant ? $finEstimee : $maintenant;

        return max(0, intdiv($reference->getTimestamp() - $promis->getTimestamp(), 60));
    }

    /** Taux de gâche en % : gâche ÷ (bon + gâche), une décimale ; null sans production. */
    public static function tauxGache(float $bon, float $gache): ?float
    {
        $total = max(0.0, $bon) + max(0.0, $gache);

        return $total > 0 ? round(100 * max(0.0, $gache) / $total, 1) : null;
    }

    /**
     * Enchaîne les travaux d'une file à partir d'un moment, sur les heures
     * d'ouverture (8 h – 19 h, du lundi au samedi) : un travail commencé le
     * soir continue le lendemain matin.
     *
     * @param list<int> $durees minutes de chaque travail, dans l'ordre
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}>
     */
    public static function enchainer(array $durees, \DateTimeImmutable $depart): array
    {
        $creneaux = [];
        $curseur = self::ouvert($depart);
        foreach ($durees as $minutes) {
            $debut = $curseur;
            $reste = max(1, $minutes);
            while ($reste > 0) {
                $fermeture = $curseur->setTime(self::HEURE_FERMETURE, 0);
                $possible = intdiv($fermeture->getTimestamp() - $curseur->getTimestamp(), 60);
                if ($reste <= $possible) {
                    $curseur = $curseur->modify('+'.$reste.' minutes');
                    $reste = 0;
                } else {
                    $reste -= $possible;
                    $curseur = self::ouvert($fermeture->modify('+1 minute'));
                }
            }
            $creneaux[] = ['debut' => $debut, 'fin' => $curseur];
        }

        return $creneaux;
    }

    /**
     * Place les travaux d'une file : ceux qui ont un début prévu dans le futur
     * (déplacés dans le planning) gardent leur créneau ; les autres
     * s'enchaînent dans l'ordre de la file à partir de $depart, en sautant
     * les créneaux déjà pris.
     *
     * @param list<array{duree: int, fixe?: ?\DateTimeImmutable}> $travaux
     *
     * @return list<array{debut: \DateTimeImmutable, fin: \DateTimeImmutable}>
     */
    public static function placer(array $travaux, \DateTimeImmutable $depart): array
    {
        $places = [];
        $pris = [];
        foreach ($travaux as $i => $t) {
            $fixe = $t['fixe'] ?? null;
            if (null !== $fixe && $fixe > $depart) {
                $places[$i] = self::enchainer([$t['duree']], $fixe)[0];
                $pris[] = $places[$i];
            }
        }
        usort($pris, static fn (array $a, array $b): int => $a['debut'] <=> $b['debut']);
        $curseur = $depart;
        foreach ($travaux as $i => $t) {
            if (isset($places[$i])) {
                continue;
            }
            for ($essai = 0; $essai <= \count($pris); ++$essai) {
                $creneau = self::enchainer([$t['duree']], $curseur)[0];
                $chevauche = null;
                foreach ($pris as $p) {
                    if ($creneau['debut'] < $p['fin'] && $creneau['fin'] > $p['debut']) {
                        $chevauche = $p;
                        break;
                    }
                }
                if (null === $chevauche) {
                    break;
                }
                $curseur = $chevauche['fin'];
            }
            $places[$i] = $creneau;
            $curseur = $creneau['fin'];
        }
        ksort($places);

        return array_values($places);
    }

    /**
     * État d'une tâche d'entretien préventif : « ok », « bientot » (80 % de
     * l'intervalle passé) ou « a_faire ». Pour les heures et les m², $usage
     * est ce qui a été produit depuis le dernier passage.
     */
    public static function etatEntretien(string $frequence, int $intervalle, ?\DateTimeImmutable $derniere, float $usage, \DateTimeImmutable $maintenant): string
    {
        if (null === $derniere) {
            return 'a_faire';
        }
        $intervalle = max(1, $intervalle);
        if (\in_array($frequence, ['heures', 'm2'], true)) {
            $part = $usage / $intervalle;
        } elseif ('jour' === $frequence && 1 === $intervalle) {
            return $derniere->format('Y-m-d') === $maintenant->format('Y-m-d') ? 'ok' : 'a_faire';
        } else {
            $echeance = $derniere->modify(match ($frequence) {
                'semaine' => '+'.(7 * $intervalle).' days',
                'mois' => '+'.$intervalle.' months',
                default => '+'.$intervalle.' days',
            });
            $total = max(1, $echeance->getTimestamp() - $derniere->getTimestamp());
            $part = ($maintenant->getTimestamp() - $derniere->getTimestamp()) / $total;
        }

        return match (true) {
            $part >= 1 => 'a_faire',
            $part >= 0.8 => 'bientot',
            default => 'ok',
        };
    }

    /** Le premier moment ouvert à partir de $moment (lundi à samedi, 8 h – 19 h). */
    public static function ouvert(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        for ($i = 0; $i < 8; ++$i) {
            if ('7' === $moment->format('N')) {
                $moment = $moment->modify('+1 day')->setTime(self::HEURE_OUVERTURE, 0);
                continue;
            }
            $ouverture = $moment->setTime(self::HEURE_OUVERTURE, 0);
            if ($moment < $ouverture) {
                return $ouverture;
            }
            if ($moment < $moment->setTime(self::HEURE_FERMETURE, 0)) {
                return $moment;
            }
            $moment = $moment->modify('+1 day')->setTime(self::HEURE_OUVERTURE, 0);
        }

        return $moment;
    }

    /**
     * Ordre automatique d'une file (maquette AT02) : le travail en cours
     * d'abord, puis les retards, puis par date promise ; le même jour, la
     * même matière groupée (moins de changements de rouleau) ; les travaux
     * bloqués restent en bas.
     *
     * @param list<array{id: int, enCours?: bool, retard?: bool, bloque?: bool, promis?: ?\DateTimeImmutable, matiere?: ?string}> $travaux
     *
     * @return list<int>
     */
    public static function ordreAutomatique(array $travaux): array
    {
        $enCours = $retards = $suivants = $bloques = [];
        foreach ($travaux as $rang => $t) {
            $t['rang'] = $rang;
            match (true) {
                (bool) ($t['enCours'] ?? false) => $enCours[] = $t,
                (bool) ($t['bloque'] ?? false) => $bloques[] = $t,
                (bool) ($t['retard'] ?? false) => $retards[] = $t,
                default => $suivants[] = $t,
            };
        }
        $parDate = static fn (array $a, array $b): int => [($a['promis'] ?? null)?->getTimestamp() ?? \PHP_INT_MAX, $a['rang']] <=> [($b['promis'] ?? null)?->getTimestamp() ?? \PHP_INT_MAX, $b['rang']];
        usort($retards, $parDate);
        usort($suivants, $parDate);
        usort($bloques, $parDate);

        // Même jour promis : la matière du travail précédent d'abord, puis les autres dans l'ordre d'arrivée.
        $groupes = [];
        foreach ($suivants as $t) {
            $groupes[($t['promis'] ?? null)?->format('Y-m-d') ?? 'sans'][] = $t;
        }
        $precedente = [] !== $retards ? ($retards[\count($retards) - 1]['matiere'] ?? null) : ([] !== $enCours ? ($enCours[0]['matiere'] ?? null) : null);
        $tries = [];
        foreach ($groupes as $jour) {
            $parMatiere = [];
            foreach ($jour as $t) {
                $parMatiere[mb_strtolower((string) ($t['matiere'] ?? ''))][] = $t;
            }
            $cle = mb_strtolower((string) $precedente);
            if (isset($parMatiere[$cle])) {
                $parMatiere = [$cle => $parMatiere[$cle]] + $parMatiere;
            }
            foreach ($parMatiere as $lot) {
                foreach ($lot as $t) {
                    $tries[] = $t;
                    $precedente = $t['matiere'] ?? null;
                }
            }
        }

        return array_map(static fn (array $t): int => (int) $t['id'], [...$enCours, ...$retards, ...$tries, ...$bloques]);
    }

    /**
     * Changements de rouleau dans une file : chaque fois que la matière change
     * d'un travail à l'autre (les travaux sans matière ne comptent pas).
     *
     * @param list<?string> $matieres
     */
    public static function changementsRouleau(array $matieres): int
    {
        $changements = 0;
        $precedente = null;
        foreach ($matieres as $matiere) {
            if (null === $matiere || '' === trim($matiere)) {
                continue;
            }
            if (null !== $precedente && mb_strtolower($precedente) !== mb_strtolower($matiere)) {
                ++$changements;
            }
            $precedente = $matiere;
        }

        return $changements;
    }

    /** Charge d'une machine en % des minutes ouvertes (bornée à 100 pour l'affichage, sauf $brute). */
    public static function charge(int $minutesPrevues, int $minutesOuvertes, bool $brute = false): int
    {
        if ($minutesOuvertes <= 0) {
            return 0;
        }
        $charge = (int) round(100 * max(0, $minutesPrevues) / $minutesOuvertes);

        return $brute ? $charge : min(100, $charge);
    }

    /** Minutes ouvertes d'une journée (le dimanche est fermé). */
    public static function minutesOuvertes(\DateTimeImmutable $jour): int
    {
        return '7' === $jour->format('N') ? 0 : (self::HEURE_FERMETURE - self::HEURE_OUVERTURE) * 60;
    }

    /** « 3 h 20 », « 45 min », « 2 jours ». */
    public static function duree(int $minutes): string
    {
        if ($minutes >= 24 * 60) {
            $jours = (int) round($minutes / (24 * 60));

            return $jours.' jour'.($jours > 1 ? 's' : '');
        }
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.(0 === $minutes % 60 ? '' : ' '.str_pad((string) ($minutes % 60), 2, '0', \STR_PAD_LEFT));
    }
}
