<?php

namespace App\Service\Catalogue;

use App\Entity\Articles;
use App\Entity\Machines;
use App\Entity\ProduitArticleStock;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Service\CalculateurCoutRevient;

/**
 * Coût de revient d'une unité vendue (1 m², 1 mètre, 1 pièce…) et marge
 * au prix de base (maquettes PR01 et PR02, docs/produits.md).
 *
 * Calculé tout seul, comme celui des lignes de commande
 * (CalculateurCoutRevient) :
 *  - matières : articles du stock liés au produit (nomenclature), au coût
 *    moyen ou à défaut au prix d'achat ramené à l'unité de stock ;
 *  - encre : coût de l'encre au m² de la machine du produit ;
 *  - machine et main-d'œuvre : coût horaire ÷ vitesse (m² par heure).
 *
 * Le coût saisi à la main sur la fiche ne sert que si rien ne se calcule
 * (service, pose, produit sans matière liée). Ce calcul n'est montré qu'à
 * qui a le droit « Marges et coûts de revient » (ROLE_COUT_MARGE), jamais
 * en ligne.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class CoutProduit
{
    /** Unité de vente montrée, selon le mode de calcul. */
    public const UNITES = [
        'metre_carre' => 'm²',
        'metre' => 'm',
        'unite' => 'pièce',
        'exemplaire' => 'exemplaire',
        'feuille' => 'feuille',
        'heure' => 'heure',
        'face' => 'face',
        'point' => 'point',
        'forfait' => 'forfait',
    ];

    public const SOURCE_CALCULE = 'calcule';
    public const SOURCE_SAISI = 'saisi';

    public static function unite(?string $modeCalcul): string
    {
        return self::UNITES[$modeCalcul ?? 'unite'] ?? 'unité';
    }

    /**
     * Configuration de référence du produit : la moins chère des
     * configurations actives qui ont un prix (le prix « dès »).
     *
     * @param iterable<ProduitConfiguration>|null $configurations par défaut, celles du produit
     */
    public static function configurationDeBase(Produits $produit, ?iterable $configurations = null): ?ProduitConfiguration
    {
        $base = null;
        foreach ($configurations ?? $produit->getConfigurations() as $c) {
            if (!$c->isActive() || null === $c->getPrixBase() || $c->getPrixBase() <= 0) {
                continue;
            }
            if (null === $base || $c->getPrixBase() < $base->getPrixBase()) {
                $base = $c;
            }
        }

        return $base;
    }

    /**
     * Prix de base d'une unité : celui de la configuration de référence,
     * sinon le prix du produit.
     */
    public static function prixBase(Produits $produit, ?ProduitConfiguration $base): ?int
    {
        if (null !== $base) {
            return (int) $base->getPrixBase();
        }

        return null !== $produit->getPrixBase() && $produit->getPrixBase() > 0 ? (int) round($produit->getPrixBase()) : null;
    }

    /**
     * Détail du coût d'une unité vendue.
     *
     * « complet » est faux quand un élément calculé manque (matière sans
     * prix d'achat dans le stock, encre ou vitesse de la machine) : le
     * total est alors trop bas et la marge trop haute, à ne pas montrer
     * comme sûre. « prixAchatManquants » liste les articles du stock sans
     * prix d'achat (lien vers leur fiche).
     *
     * @return array{lignes: list<array{element: string, source: string, cout: int}>, total: ?int, source: ?string, manquants: list<string>, complet: bool, prixAchatManquants: list<array{id: ?int, designation: string}>}
     */
    public static function detail(Produits $produit, ?string $modeCalcul = null): array
    {
        $mode = $modeCalcul ?? $produit->getModeCalcul();
        $surface = 'metre_carre' === $mode ? 1.0 : 0.0;
        $metres = 'metre' === $mode ? 1.0 : 0.0;
        $lignes = [];
        $manquants = [];
        $sansPrix = [];

        foreach ($produit->getArticlesStock() as $liaison) {
            $article = $liaison->getArticle();
            if (!$liaison->isActif() || !$article instanceof Articles) {
                continue;
            }
            $besoin = CalculateurCoutRevient::besoinArticle($liaison->getModeCalcul(), (float) $liaison->getCoefficient(), 1, $surface, $metres);
            if ($besoin <= 0) {
                continue;
            }
            $coutUnitaire = $article->getCoutUnitaireStock();
            if (null === $coutUnitaire) {
                $manquants[] = \sprintf('prix d\'achat de %s', $article->getDesignation());
                $sansPrix[(int) $article->getId()] = ['id' => $article->getId(), 'designation' => (string) $article->getDesignation()];
                continue;
            }
            $lignes[] = [
                'element' => (string) $article->getDesignation().(abs($besoin - 1.0) > 0.0005 ? ' (× '.self::nombre($besoin).')' : ''),
                'source' => null !== $article->getCoutMoyen() ? 'stock · coût moyen' : 'stock · prix d\'achat',
                'cout' => (int) round($besoin * $coutUnitaire),
            ];
        }

        $machine = $produit->getMachine();
        if ($machine instanceof Machines && $surface > 0) {
            if (null === $machine->getCoutEncreM2()) {
                $manquants[] = \sprintf('coût de l\'encre au m² de %s', $machine->getNom());
            } else {
                $lignes[] = ['element' => 'Encre', 'source' => 'consommation machine', 'cout' => (int) $machine->getCoutEncreM2()];
            }
            $horaire = CalculateurCoutRevient::coutHoraire($machine);
            $vitesse = null !== $machine->getVitesseM2Heure() ? (float) $machine->getVitesseM2Heure() : null;
            if (null === $horaire || null === $vitesse || $vitesse <= 0) {
                $manquants[] = \sprintf('vitesse ou coût horaire de %s', $machine->getNom());
            } else {
                $lignes[] = ['element' => 'Machine et main-d\'œuvre', 'source' => 'temps × taux horaire', 'cout' => (int) round($horaire / $vitesse)];
            }
        }

        if ([] === $lignes) {
            if (null !== $produit->getCoutRevientSaisi()) {
                return [
                    'lignes' => [['element' => 'Coût saisi sur la fiche', 'source' => 'à la main', 'cout' => $produit->getCoutRevientSaisi()]],
                    'total' => $produit->getCoutRevientSaisi(),
                    'source' => self::SOURCE_SAISI,
                    'manquants' => [],
                    // Coût donné à la main : il fait foi, même si des matières n'ont pas de prix.
                    'complet' => true,
                    'prixAchatManquants' => [],
                ];
            }

            return ['lignes' => [], 'total' => null, 'source' => null, 'manquants' => array_values(array_unique($manquants)), 'complet' => [] === $manquants, 'prixAchatManquants' => array_values($sansPrix)];
        }

        return [
            'lignes' => $lignes,
            'total' => array_sum(array_column($lignes, 'cout')),
            'source' => self::SOURCE_CALCULE,
            'manquants' => array_values(array_unique($manquants)),
            'complet' => [] === $manquants,
            'prixAchatManquants' => array_values($sansPrix),
        ];
    }

    /** Marge brute en % du prix (arrondie à l'unité), ou null sans prix ni coût. */
    public static function marge(?int $prix, ?int $cout): ?int
    {
        if (null === $prix || null === $cout || $prix <= 0) {
            return null;
        }

        return (int) round(($prix - $cout) / $prix * 100);
    }

    /**
     * Couleur de la barre de marge : bonne au-dessus de la cible, à
     * surveiller entre le minimum et la cible, faible en dessous.
     */
    public static function niveau(?int $marge, int $cible, int $minimum): string
    {
        return match (true) {
            null === $marge => 'inconnue',
            $marge < $minimum => 'faible',
            $marge < $cible => 'moyenne',
            default => 'bonne',
        };
    }

    /**
     * Marge moyenne pondérée par les ventes : chaque produit compte pour
     * son chiffre d'affaires. Sans vente, moyenne simple.
     *
     * @param list<array{marge: ?int, ventes: int}> $produits
     *
     * @return array{marge: ?int, ponderee: bool}
     */
    public static function margeMoyenne(array $produits): array
    {
        $poids = 0;
        $somme = 0.0;
        $simples = [];
        foreach ($produits as $p) {
            if (null === $p['marge']) {
                continue;
            }
            $simples[] = $p['marge'];
            if ($p['ventes'] > 0) {
                $poids += $p['ventes'];
                $somme += $p['marge'] * $p['ventes'];
            }
        }
        if ($poids > 0) {
            return ['marge' => (int) round($somme / $poids), 'ponderee' => true];
        }

        return ['marge' => [] === $simples ? null : (int) round(array_sum($simples) / \count($simples)), 'ponderee' => false];
    }

    private static function nombre(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 3, ',', ''), '0'), ',');
    }
}
