<?php

namespace App\Service\Statistiques;

use Doctrine\ORM\QueryBuilder;

/**
 * Activité comptée par les statistiques (docs/hors-imprimerie.md) :
 * l'imprimerie seule par défaut, les ventes « hors imprimerie »
 * (matériel, informatique… : case du devis et de la commande) à part, ou
 * tout. Une facture sans commande compte pour l'imprimerie.
 *
 * Règles pures, pour être testées sans base.
 */
final class Activite
{
    public const IMPRIMERIE = 'imprimerie';
    public const HORS = 'hors';
    public const TOUT = 'tout';

    public const LIBELLES = [
        self::IMPRIMERIE => 'Imprimerie',
        self::HORS => 'Hors imprimerie',
        self::TOUT => 'Tout',
    ];

    /** Le filtre « activite » d'une adresse ; l'imprimerie sans valeur connue. */
    public static function lire(mixed $valeur): string
    {
        return \is_string($valeur) && isset(self::LIBELLES[$valeur]) ? $valeur : self::IMPRIMERIE;
    }

    /** Une vente (hors imprimerie ou non) entre-t-elle dans l'activité choisie ? */
    public static function retient(bool $horsImprimerie, string $activite): bool
    {
        return match ($activite) {
            self::HORS => $horsImprimerie,
            self::TOUT => true,
            default => !$horsImprimerie,
        };
    }

    /**
     * Ajoute le filtre à une requête sur l'alias de la commande. Avec
     * $commandeFacultative (facture en jointure gauche sur sa commande),
     * une facture sans commande reste dans l'imprimerie.
     */
    public static function filtrer(QueryBuilder $qb, string $commande, string $activite, bool $commandeFacultative = false): QueryBuilder
    {
        return match ($activite) {
            self::HORS => $qb->andWhere(\sprintf('%s.horsImprimerie = true', $commande)),
            self::TOUT => $qb,
            default => $qb->andWhere($commandeFacultative
                ? \sprintf('(%1$s.id IS NULL OR %1$s.horsImprimerie = false)', $commande)
                : \sprintf('%s.horsImprimerie = false', $commande)),
        };
    }

    /**
     * Carte « Imprimerie / Hors imprimerie / Total » d'une période. Le total
     * additionne chaque chiffre des deux côtés (null si l'un manque) ; ce
     * qui ne s'additionne pas (un taux de marge) vient de $total. Avec un
     * CA, chaque côté reçoit sa part du total en %.
     *
     * @param array<string, int|float|null> $imprimerie
     * @param array<string, int|float|null> $hors
     * @param array<string, mixed>          $total chiffres du total déjà calculés
     *
     * @return array{imprimerie: array<string, mixed>, hors: array<string, mixed>, total: array<string, mixed>}
     */
    public static function repartition(array $imprimerie, array $hors, array $total = []): array
    {
        foreach ($imprimerie as $cle => $valeur) {
            if (\array_key_exists($cle, $total) || !\array_key_exists($cle, $hors)) {
                continue;
            }
            $total[$cle] = null === $valeur || null === $hors[$cle] ? null : $valeur + $hors[$cle];
        }

        $ca = $total['ca'] ?? null;
        if (\is_int($ca) || \is_float($ca)) {
            $imprimerie['part'] = $ca > 0 ? (int) round(($imprimerie['ca'] ?? 0) * 100 / $ca) : null;
            $hors['part'] = $ca > 0 ? 100 - $imprimerie['part'] : null;
        }

        return ['imprimerie' => $imprimerie, 'hors' => $hors, 'total' => $total];
    }
}
