<?php

namespace App\Service\Clients;

/**
 * Calculs de la fiche client (maquettes FC01 à FC04, docs/clients.md),
 * sans base de données : panier moyen, évolution, plafond utilisé,
 * chiffre d'affaires par mois, délai de paiement moyen, activité par
 * semaine, satisfaction. Testés dans tests/Unit/Service/Clients.
 */
final class CalculsFicheClient
{
    /** Au-delà de cette part du plafond, la jauge passe à l'orange puis au rouge. */
    public const PLAFOND_ATTENTION = 60;
    public const PLAFOND_ALERTE = 90;

    /** Délai de paiement accordé, en jours, d'après Clients::DELAIS_PAIEMENT. */
    public const JOURS_DELAI = [
        'comptant' => 0,
        '15_jours' => 15,
        '30_jours' => 30,
        '30_jours_fdm' => 30,
        '45_jours' => 45,
        '60_jours' => 60,
    ];

    /**
     * Montant moyen d'une commande, arrondi au franc inférieur (0 sans commande).
     *
     * @param list<int> $montants
     */
    public static function panierMoyen(array $montants): int
    {
        return [] === $montants ? 0 : intdiv(array_sum($montants), \count($montants));
    }

    /** Évolution en % (arrondie) ; null sans base de comparaison. */
    public static function evolution(int $actuel, int $precedent): ?int
    {
        return $precedent > 0 ? (int) round(($actuel - $precedent) * 100 / $precedent) : null;
    }

    /**
     * Part du plafond de crédit prise par ce que le client doit.
     * Sans plafond (null ou 0), aucune part n'est calculée.
     *
     * @return array{plafond: int, encours: int, pourcentage: ?int, jauge: int, disponible: int, depassement: int, niveau: string}
     */
    public static function plafondUtilise(int $encours, ?int $plafond): array
    {
        $encours = max(0, $encours);
        $plafond = max(0, (int) $plafond);
        if (0 === $plafond) {
            return ['plafond' => 0, 'encours' => $encours, 'pourcentage' => null, 'jauge' => 0, 'disponible' => 0, 'depassement' => 0, 'niveau' => 'aucun'];
        }
        $pourcentage = (int) round($encours * 100 / $plafond);

        return [
            'plafond' => $plafond,
            'encours' => $encours,
            'pourcentage' => $pourcentage,
            'jauge' => min(100, $pourcentage),
            'disponible' => max(0, $plafond - $encours),
            'depassement' => max(0, $encours - $plafond),
            'niveau' => match (true) {
                $encours > $plafond || $pourcentage >= self::PLAFOND_ALERTE => 'alerte',
                $pourcentage >= self::PLAFOND_ATTENTION => 'attention',
                default => 'ok',
            },
        ];
    }

    /**
     * Chiffre d'affaires de chaque mois d'une année (1 à 12).
     *
     * @param list<array{date: \DateTimeInterface, montant: int}> $ventes
     *
     * @return array<int, int>
     */
    public static function caParMois(array $ventes, int $annee): array
    {
        $mois = array_fill(1, 12, 0);
        foreach ($ventes as $v) {
            if ((int) $v['date']->format('Y') === $annee) {
                $mois[(int) $v['date']->format('n')] += $v['montant'];
            }
        }

        return $mois;
    }

    /**
     * Délai moyen entre la date du document (facture, sinon commande) et le
     * paiement qui l'a soldé, en jours arrondis ; null sans document soldé.
     *
     * @param list<array{debut: \DateTimeInterface, solde: \DateTimeInterface}> $soldes
     */
    public static function delaiPaiementMoyen(array $soldes): ?int
    {
        if ([] === $soldes) {
            return null;
        }
        $jours = 0;
        foreach ($soldes as $s) {
            $jours += max(0, (int) \DateTimeImmutable::createFromInterface($s['debut'])->setTime(0, 0)->diff(\DateTimeImmutable::createFromInterface($s['solde'])->setTime(0, 0))->format('%r%a'));
        }

        return (int) round($jours / \count($soldes));
    }

    /**
     * Date à laquelle un document est soldé : celle du paiement qui fait
     * atteindre le montant (paiements validés, dans l'ordre des dates).
     *
     * @param list<array{date: \DateTimeInterface, montant: int}> $paiements
     */
    public static function dateSolde(int $montant, array $paiements): ?\DateTimeInterface
    {
        if ($montant <= 0) {
            return null;
        }
        usort($paiements, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        $cumul = 0;
        foreach ($paiements as $p) {
            $cumul += $p['montant'];
            if ($cumul >= $montant) {
                return $p['date'];
            }
        }

        return null;
    }

    /**
     * Activité sur 52 semaines (la dernière finit aujourd'hui) : nombre
     * d'événements par semaine, la plus ancienne d'abord.
     *
     * @param list<\DateTimeInterface> $dates
     *
     * @return list<int>
     */
    public static function activiteParSemaine(array $dates, \DateTimeImmutable $maintenant, int $semaines = 52): array
    {
        $compte = array_fill(0, $semaines, 0);
        $fin = $maintenant->setTime(23, 59, 59)->getTimestamp();
        foreach ($dates as $d) {
            $ecart = $fin - $d->getTimestamp();
            if ($ecart < 0) {
                continue;
            }
            $rang = intdiv($ecart, 7 * 86400);
            if ($rang < $semaines) {
                ++$compte[$semaines - 1 - $rang];
            }
        }

        return $compte;
    }

    /**
     * Intensité 0 à 4 d'une case de l'activité (0 : rien ; 4 : la semaine la plus chargée).
     */
    public static function intensite(int $nombre, int $maximum): int
    {
        if ($nombre <= 0 || $maximum <= 0) {
            return 0;
        }

        return max(1, min(4, (int) ceil($nombre * 4 / $maximum)));
    }

    /**
     * Note moyenne sur 5 (une décimale) et nombre d'avis.
     *
     * @param list<int|null> $notes
     *
     * @return array{moyenne: ?float, avis: int}
     */
    public static function satisfaction(array $notes): array
    {
        $notes = array_values(array_filter($notes, static fn (?int $n): bool => null !== $n && $n >= 1 && $n <= 5));

        return ['moyenne' => [] === $notes ? null : round(array_sum($notes) / \count($notes), 1), 'avis' => \count($notes)];
    }

    /** Jours accordés pour payer (« 30 jours fin de mois » : 30) ; null sans délai noté. */
    public static function joursAccordes(?string $delaiPaiement): ?int
    {
        return null === $delaiPaiement ? null : (self::JOURS_DELAI[$delaiPaiement] ?? null);
    }

    /** « il y a 5 jours », « aujourd'hui », « hier ». */
    public static function ilYA(?int $jours): ?string
    {
        return match (true) {
            null === $jours => null,
            $jours <= 0 => 'aujourd\'hui',
            1 === $jours => 'hier',
            default => 'il y a '.$jours.' jours',
        };
    }
}
