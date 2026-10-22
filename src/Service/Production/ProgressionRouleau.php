<?php

namespace App\Service\Production;

use App\Entity\PieceTirage;
use App\Entity\TirageRouleau;

/**
 * Avancement d'un rouleau en impression (PD04, docs/production-rouleau.md).
 * La machine n'a pas d'API : l'avancement vient des « Pièce imprimée » de
 * l'opérateur et s'estime entre deux appuis. Pur, sans base.
 *
 * Temps effectif : depuis le démarrage, pauses retirées. Pièce en cours :
 * temps effectif depuis son début × vitesse du profil, au plus sa surface
 * (« estimé »). Vitesse réelle : m² des pièces confirmées ÷ temps effectif
 * jusqu'à la dernière confirmée. Encre ≈ m² imprimés × ml/m² du profil.
 * Fin : maintenant + m² restants ÷ vitesse réelle (celle du profil tant
 * qu'aucune pièce n'est confirmée).
 */
final class ProgressionRouleau
{
    /**
     * @param list<array{m2: float, statut: string, debut: ?\DateTimeImmutable, fin: ?\DateTimeImmutable}> $pieces dans l'ordre d'impression
     * @param list<array{debut: \DateTimeImmutable, fin: ?\DateTimeImmutable}>                           $pauses
     *
     * @return array{total: int, faites: int, courante: ?int, m2Total: float, m2Confirme: float, m2Fait: float, estime: bool, secondes: int, vitesseReelle: ?float, vitesse: ?float, encreMl: ?int, fin: ?\DateTimeImmutable, pourcent: int}
     */
    public static function calculer(array $pieces, \DateTimeImmutable $demarre, array $pauses, \DateTimeImmutable $maintenant, ?float $vitesseProfil, ?float $encreMlM2): array
    {
        $vitesseProfil = null !== $vitesseProfil && $vitesseProfil > 0 ? $vitesseProfil : null;
        $m2Total = 0.0;
        $m2Confirme = 0.0;
        $faites = 0;
        $courante = null;
        $derniereFin = null;
        foreach (array_values($pieces) as $i => $p) {
            $m2Total += max(0.0, $p['m2']);
            if ('imprimee' === $p['statut']) {
                ++$faites;
                $m2Confirme += max(0.0, $p['m2']);
                if (null !== $p['fin'] && (null === $derniereFin || $p['fin'] > $derniereFin)) {
                    $derniereFin = $p['fin'];
                }
            } elseif (null === $courante && 'en_cours' === $p['statut']) {
                $courante = $i;
            }
        }
        // Une pièce « à imprimer » après les confirmées est la suivante quand aucune n'est marquée en cours.
        if (null === $courante) {
            foreach (array_values($pieces) as $i => $p) {
                if ('imprimee' !== $p['statut']) {
                    $courante = $i;
                    break;
                }
            }
        }

        $secondes = self::secondesEffectives($demarre, $maintenant, $pauses, $maintenant);
        $vitesseReelle = null;
        if ($m2Confirme > 0 && null !== $derniereFin) {
            $jusqua = self::secondesEffectives($demarre, $derniereFin, $pauses, $maintenant);
            $vitesseReelle = $jusqua > 0 ? round($m2Confirme * 3600 / $jusqua, 1) : null;
        }

        $m2Fait = $m2Confirme;
        $estime = false;
        if (null !== $courante && null !== $vitesseProfil) {
            $p = array_values($pieces)[$courante];
            $debutPiece = $p['debut'] ?? $derniereFin ?? $demarre;
            $ecoule = self::secondesEffectives(max($debutPiece, $demarre), $maintenant, $pauses, $maintenant);
            $enCours = min(max(0.0, $p['m2']), $ecoule * $vitesseProfil / 3600);
            if ($enCours > 0) {
                $m2Fait += $enCours;
                $estime = true;
            }
        }
        $m2Fait = round(min($m2Total, $m2Fait), 2);

        $vitesse = $vitesseReelle ?? $vitesseProfil;
        $restant = max(0.0, $m2Total - $m2Fait);
        $fin = null;
        if (null !== $courante && null !== $vitesse && $vitesse > 0) {
            $fin = $maintenant->modify('+'.(int) ceil($restant * 3600 / $vitesse).' seconds');
        }

        return [
            'total' => \count($pieces),
            'faites' => $faites,
            'courante' => null !== $courante ? $courante + 1 : null,
            'm2Total' => round($m2Total, 2),
            'm2Confirme' => round($m2Confirme, 2),
            'm2Fait' => $m2Fait,
            'estime' => $estime,
            'secondes' => $secondes,
            'vitesseReelle' => $vitesseReelle,
            'vitesse' => null !== $vitesse ? round($vitesse, 1) : null,
            'encreMl' => null !== $encreMlM2 && $encreMlM2 > 0 ? (int) round($m2Fait * $encreMlM2) : null,
            'fin' => $fin,
            'pourcent' => $m2Total > 0 ? (int) min(100, floor(100 * $m2Fait / $m2Total)) : 0,
        ];
    }

