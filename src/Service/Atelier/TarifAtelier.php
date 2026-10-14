<?php

namespace App\Service\Atelier;

use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;

/**
 * Prix de l'Atelier 3D (docs/atelier-3d.md), toujours calculé ici, jamais
 * repris de la page : le prix du catalogue (prix public, ou prix B2B pour
 * un revendeur connecté), la seconde face marquée en plus, puis la remise
 * du palier de quantité (Réglages › Atelier 3D).
 *
 * Sur la ligne de commande ou de devis, la face en plus entre dans le prix
 * unitaire et le palier devient la remise par pièce : l'atelier voit et
 * peut changer l'un et l'autre en confirmant. Une promotion du client se
 * compare ensuite à cette remise (pas de cumul, docs/promotions.md).
 */
final class TarifAtelier
{
    /** Le prix net d'une pièce remisée est arrondi à 50 F, comme sur le prototype. */
    public const ARRONDI = 50;

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /**
     * Réglages de la tuile, avec leurs valeurs par défaut et remis dans
     * l'ordre (le second palier toujours au-dessus du premier).
     *
     * @return array{paliers: list<array{quantite: int, pourcentage: float}>, face_en_plus: int, paliers_b2b: bool}
     */
    public function reglages(): array
    {
        $valeurs = [];
        foreach (CatalogueParametres::tuiles()['atelier_3d']['champs'] as $champ) {
            $valeurs[$champ['cle']] = $this->parametres->valeur('atelier_3d', $champ['cle']) ?? $champ['defaut'];
        }

        return self::normaliser($valeurs);
    }

    /**
     * @param array<string, mixed> $valeurs valeurs de la tuile
     *
     * @return array{paliers: list<array{quantite: int, pourcentage: float}>, face_en_plus: int, paliers_b2b: bool}
     */
    public static function normaliser(array $valeurs): array
    {
        $paliers = [];
        foreach ([1, 2] as $n) {
            $quantite = max(2, (int) ($valeurs['palier'.$n.'_quantite'] ?? 0));
            $pourcentage = max(0.0, min(50.0, (float) ($valeurs['palier'.$n.'_remise'] ?? 0)));
            if ($pourcentage > 0) {
                $paliers[$quantite] = ['quantite' => $quantite, 'pourcentage' => max($pourcentage, $paliers[$quantite]['pourcentage'] ?? 0)];
            }
        }
        ksort($paliers);
        // Un palier plus haut ne donne jamais moins qu'un palier plus bas.
        $retenus = [];
        foreach ($paliers as $p) {
            if ([] === $retenus || $p['pourcentage'] > end($retenus)['pourcentage']) {
                $retenus[] = $p;
            }
        }

        return [
            'paliers' => $retenus,
            'face_en_plus' => max(0, (int) ($valeurs['face_en_plus'] ?? 0)),
            'paliers_b2b' => (bool) ($valeurs['paliers_b2b'] ?? false),
        ];
    }

    /**
     * Prix d'une pièce et remise par pièce.
     *
     * @param int                                                                                                         $prixCatalogue prix de la configuration (public ou B2B)
     * @param array{paliers: list<array{quantite: int, pourcentage: float}>, face_en_plus: int, paliers_b2b: bool} $reglages
     *
     * @return array{prixUnitaire: int, remiseUnitaire: int, net: int, pourcentage: float, palier: ?int, prochain: ?array{quantite: int, pourcentage: float, manque: int}}
     */
    public static function calculer(int $prixCatalogue, int $faces, int $quantite, array $reglages, bool $b2b): array
    {
        $prix = max(0, $prixCatalogue) + $reglages['face_en_plus'] * max(0, $faces - 1);
        $paliers = $b2b && !$reglages['paliers_b2b'] ? [] : $reglages['paliers'];

        $atteint = null;
        $prochain = null;
        foreach ($paliers as $p) {
            if ($quantite >= $p['quantite']) {
                $atteint = $p;
            } elseif (null === $prochain) {
                $prochain = $p + ['manque' => $p['quantite'] - $quantite];
            }
        }
        $net = $prix;
        if (null !== $atteint) {
            $net = min($prix, (int) (round($prix * (1 - $atteint['pourcentage'] / 100) / self::ARRONDI) * self::ARRONDI));
        }

        return [
            'prixUnitaire' => $prix,
            'remiseUnitaire' => $prix - $net,
            'net' => $net,
            'pourcentage' => $atteint['pourcentage'] ?? 0.0,
            'palier' => $atteint['quantite'] ?? null,
            'prochain' => $prochain,
        ];
    }

    /**
     * Pose le prix de l'atelier sur une ligne déjà construite au prix du
     * catalogue (NouvelleCommande::construireLigne) et refait ses totaux.
     *
     * @param array{paliers: list<array{quantite: int, pourcentage: float}>, face_en_plus: int, paliers_b2b: bool} $reglages
     *
     * @return array{prixUnitaire: int, remiseUnitaire: int, net: int, pourcentage: float, palier: ?int, prochain: ?array{quantite: int, pourcentage: float, manque: int}}
     */
    public static function appliquer(CommandesDetails|DevisDetails $ligne, int $faces, array $reglages, bool $b2b): array
    {
        $calcul = self::calculer((int) round((float) $ligne->getPrixUnitaire()), $faces, (int) $ligne->getQuantite(), $reglages, $b2b);
        $ligne->setPrixUnitaire($calcul['prixUnitaire'])->setRemise($calcul['remiseUnitaire'])->setNombreFaces(max(1, $faces));
        $ligne->calculerTotaux($b2b);

        return $calcul;
    }
}
