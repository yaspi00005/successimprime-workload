<?php

namespace App\Service;

use App\Entity\Articles;
use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\ProduitArticleStock;

/**
 * Cout de revient d'une ligne de commande, calcule tout seul a partir
 * de la consommation (decision de Yaya du 10/10/2026) :
 *
 * - matieres : articles de stock lies au produit, au prix d'achat, avec
 *   la chute de la laize pour les supports au m2 ;
 * - encre : cout de l'encre par m2 de la machine ;
 * - temps machine : surface / vitesse x cout horaire ;
 * - finitions : cout unitaire de chaque finition.
 *
 * Une donnee absente (prix d'achat, vitesse...) ne bloque pas le calcul :
 * elle est listee dans « manquants » pour que l'ecran dise que le cout
 * est incomplet.
 */
final class CalculateurCoutRevient
{
    /*
     * Heures de production par mois, pour deduire un cout horaire de
     * l'amortissement quand la machine n'en a pas.
     */
    private const HEURES_PAR_MOIS = 200;

    /**
     * @return array{
     *     surfaceUtile: float,
     *     surfaceConsommee: float,
     *     laize: ?float,
     *     chute: float,
     *     matieres: int,
     *     encre: int,
     *     machine: int,
     *     finitions: int,
     *     total: int,
     *     manquants: list<string>
     * }
     */
    public function pourLigne(CommandesDetails $ligne): array
    {
        $quantite = max(1, $ligne->getQuantite());
        $faces = max(1, $ligne->getNombreFaces());
        $machine = $ligne->getMachine();
        $manquants = [];

        // Dimensions stockees en metres (le formulaire affiche des cm).
        $largeur = (float) ($ligne->getLargeur() ?? 0);
        $hauteur = (float) ($ligne->getLongueur() ?? 0);

        $imposition = self::imposition($largeur, $hauteur, $quantite, $machine?->getLaizeMetres());

        if (0.0 === $imposition['surfaceUtile'] && (float) $ligne->getSurface() > 0) {
            $surface = (float) $ligne->getSurface() * $quantite;
            $imposition = ['surfaceUtile' => $surface, 'surfaceConsommee' => $surface, 'metresLineaires' => 0.0, 'laize' => null, 'chute' => 0.0];
        }

        $matieres = 0;
        $produit = $ligne->getProduit();
        $liaisons = array_filter(
            $produit?->getArticlesStock()->toArray() ?? [],
            static fn (ProduitArticleStock $liaison): bool => $liaison->isActif() && $liaison->getArticle() instanceof Articles
        );

        if (null === $produit) {
            $manquants[] = 'produit du catalogue (saisie libre)';
        } elseif ([] === $liaisons) {
            $manquants[] = sprintf('matières de %s', $produit->getNom());
        }

        foreach ($liaisons as $liaison) {
            $article = $liaison->getArticle();

            $besoin = self::besoinArticle(
                $liaison->getModeCalcul(),
                (float) $liaison->getCoefficient(),
                $quantite,
                $imposition['surfaceConsommee'],
                $imposition['metresLineaires'] > 0 ? $imposition['metresLineaires'] : $hauteur * $quantite,
            );

            if ($besoin <= 0) {
                continue;
            }

            if (null === $article->getPrixAchat()) {
                $manquants[] = sprintf('prix d\'achat de %s', $article->getDesignation());

                continue;
            }

            $matieres += (int) round($besoin * $article->getPrixAchat());
        }

        $surfaceImprimee = $imposition['surfaceUtile'] * $faces;
        $encre = 0;
        $tempsMachine = 0;

        if ($machine instanceof Machines && $surfaceImprimee > 0) {
            if (null === $machine->getCoutEncreM2()) {
                $manquants[] = sprintf('coût de l\'encre par m² de %s', $machine->getNom());
            } else {
                $encre = (int) round($surfaceImprimee * $machine->getCoutEncreM2());
            }

            $coutHoraire = self::coutHoraire($machine);

            if (null === $machine->getVitesseM2Heure() || null === $coutHoraire) {
                $manquants[] = sprintf('vitesse ou coût horaire de %s', $machine->getNom());
            } else {
                $tempsMachine = (int) round($surfaceImprimee / $machine->getVitesseM2Heure() * $coutHoraire);
            }
        }

        $finitions = 0;

        foreach ($ligne->getFinitions() as $finitionLigne) {
            $finition = $finitionLigne->getFinition();

            if (null === $finition) {
                continue;
            }

            if (null === $finition->getCoutUnitaire()) {
                $manquants[] = sprintf('coût de la finition %s', $finition->getNom());

                continue;
            }

            $finitions += $finition->getCoutUnitaire() * max(1, $finitionLigne->getQuantite());
        }

        return [
            'surfaceUtile' => round($imposition['surfaceUtile'], 2),
            'surfaceConsommee' => round($imposition['surfaceConsommee'], 2),
            'laize' => $imposition['laize'],
            'chute' => $imposition['chute'],
            'matieres' => $matieres,
            'encre' => $encre,
            'machine' => $tempsMachine,
            'finitions' => $finitions,
            'total' => $matieres + $encre + $tempsMachine + $finitions,
            'manquants' => array_values(array_unique($manquants)),
        ];
    }