    /**
     * L'avancement d'un rouleau lancé. Sans vitesse de profil donnée, celle
     * de son estimation (m² imprimés ÷ durée estimée), sinon celle de la
     * machine.
     *
     * @return array<string, mixed>|null null : le rouleau n'a pas démarré
     */
    public static function duTirage(TirageRouleau $tirage, \DateTimeImmutable $maintenant, ?float $vitesseProfil = null, ?float $encreMlM2 = null): ?array
    {
        $demarre = $tirage->getDemarreLe();
        if (null === $demarre) {
            return null;
        }
        $pieces = array_map(static fn (PieceTirage $p): array => [
            'm2' => $p->getLargeurCm() * $p->getHauteurCm() / 10000,
            'statut' => $p->getStatut(),
            'debut' => $p->getDebuteeLe(),
            'fin' => $p->getImprimeeLe(),
        ], $tirage->piecesTriees());

        return self::calculer($pieces, $demarre, $tirage->getPauses(), $tirage->getImprimeLe() ?? $maintenant, $vitesseProfil ?? self::vitesseEstimee($tirage), $encreMlM2);
    }

    /** La vitesse qui a servi à l'estimation du rouleau, sinon celle de la machine (m²/h). */
    public static function vitesseEstimee(TirageRouleau $tirage): ?float
    {
        $minutes = $tirage->getDureeImpressionMin();
        if (null !== $minutes && $minutes > 0 && $tirage->getImprimeM2() > 0) {
            return round($tirage->getImprimeM2() * 60 / $minutes, 1);
        }
        $machine = $tirage->getMachine()->getVitesseM2Heure();

        return null !== $machine && (float) $machine > 0 ? (float) $machine : null;
    }

    /**
     * Secondes entre deux instants sans les pauses (une pause encore
     * ouverte court jusqu'à $maintenant).
     *
     * @param list<array{debut: \DateTimeImmutable, fin: ?\DateTimeImmutable}> $pauses
     */
    public static function secondesEffectives(\DateTimeImmutable $de, \DateTimeImmutable $a, array $pauses, \DateTimeImmutable $maintenant): int
    {
        $total = $a->getTimestamp() - $de->getTimestamp();
        if ($total <= 0) {
            return 0;
        }
        foreach ($pauses as $p) {
            $debut = max($p['debut']->getTimestamp(), $de->getTimestamp());
            $fin = min(($p['fin'] ?? $maintenant)->getTimestamp(), $a->getTimestamp());
            if ($fin > $debut) {
                $total -= $fin - $debut;
            }
        }

        return max(0, $total);
    }

    /**
     * Minutes d'impression d'un ordre sur le rouleau : du début de sa
     * première pièce à la fin de sa dernière, pauses du rouleau retirées.
     *
     * @param list<array{debut: ?\DateTimeImmutable, fin: ?\DateTimeImmutable}> $pieces les pièces de l'ordre
     * @param list<array{debut: \DateTimeImmutable, fin: ?\DateTimeImmutable}>  $pauses
     *
     * @return array{debut: \DateTimeImmutable, fin: \DateTimeImmutable, minutes: int}|null
     */
    public static function tempsOrdre(array $pieces, array $pauses, \DateTimeImmutable $maintenant): ?array
    {
        $debuts = array_filter(array_column($pieces, 'debut'));
        $fins = array_filter(array_column($pieces, 'fin'));
        if ([] === $debuts || [] === $fins) {
            return null;
        }
        $debut = min($debuts);
        $fin = max($fins);
        if ($fin < $debut) {
            return null;
        }

        return ['debut' => $debut, 'fin' => $fin, 'minutes' => (int) round(self::secondesEffectives($debut, $fin, $pauses, $maintenant) / 60)];
    }
}
