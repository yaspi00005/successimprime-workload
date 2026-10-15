<?php

namespace App\Service\Promotions;

use App\Entity\Promotion;

/**
 * Règles d'une promotion sur une ligne (docs/promotions.md), sans base de
 * données : est-elle valable, combien retire-t-elle, laquelle garder
 * quand plusieurs conviennent.
 *
 * Pas de cumul : la remise de la promotion se compare à la remise déjà
 * sur la ligne (prix B2B, remise saisie à la main, promo de réveil) et la
 * ligne garde la meilleure des deux, jamais les deux.
 */
final class CalculPromotion
{
    /** Pourquoi une promotion ne s'applique pas, dans l'ordre des vérifications. */
    public const REFUS = [
        'client' => 'Choisissez d\'abord le client.',
        'suspendue' => 'Promotion suspendue.',
        'periode' => 'Hors de la période de la promotion.',
        'article' => 'Article non visé.',
        'segment' => 'Client hors du segment.',
        'quantite' => 'Quantité minimale non atteinte.',
        'montant' => 'Montant minimal non atteint.',
        'code' => 'Code promo manquant ou différent.',
        'utilisations' => 'Déjà utilisée par ce client autant de fois que permis.',
        'sans_effet' => 'La remise de la ligne est déjà meilleure.',
        'b2b' => 'Client au prix B2B : exception à demander.',
    ];

    /** Remise par unité sur le prix de base, avant comparaison avec la remise de la ligne. */
    public static function remiseSurPrix(Promotion $p, float $prixUnitaire): int
    {
        $prix = max(0.0, $prixUnitaire);
        $valeur = $p->getValeur();

        return (int) round(match ($p->getAvantage()) {
            Promotion::AVANTAGE_MONTANT => min($valeur, $prix),
            Promotion::AVANTAGE_PRIX_FIXE => max(0.0, $prix - $valeur),
            default => $prix * min(100.0, $valeur) / 100,
        });
    }

    /**
     * Ce que la promotion ajoute à la remise déjà sur la ligne, par unité :
     * la ligne arrive au prix de la promotion si elle est meilleure, sinon
     * rien.
     */
    public static function remiseUnitaire(Promotion $p, float $prixUnitaire, int $remiseSaisie): int
    {
        return max(0, self::remiseSurPrix($p, $prixUnitaire) - max(0, $remiseSaisie));
    }

    /**
     * Ce que la promotion ajoute sur cette ligne, par unité. Pour un
     * revendeur, elle part du prix public et se compare à tout ce que la
     * ligne a déjà (prix B2B compris) : la promotion à la place du prix B2B,
     * jamais les deux.
     */
    public static function remisePour(Promotion $p, ContexteLigne $l): int
    {
        return self::remiseUnitaire($p, $l->prixDeReference(), $l->avantageDeLaLigne());
    }

    /** Montant retiré sur la ligne par la promotion (plafonné au total, comme la remise). */
    public static function montantLigne(int $remiseUnitaire, int $remiseSaisie, float $unites, int $totalAvantRemise): int
    {
        if ($remiseUnitaire <= 0) {
            return 0;
        }
        $seule = min($totalAvantRemise, max(0, (int) round($remiseSaisie * $unites)));
        $avec = min($totalAvantRemise, max(0, (int) round(($remiseSaisie + $remiseUnitaire) * $unites)));

        return max(0, $avec - $seule);
    }

    public static function dansSegment(Promotion $p, ContexteLigne $l): bool
    {
        return match ($p->getSegment()) {
            Promotion::SEGMENT_TOUS => null !== $l->client,
            Promotion::SEGMENT_LISTE => $p->contientClient($l->client),
            default => \in_array($p->getSegment(), $l->segments, true),
        };
    }

    /**
     * Le code qu'il faut est donné : aucun code demandé, le code simple de la
     * promotion tapé tel quel, ou, pour une promotion à codes générés, un de
     * ses codes vérifié (limites, dates, client) par CodesPromo.
     */
    public static function codeDonne(Promotion $p, ContexteLigne $l): bool
    {
        if ($p->exigeCodeGenere()) {
            return \in_array((int) $p->getId(), $l->promotionsDebloquees, true);
        }

        return null === $p->getCode() || $p->getCode() === Promotion::normaliserCode($l->code);
    }