    /**
     * Marge brute en % du prix HT, ou null sans prix.
     */
    public static function tauxMarge(int $prixHt, int $cout): ?float
    {
        if ($prixHt <= 0) {
            return null;
        }

        return round(($prixHt - $cout) / $prixHt * 100, 1);
    }

    /**
     * Pose des pieces sur la laize : on essaie les deux sens et on garde
     * celui qui consomme le moins de support.
     *
     * @return array{surfaceUtile: float, surfaceConsommee: float, metresLineaires: float, laize: ?float, chute: float}
     */
    public static function imposition(float $largeur, float $hauteur, int $quantite, ?float $laize): array
    {
        $utile = $largeur * $hauteur * $quantite;

        if ($utile <= 0) {
            return ['surfaceUtile' => 0.0, 'surfaceConsommee' => 0.0, 'metresLineaires' => 0.0, 'laize' => $laize, 'chute' => 0.0];
        }

        $meilleure = null;

        foreach ([[$largeur, $hauteur], [$hauteur, $largeur]] as [$travers, $long]) {
            if (null === $laize || $travers > $laize) {
                continue;
            }

            $parRangee = max(1, (int) floor($laize / $travers + 1e-9));
            $metres = (int) ceil($quantite / $parRangee) * $long;

            if (null === $meilleure || $metres < $meilleure) {
                $meilleure = $metres;
            }
        }

        if (null === $meilleure) {
            // Sans laize connue, ou piece plus large que la laize (raccords) :
            // on compte la surface utile, sans chute.
            return [
                'surfaceUtile' => $utile,
                'surfaceConsommee' => $utile,
                'metresLineaires' => null === $laize ? 0.0 : $utile / $laize,
                'laize' => $laize,
                'chute' => 0.0,
            ];
        }

        $consommee = $meilleure * $laize;

        return [
            'surfaceUtile' => $utile,
            'surfaceConsommee' => $consommee,
            'metresLineaires' => $meilleure,
            'laize' => $laize,
            'chute' => round((1 - $utile / $consommee) * 100, 1),
        ];
    }

    /**
     * Quantite d'un article de stock consommee par la ligne, dans son unite.
     */
    public static function besoinArticle(string $mode, float $coefficient, int $quantite, float $surfaceConsommee, float $metresLineaires): float
    {
        $coefficient = max(0.0, $coefficient);

        return match ($mode) {
            ProduitArticleStock::MODE_SURFACE => $surfaceConsommee * $coefficient,
            ProduitArticleStock::MODE_METRE => $metresLineaires * $coefficient,
            ProduitArticleStock::MODE_FORFAIT => $coefficient,
            ProduitArticleStock::MODE_UNITE, ProduitArticleStock::MODE_QUANTITE => $quantite * $coefficient,
            default => 0.0,
        };
    }

    /**
     * Cout d'une heure de machine : celui saisi, sinon l'amortissement
     * mensuel reparti sur les heures de production du mois.
     */
    public static function coutHoraire(Machines $machine): ?int
    {
        if (null !== $machine->getCoutHoraire()) {
            return $machine->getCoutHoraire();
        }

        if ($machine->getPrixAchat() && $machine->getDureeAmortissementMois()) {
            return (int) round($machine->getPrixAchat() / $machine->getDureeAmortissementMois() / self::HEURES_PAR_MOIS);
        }

        return null;
    }
}
