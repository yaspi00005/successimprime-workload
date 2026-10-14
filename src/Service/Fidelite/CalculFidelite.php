<?php

namespace App\Service\Fidelite;

use App\Entity\CommandesDetails;
use App\Entity\MouvementFidelite;
use App\Entity\Paiements;
use App\Entity\RegleFidelite;

/**
 * Calculs du programme de fidélité (docs/fidelite.md), sans base de données
 * pour être testés seuls.
 *
 * - Famille d'une ligne : au m² (bâches, banderoles, vinyle…) ou autre.
 * - Valeurs d'une ligne : celles du client (gros client) d'abord, puis la
 *   règle de l'article dans ses dates, puis les valeurs générales.
 * - Seuil : seule la part au-dessus du seuil rapporte, y compris sur la
 *   ligne qui le franchit.
 * - Paiement : chaque paiement validé compte pour sa part de la commande ;
 *   ×2 le jour même, ×1 dans les 72 h, rien après.
 * - Lots : les points expirent ; on consomme toujours les plus anciens.
 */
final class CalculFidelite
{
    public const CLE_M2 = 'famille:m2';
    public const CLE_AUTRE = 'famille:autre';

    /* ---------- Lignes de commande ---------- */

    /** Au m² : mode « metre_carre », ou « metre » quand la laize est connue. */
    public static function famille(string $modeCalcul, ?float $largeur = null): string
    {
        return 'metre_carre' === $modeCalcul || ('metre' === $modeCalcul && (float) $largeur > 0)
            ? MouvementFidelite::FAMILLE_M2
            : MouvementFidelite::FAMILLE_AUTRE;
    }

    /**
     * Ce qui compte d'une ligne de commande.
     *
     * @return array{id: ?int, produit: ?int, famille: string, m2: float, pieces: float, montant: int}
     */
    public static function ligne(CommandesDetails $l): array
    {
        $famille = self::famille($l->getModeCalcul(), null === $l->getLargeur() ? null : (float) $l->getLargeur());
        $m2 = 0.0;
        if (MouvementFidelite::FAMILLE_M2 === $famille) {
            $m2 = 'metre' === $l->getModeCalcul()
                ? (float) $l->getLargeur() * (float) $l->getLongueur() * $l->getQuantite()
                : $l->getSurfaceTotale();
        }

        return [
            'id' => $l->getId(),
            'produit' => $l->getProduit()?->getId(),
            'famille' => $famille,
            'm2' => round($m2, 4),
            'pieces' => (float) $l->getQuantite(),
            'montant' => max(0, $l->getTotalTtc()),
        ];
    }

    /* ---------- Délai de paiement ---------- */

    /**
     * Payé le jour de la référence (ou avant) : ×2 ; dans les 72 h : ×1 ;
     * plus tard : 0. Référence : date de la facture, sinon de la commande.
     */
    public static function multiplicateur(\DateTimeImmutable $paiement, \DateTimeImmutable $reference, ReglagesFidelite $r): int
    {
        if ($paiement->format('Y-m-d') <= $reference->format('Y-m-d')) {
            return $r->multiplicateurJour;
        }
        if ($paiement <= $reference->modify('+'.$r->delaiHeures.' hours')) {
            return $r->multiplicateurDelai;
        }

        return 0;
    }

    /**
     * Un paiement rapporte-t-il des points ? Seulement validé, pour un client
     * au prix public (B2C), sur une commande ni annulée ni supprimée, et pas
     * quand il est lui-même payé en points.
     */
    public static function paiementRapporte(bool $programmeActif, bool $clientB2B, string $modePaiement, bool $paiementValide, bool $commandeAnnulee): bool
    {
        return $programmeActif && !$clientB2B && $paiementValide && !$commandeAnnulee
            && Paiements::MODE_POINTS_FIDELITE !== $modePaiement;
    }

    /**
     * Part de la commande couverte par un paiement, sans dépasser ce qui
     * n'a pas encore été compté (paiement au-delà du total).
     */
    public static function part(int $montantPaiement, int $totalCommande, int $dejaCompte = 0): float
    {
        if ($totalCommande <= 0 || $montantPaiement <= 0) {
            return 0.0;
        }

        return min($montantPaiement, max(0, $totalCommande - $dejaCompte)) / $totalCommande;
    }

    /* ---------- Règle qui s'applique ---------- */

