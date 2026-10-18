<?php

namespace App\Service\Planning;

/**
 * Calcul des dates du planning (docs/planning-taches.md, T02) : répétition
 * d'une échéance et décalage des week-ends et jours fériés. Classe sans
 * base de données : les fêtes mobiles (Ramadan, Tabaski, Maouloud) sont
 * passées au constructeur, les fêtes fixes du Mali sont ici.
 */
final class Calendrier
{
    public const JOUR = 'jour';
    public const SEMAINE = 'semaine';
    public const MOIS = 'mois';
    public const TRIMESTRE = 'trimestre';
    public const ANNEE = 'annee';
    public const UNE_FOIS = 'une_fois';

    public const FREQUENCES = [
        self::JOUR => 'Chaque jour',
        self::SEMAINE => 'Chaque semaine',
        self::MOIS => 'Chaque mois',
        self::TRIMESTRE => 'Chaque trimestre',
        self::ANNEE => 'Chaque année',
        self::UNE_FOIS => 'Une fois',
    ];

    public const AVANT = 'avant';
    public const APRES = 'apres';
    public const GARDER = 'garder';

    public const DECALAGES = [
        self::AVANT => 'Jour ouvré précédent',
        self::APRES => 'Jour ouvré suivant',
        self::GARDER => 'Garder la date',
    ];

    /** Fêtes fixes du Mali (mois-jour). */
    public const FETES_FIXES = [
        '01-01' => 'Jour de l\'an',
        '01-20' => 'Fête de l\'armée',
        '03-26' => 'Journée des martyrs',
        '05-01' => 'Fête du travail',
        '05-25' => 'Journée de l\'Afrique',
        '09-22' => 'Fête de l\'indépendance',
        '12-25' => 'Noël',
    ];

    public const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /** Garde-fou : nombre maximum de dates calculées en une fois. */
    private const MAX = 800;

    /**
     * @param array<string, string> $fetesMobiles 'Y-m-d' => libellé
     * @param list<int>             $joursOuvres   jours des administrations (décalage), 1 = lundi … 7 = dimanche
     * @param list<int>|null        $joursActivite jours d'ouverture de l'imprimerie (tâches « chaque jour ») ; null = jours ouvrés
     */
    public function __construct(
        private readonly array $fetesMobiles = [],
        private readonly array $joursOuvres = [1, 2, 3, 4, 5],
        private readonly ?array $joursActivite = null,
    ) {
    }

    /** Nom du jour férié, ou null. */
    public function ferie(\DateTimeImmutable $jour): ?string
    {
        return $this->fetesMobiles[$jour->format('Y-m-d')] ?? self::FETES_FIXES[$jour->format('m-d')] ?? null;
    }

    public function estOuvre(\DateTimeImmutable $jour): bool
    {
        return \in_array((int) $jour->format('N'), $this->joursOuvres, true) && $this->ferie($jour) === null;
    }

    /** Pourquoi un jour n'est pas ouvré : « le 25 est un dimanche », « férié : Noël ». */
    public function raison(\DateTimeImmutable $jour): ?string
    {
        if (($ferie = $this->ferie($jour)) !== null) {
            return 'férié : '.$ferie;
        }

        $j = (int) $jour->format('j');

        return $this->estOuvre($jour) ? null : \sprintf('le %s est un %s', $j === 1 ? '1er' : (string) $j, self::JOURS[(int) $jour->format('N')]);
    }

    /**
     * Date effective d'une date prévue selon le décalage choisi.
     *
     * @return array{date: \DateTimeImmutable, raison: ?string}
     */
    public function decaler(\DateTimeImmutable $jour, string $decalage = self::AVANT): array
    {
        $jour = $jour->setTime(0, 0);
        $raison = $this->raison($jour);
        if ($raison === null || $decalage === self::GARDER) {
            return ['date' => $jour, 'raison' => null];
        }
        $pas = $decalage === self::APRES ? '+1 day' : '-1 day';
        $d = $jour;
        for ($i = 0; $i < 30 && !$this->estOuvre($d); ++$i) {
            $d = $d->modify($pas);
        }

        return ['date' => $d, 'raison' => $raison];
    }

