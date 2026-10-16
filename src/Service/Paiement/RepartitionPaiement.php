<?php

namespace App\Service\Paiement;

use App\Entity\ParametresPaiement;

/**
 * Répartition d'un règlement de plusieurs factures et acomptes (« Factures
 * et paiements », maquette FP03), calculée par le serveur seul :
 *
 *  - l'avoir choisi va d'abord à la plus ancienne échéance, puis aux
 *    suivantes ;
 *  - le montant payé par Orange Money (tout, ou « Autre montant » à partir
 *    de MINIMUM_PARTIEL) couvre ensuite ce qui reste, dans le même ordre ;
 *  - les frais Orange Money se calculent une fois sur ce montant (comme un
 *    seul paiement chez Orange), puis se partagent entre les commandes au
 *    prorata de leur part, au franc près (plus forts restes).
 */
final class RepartitionPaiement
{
    /** « Autre montant » : pas moins de 1 000 F (sauf s'il reste moins à payer). */
    public const MINIMUM_PARTIEL = 1000;

    /**
     * @param array<string, int> $dus ce qui est demandé pour chaque élément choisi, la plus ancienne échéance d'abord
     * @param bool               $avecAvoir le client utilise son avoir
     * @param ?int               $montantOm « Autre montant » saisi ; null : tout payer
     *
     * @return array{total: int, avoir: int, omMax: int, om: int, frais: FraisOrangeMoney, parts: array<string, array{om: int, avoir: int, fondsSoutien: int, fraisRetrait: int}>}
     *
     * @throws \DomainException message à montrer au client
     */
    public static function planifier(array $dus, int $avoirDisponible, bool $avecAvoir, ?int $montantOm, ParametresPaiement $parametres): array
    {
        $dus = array_filter(array_map(static fn (int $m): int => max(0, $m), $dus));
        if ([] === $dus) {
            throw new \DomainException('Choisissez au moins une facture ou un acompte à payer.');
        }
        $total = array_sum($dus);
        $avoir = $avecAvoir ? min(max(0, $avoirDisponible), $total) : 0;
        $omMax = $total - $avoir;
        $om = $montantOm ?? $omMax;
        if (null !== $montantOm && null !== ($refus = self::refusMontant($montantOm, $omMax))) {
            throw new \DomainException($refus);
        }
        $frais = FraisOrangeMoney::calculer($om, $parametres);

        $parAvoir = self::dansLOrdre($dus, $avoir);
        $restes = array_map(static fn (int $du, string $cle): int => $du - $parAvoir[$cle], $dus, array_keys($dus));
        $parOm = self::dansLOrdre(array_combine(array_keys($dus), $restes), $om);
        $fonds = self::proportionnel($frais->fondsSoutien, $parOm);
        $retrait = self::proportionnel($frais->fraisRetrait, $parOm);

        $parts = [];
        foreach (array_keys($dus) as $cle) {
            if ($parAvoir[$cle] + $parOm[$cle] > 0) {
                $parts[$cle] = ['om' => $parOm[$cle], 'avoir' => $parAvoir[$cle], 'fondsSoutien' => $fonds[$cle], 'fraisRetrait' => $retrait[$cle]];
            }
        }

        return ['total' => $total, 'avoir' => $avoir, 'omMax' => $omMax, 'om' => $om, 'frais' => $frais, 'parts' => $parts];
    }

    /** Pourquoi ce montant partiel n'est pas possible, ou null s'il l'est. */
    public static function refusMontant(int $montant, int $maximum): ?string
    {
        $minimum = min(self::MINIMUM_PARTIEL, $maximum);

        return match (true) {
            $maximum <= 0 => 'Rien à payer par Orange Money : votre avoir couvre tout.',
            $montant > $maximum => \sprintf('Le montant ne peut pas dépasser %s F.', self::francs($maximum)),
            $montant < $minimum || $montant <= 0 => \sprintf('Le montant doit être d\'au moins %s F.', self::francs($minimum)),
            default => null,
        };
    }

    /**
     * Verse $montant aux éléments dans leur ordre, chacun jusqu'à ce qui lui est dû.
     *
     * @param array<string, int> $dus
     *
     * @return array<string, int>
     */
    public static function dansLOrdre(array $dus, int $montant): array
    {
        $reste = max(0, $montant);
        $verse = [];
        foreach ($dus as $cle => $du) {
            $verse[$cle] = min(max(0, $du), $reste);
            $reste -= $verse[$cle];
        }

        return $verse;
    }

    /**
     * Partage $total au prorata des poids, en francs entiers dont la somme
     * fait exactement $total (méthode des plus forts restes ; à égalité,
     * le premier élément l'emporte).
     *
     * @param array<string, int> $poids
     *
     * @return array<string, int>
     */
    public static function proportionnel(int $total, array $poids): array
    {
        $somme = array_sum(array_map(static fn (int $p): int => max(0, $p), $poids));
        $parts = array_map(static fn (): int => 0, $poids);
        if ($total <= 0 || $somme <= 0) {
            return $parts;
        }
        $restes = [];
        foreach ($poids as $cle => $p) {
            $exact = $total * max(0, $p) / $somme;
            $parts[$cle] = (int) floor($exact);
            $restes[$cle] = $exact - $parts[$cle];
        }
        $aDonner = $total - array_sum($parts);
        $ordre = array_keys($restes);
        usort($ordre, static fn (string|int $a, string|int $b): int => [$restes[$b], array_search($a, array_keys($poids), true)] <=> [$restes[$a], array_search($b, array_keys($poids), true)]);
        foreach (\array_slice($ordre, 0, $aDonner) as $cle) {
            ++$parts[$cle];
        }

        return $parts;
    }

    private static function francs(int $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