    /**
     * La règle d'article du jour : la plus récemment commencée parmi celles
     * dont la période contient le jour.
     *
     * @param list<RegleFidelite> $regles
     */
    public static function regleDuJour(array $regles, \DateTimeInterface $jour): ?RegleFidelite
    {
        $choisie = null;
        foreach ($regles as $r) {
            if ($r->sAppliqueLe($jour) && (null === $choisie || $r->getDebut() >= $choisie->getDebut())) {
                $choisie = $r;
            }
        }

        return $choisie;
    }

    /**
     * Valeurs d'une ligne : client (gros client) > article dans ses dates >
     * valeurs générales. Un article « désactivé » ne rapporte rien.
     *
     * @param array{pointsParM2?: ?float, tauxAutre?: ?float, seuilM2?: ?float, seuilMontant?: ?int} $client
     * @param list<RegleFidelite>                                                                   $reglesProduit
     *
     * @return array{gagne: bool, source: string, mode: string, valeur: float, valeurPoint: int, seuil: float, compteur: string}
     */
    public static function regle(string $famille, ?int $produit, array $client, array $reglesProduit, ReglagesFidelite $r, \DateTimeInterface $jour): array
    {
        $m2 = MouvementFidelite::FAMILLE_M2 === $famille;
        $cleFamille = $m2 ? self::CLE_M2 : self::CLE_AUTRE;
        $article = null === $produit ? null : self::regleDuJour($reglesProduit, $jour);

        if (null !== $article && !$article->isActif()) {
            return ['gagne' => false, 'source' => MouvementFidelite::REGLE_DESACTIVE, 'mode' => RegleFidelite::MODE_UNITE, 'valeur' => 0.0, 'valeurPoint' => $r->valeurPoint, 'seuil' => 0.0, 'compteur' => $cleFamille];
        }

        $tauxClient = $m2 ? ($client['pointsParM2'] ?? null) : ($client['tauxAutre'] ?? null);
        if (null !== $tauxClient) {
            [$mode, $valeur, $valeurPoint, $source] = [$m2 ? RegleFidelite::MODE_UNITE : RegleFidelite::MODE_POURCENTAGE, (float) $tauxClient, $r->valeurPoint, MouvementFidelite::REGLE_CLIENT];
        } elseif (null !== $article) {
            [$mode, $valeur, $valeurPoint, $source] = [$article->getMode(), $article->getValeur(), $article->getValeurPoint(), MouvementFidelite::REGLE_ARTICLE];
        } else {
            [$mode, $valeur, $valeurPoint, $source] = $m2
                ? [RegleFidelite::MODE_UNITE, $r->pointsParM2, $r->valeurPoint, MouvementFidelite::REGLE_DEFAUT]
                : [RegleFidelite::MODE_POURCENTAGE, $r->tauxAutre, $r->valeurPoint, MouvementFidelite::REGLE_DEFAUT];
        }

        $seuilClient = $m2 ? ($client['seuilM2'] ?? null) : ($client['seuilMontant'] ?? null);
        if (null !== $seuilClient) {
            [$seuil, $compteur] = [(float) $seuilClient, $cleFamille];
            $source = MouvementFidelite::REGLE_CLIENT;
        } elseif (null !== $article) {
            $seuil = $article->getSeuil();
            $compteur = RegleFidelite::PORTEE_FAMILLE === $article->getPortee()
                ? $cleFamille
                : 'produit:'.$produit.':'.($m2 ? 'm2' : (RegleFidelite::MODE_UNITE === $article->getMode() ? 'pieces' : 'montant'));
        } else {
            [$seuil, $compteur] = [$m2 ? $r->seuilM2 : (float) $r->seuilMontant, $cleFamille];
        }

        return ['gagne' => true, 'source' => $source, 'mode' => $mode, 'valeur' => $valeur, 'valeurPoint' => max(1, $valeurPoint), 'seuil' => $seuil, 'compteur' => $compteur];
    }

    /* ---------- Gains d'un paiement ---------- */

    /**
     * Ajoute la part payée d'une ligne aux compteurs de progression.
     *
     * @param array<string, float> $compteurs
     */
    public static function compter(array &$compteurs, string $famille, ?int $produit, float $m2, float $pieces, int $montant): void
    {
        if (MouvementFidelite::FAMILLE_M2 === $famille) {
            $compteurs[self::CLE_M2] = ($compteurs[self::CLE_M2] ?? 0) + $m2;
        } else {
            $compteurs[self::CLE_AUTRE] = ($compteurs[self::CLE_AUTRE] ?? 0) + $montant;
        }
        if (null !== $produit) {
            foreach (['m2' => $m2, 'pieces' => $pieces, 'montant' => $montant] as $unite => $q) {
                $compteurs['produit:'.$produit.':'.$unite] = ($compteurs['produit:'.$produit.':'.$unite] ?? 0) + $q;
            }
        }
    }