    /**
     * Dates d'une échéance entre deux jours (inclus).
     *
     * Règle : frequence, debut (Y-m-d), jourMois (1-31, 0 = dernier jour),
     * joursSemaine (1-7), decalage, finLe (Y-m-d|null), finNombre (int|null).
     * Une échéance « chaque jour » tombe les jours d'ouverture, hors fériés, sans décalage.
     *
     * @param array<string, mixed> $regle
     *
     * @return list<array{origine: \DateTimeImmutable, date: \DateTimeImmutable, raison: ?string}>
     */
    public function occurrences(array $regle, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        $du = $du->setTime(0, 0);
        $au = $au->setTime(0, 0);
        $debut = new \DateTimeImmutable((string) $regle['debut']);
        $fin = !empty($regle['finLe']) ? new \DateTimeImmutable((string) $regle['finLe']) : null;
        $nombre = !empty($regle['finNombre']) ? (int) $regle['finNombre'] : null;
        $decalage = (string) ($regle['decalage'] ?? self::AVANT);
        $resultat = [];
        $rang = 0;
        foreach ($this->origines($regle, $debut) as $origine) {
            if (($fin !== null && $origine > $fin) || ($nombre !== null && $rang >= $nombre) || $rang >= self::MAX) {
                break;
            }
            ++$rang;
            $effective = $regle['frequence'] === self::JOUR ? ['date' => $origine, 'raison' => null] : $this->decaler($origine, $decalage);
            if ($effective['date'] > $au && $origine > $au) {
                break;
            }
            if ($effective['date'] >= $du && $effective['date'] <= $au) {
                $resultat[] = ['origine' => $origine, 'date' => $effective['date'], 'raison' => $effective['raison']];
            }
        }

        return $resultat;
    }

    /**
     * Dates d'origine successives, sans fin.
     *
     * @param array<string, mixed> $regle
     *
     * @return \Generator<\DateTimeImmutable>
     */
    private function origines(array $regle, \DateTimeImmutable $debut): \Generator
    {
        $frequence = (string) ($regle['frequence'] ?? self::UNE_FOIS);
        $jourMois = (int) ($regle['jourMois'] ?? $debut->format('j'));
        switch ($frequence) {
            case self::UNE_FOIS:
                yield $debut;

                return;
            case self::JOUR:
                $jours = $this->joursActivite ?? $this->joursOuvres;
                for ($d = $debut; ; $d = $d->modify('+1 day')) {
                    if (\in_array((int) $d->format('N'), $jours, true) && $this->ferie($d) === null) {
                        yield $d;
                    }
                }
            case self::SEMAINE:
                $jours = array_map('intval', (array) ($regle['joursSemaine'] ?? []) ?: [(int) $debut->format('N')]);
                for ($d = $debut; ; $d = $d->modify('+1 day')) {
                    if (\in_array((int) $d->format('N'), $jours, true)) {
                        yield $d;
                    }
                }
            case self::MOIS:
            case self::TRIMESTRE:
            case self::ANNEE:
                $pas = ['mois' => 1, 'trimestre' => 3, 'annee' => 12][$frequence];
                $mois = $debut->modify('first day of this month');
                for ($i = 0; ; $i += $pas) {
                    $m = $mois->modify(\sprintf('+%d month', $i));
                    $dernier = (int) $m->format('t');
                    $j = $jourMois <= 0 ? $dernier : min($jourMois, $dernier);
                    $d = $m->setDate((int) $m->format('Y'), (int) $m->format('n'), $j);
                    if ($d >= $debut) {
                        yield $d;
                    }
                }
        }
    }

    /** « Le 25 du mois », « Chaque lundi et jeudi »… @param array<string, mixed> $regle */
    public static function resume(array $regle): string
    {
        $jourMois = (int) ($regle['jourMois'] ?? 0);
        $jour = $jourMois <= 0 ? 'le dernier jour' : ($jourMois === 1 ? 'le 1er' : 'le '.$jourMois);

        return match ((string) ($regle['frequence'] ?? '')) {
            self::JOUR => 'Chaque jour d\'ouverture',
            self::SEMAINE => ($regle['joursSemaine'] ?? []) !== [] ? 'Chaque '.implode(' et ', array_map(static fn ($j): string => self::JOURS[(int) $j] ?? '', (array) $regle['joursSemaine'])) : 'Chaque semaine',
            self::MOIS => ucfirst($jour).' de chaque mois',
            self::TRIMESTRE => ucfirst($jour).' de chaque trimestre',
            self::ANNEE => 'Chaque année',
            default => 'Une fois',
        };
    }
}