    /**
     * null si la promotion s'applique à la ligne, sinon la clé de REFUS.
     *
     * La période se juge au jour du document. $dejaAppliquee : la ligne la
     * porte déjà (enregistrement précédent) ; elle la garde même si la
     * promotion a été suspendue depuis, et le code simple n'est pas
     * redemandé (un code généré, si : il doit rester retenu sur le document).
     * L'article, le segment et les conditions, eux, si : la ligne a pu
     * changer.
     */
    public static function refus(Promotion $p, ContexteLigne $l, bool $dejaAppliquee = false): ?string
    {
        if (null === $l->client) {
            return 'client';
        }
        if (!$dejaAppliquee && !$p->isActive()) {
            return 'suspendue';
        }
        if (!$p->couvre($l->jour)) {
            return 'periode';
        }
        if (!$p->vise($l->produit)) {
            return 'article';
        }
        if (!self::dansSegment($p, $l)) {
            return 'segment';
        }
        if (null !== $p->getQuantiteMin() && $l->quantite < $p->getQuantiteMin()) {
            return 'quantite';
        }
        if (null !== $p->getMontantMin() && $l->totalAvantRemise < $p->getMontantMin()) {
            return 'montant';
        }
        // Codes générés : la ligne ne la garde qu'avec le code retenu sur le document (sinon, le retirer rendrait
        // son utilisation tout en gardant la remise).
        if ((!$dejaAppliquee || $p->exigeCodeGenere()) && !self::codeDonne($p, $l)) {
            return 'code';
        }
        if (null !== $p->getUtilisationsParClient() && ($l->utilisations[(int) $p->getId()] ?? 0) >= $p->getUtilisationsParClient()) {
            return 'utilisations';
        }
        if (self::remisePour($p, $l) <= 0) {
            return 'sans_effet';
        }
        // En dernier : l'exception ne se demande que pour une promotion qui s'appliquerait.
        if ($l->estB2b() && !\in_array((int) $p->getId(), $l->exceptionsAcceptees, true)) {
            return 'b2b';
        }

        return null;
    }

    /**
     * Les promotions de la ligne : celle à appliquer (la plus forte remise ;
     * à égalité, celle qui finit le plus tôt, puis la plus ancienne), et
     * celles qu'une exception B2B permettrait.
     *
     * @param iterable<Promotion> $promotions
     *
     * @return array{promotion: ?Promotion, remise: int, b2b: list<array{promotion: Promotion, remise: int}>, refus: array<int, string>}
     */
    public static function evaluer(iterable $promotions, ContexteLigne $l, ?Promotion $actuelle = null): array
    {
        $retenue = null;
        $remise = 0;
        $b2b = [];
        $refus = [];
        $vues = [];
        foreach ($promotions as $p) {
            $cle = $p->getId() ?? spl_object_id($p);
            if (isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;
            $deja = null !== $actuelle && ($p === $actuelle || (null !== $p->getId() && $p->getId() === $actuelle->getId()));
            $raison = self::refus($p, $l, $deja);
            if (null !== $raison) {
                $refus[(int) $p->getId()] = $raison;
                if ('b2b' === $raison) {
                    $b2b[] = ['promotion' => $p, 'remise' => self::remisePour($p, $l)];
                }
                continue;
            }
            $r = self::remisePour($p, $l);
            if (null === $retenue || self::meilleure($p, $r, $retenue, $remise)) {
                $retenue = $p;
                $remise = $r;
            }
        }
        usort($b2b, static fn (array $a, array $b): int => $b['remise'] <=> $a['remise']);

        return ['promotion' => $retenue, 'remise' => $remise, 'b2b' => $b2b, 'refus' => $refus];
    }

    private static function meilleure(Promotion $p, int $remise, Promotion $actuelle, int $remiseActuelle): bool
    {
        if ($remise !== $remiseActuelle) {
            return $remise > $remiseActuelle;
        }
        $finP = $p->getFin()->format('Y-m-d');
        $finA = $actuelle->getFin()->format('Y-m-d');
        if ($finP !== $finA) {
            return $finP < $finA;
        }

        return ($p->getId() ?? \PHP_INT_MAX) < ($actuelle->getId() ?? \PHP_INT_MAX);
    }
}