    /**
     * Points d'un paiement : chaque ligne compte pour la part payée ($part,
     * entre 0 et 1). Seule la quantité au-dessus du seuil rapporte. Les
     * compteurs avancent même quand rien n'est gagné (paiement tardif,
     * article désactivé) : la progression compte toute ligne payée.
     *
     * @param list<array{id: ?int, produit: ?int, famille: string, m2: float, pieces: float, montant: int}> $lignes
     * @param array{pointsParM2?: ?float, tauxAutre?: ?float, seuilM2?: ?float, seuilMontant?: ?int}        $client
     * @param array<int, list<RegleFidelite>>                                                               $reglesParProduit
     * @param array<string, float>                                                                          $compteurs
     *
     * @return list<array{ligne: ?int, produit: ?int, famille: string, m2: float, pieces: float, montant: int, points: int, valeurPoint: int, regle: string}>
     */
    public static function gains(array $lignes, float $part, int $multiplicateur, array $client, array $reglesParProduit, ReglagesFidelite $r, \DateTimeInterface $jour, array &$compteurs): array
    {
        $part = max(0.0, min(1.0, $part));
        $resultat = [];

        foreach ($lignes as $l) {
            $m2 = round($l['m2'] * $part, 4);
            $pieces = round($l['pieces'] * $part, 4);
            $montant = (int) round($l['montant'] * $part);
            if ($m2 <= 0 && $montant <= 0) {
                continue;
            }

            $regle = self::regle($l['famille'], $l['produit'], $client, $reglesParProduit[$l['produit'] ?? 0] ?? [], $r, $jour);
            $q = match (true) {
                self::CLE_M2 === $regle['compteur'], str_ends_with($regle['compteur'], ':m2') => $m2,
                str_ends_with($regle['compteur'], ':pieces') => $pieces,
                default => (float) $montant,
            };
            $avant = $compteurs[$regle['compteur']] ?? 0.0;
            $auDessus = max(0.0, $avant + $q - $regle['seuil']) - max(0.0, $avant - $regle['seuil']);
            $fraction = $q > 0 ? min(1.0, $auDessus / $q) : 0.0;

            $base = 0.0;
            if ($regle['gagne']) {
                $base = RegleFidelite::MODE_UNITE === $regle['mode']
                    ? $fraction * (MouvementFidelite::FAMILLE_M2 === $l['famille'] ? $m2 : $pieces) * $regle['valeur']
                    : $fraction * $montant * $regle['valeur'] / 100;
            }

            self::compter($compteurs, $l['famille'], $l['produit'], $m2, $pieces, $montant);

            $resultat[] = [
                'ligne' => $l['id'],
                'produit' => $l['produit'],
                'famille' => $l['famille'],
                'm2' => $m2,
                'pieces' => $pieces,
                'montant' => $montant,
                'points' => (int) round($base * $multiplicateur + 1e-9),
                'valeurPoint' => $regle['valeurPoint'],
                'regle' => $regle['source'],
            ];
        }

        return $resultat;
    }

    /* ---------- Solde, lots et expiration ---------- */

    /**
     * Rejoue le relevé dans l'ordre : chaque ligne positive est un lot qui
     * expire ; chaque ligne négative consomme d'abord le lot qu'elle vise
     * (gain retiré, lot expiré), puis les plus anciens encore valables. Ce
     * qui manque devient une dette, reprise sur les gains suivants.
     *
     * @param list<array{id: int, type: string, points: int, date: \DateTimeImmutable, expireLe: ?\DateTimeImmutable, valeurPoint?: int, cible?: ?int}> $mouvements
     *
     * @return array{solde: int, disponible: int, disponibleF: int, dette: int, lots: list<array{id: int, date: \DateTimeImmutable, expireLe: ?\DateTimeImmutable, restant: int, valeurPoint: int}>, aExpirer: list<array{id: int, expireLe: \DateTimeImmutable, restant: int}>, expirant: int, prochaineExpiration: ?\DateTimeImmutable, consommes: array<int, list<array{lot: int, points: int, expireLe: ?\DateTimeImmutable}>>}
     */
    public static function etat(array $mouvements, \DateTimeImmutable $maintenant, int $alerteJours = 30): array
    {
        usort($mouvements, static fn (array $a, array $b): int => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        $lots = [];
        $dette = 0;
        $solde = 0;
        $consommes = [];

        foreach ($mouvements as $m) {
            $solde += $m['points'];
            if ($m['points'] > 0) {
                $restant = $m['points'];
                $repris = min($dette, $restant);
                $dette -= $repris;
                $lots[$m['id']] = ['id' => $m['id'], 'date' => $m['date'], 'expireLe' => $m['expireLe'], 'restant' => $restant - $repris, 'valeurPoint' => max(1, $m['valeurPoint'] ?? 1)];
                continue;
            }
            if ($m['points'] === 0) {
                continue;
            }

            $besoin = -$m['points'];
            $prendre = static function (int $id) use (&$lots, &$besoin, &$consommes, $m): void {
                $pris = min($besoin, $lots[$id]['restant']);
                if ($pris <= 0) {
                    return;
                }
                $lots[$id]['restant'] -= $pris;
                $besoin -= $pris;
                $consommes[$m['id']][] = ['lot' => $id, 'points' => $pris, 'expireLe' => $lots[$id]['expireLe']];
            };

            $cible = $m['cible'] ?? null;
            if (null !== $cible && isset($lots[$cible])) {
                $prendre($cible);
            }
            foreach ($lots as $id => $lot) {
                if ($besoin <= 0) {
                    break;
                }
                // Un lot déjà expiré ne sert plus, sauf à sa propre expiration.
                if (MouvementFidelite::EXPIRATION !== $m['type'] && null !== $lot['expireLe'] && $lot['expireLe'] <= $m['date']) {
                    continue;
                }
                $prendre($id);
            }
            $dette += $besoin;
        }

        $disponible = 0;
        $disponibleF = 0;
        $aExpirer = [];
        $expirant = 0;
        $prochaine = null;
        $limite = $maintenant->modify('+'.$alerteJours.' days');
        $valables = [];
        foreach ($lots as $lot) {
            if ($lot['restant'] <= 0) {
                continue;
            }
            if (null !== $lot['expireLe'] && $lot['expireLe'] <= $maintenant) {
                $aExpirer[] = ['id' => $lot['id'], 'expireLe' => $lot['expireLe'], 'restant' => $lot['restant']];
                continue;
            }
            $valables[] = $lot;
            $disponible += $lot['restant'];
            $disponibleF += $lot['restant'] * $lot['valeurPoint'];
            if (null !== $lot['expireLe'] && $lot['expireLe'] <= $limite) {
                $expirant += $lot['restant'];
            }
            if (null !== $lot['expireLe'] && (null === $prochaine || $lot['expireLe'] < $prochaine)) {
                $prochaine = $lot['expireLe'];
            }
        }

        return [
            'solde' => $solde,
            'disponible' => $disponible - $dette,
            'disponibleF' => $disponibleF - $dette,
            'dette' => $dette,
            'lots' => $valables,
            'aExpirer' => $aExpirer,
            'expirant' => $expirant,
            'prochaineExpiration' => $prochaine,
            'consommes' => $consommes,
        ];
    }

    /**
     * Points à utiliser sur une commande, sans dépasser ce que le client a
     * ni ce qui reste à payer : les lots les plus anciens d'abord, comme le
     * relevé les consommera.
     *
     * @param list<array{restant: int, valeurPoint: int}> $lots lots valables, du plus ancien au plus récent
     *
     * @return array{points: int, montant: int}
     */
    public static function planUtilisation(array $lots, int $pointsVoulus, int $plafondF): array
    {
        $points = 0;
        $montant = 0;
        foreach ($lots as $lot) {
            $reste = $pointsVoulus - $points;
            if ($reste <= 0) {
                break;
            }
            $k = min($lot['restant'], $reste, intdiv(max(0, $plafondF - $montant), max(1, $lot['valeurPoint'])));
            if ($k <= 0) {
                break;
            }
            $points += $k;
            $montant += $k * $lot['valeurPoint'];
            if ($k < $lot['restant']) {
                break;
            }
        }

        return ['points' => $points, 'montant' => $montant];
    }

    /** Date d'expiration d'un lot gagné le jour donné. */
    public static function expiration(\DateTimeImmutable $le, ReglagesFidelite $r): \DateTimeImmutable
    {
        return $le->modify('+'.$r->validiteMois.' months');
    }
}
