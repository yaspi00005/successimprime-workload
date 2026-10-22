<?php

namespace App\Service\Catalogue;

use App\Service\Parametres\Parametres;

/**
 * Garde-fous des prix (maquette PR03, docs/produits.md) : marge à viser,
 * marge minimale après remise et remise maximale sans accord de la
 * direction. Réglés dans Paramètres › Ventes › Grille tarifaire.
 */
final class GardeFousPrix
{
    public const CIBLE_DEFAUT = 30;
    public const MINIMUM_DEFAUT = 25;
    public const REMISE_DEFAUT = 10;

    public const RAISON_REMISE = 'remise';
    public const RAISON_MARGE = 'marge';

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /**
     * @return array{cible: int, minimum: int, remise: int}
     */
    public function valeurs(): array
    {
        $v = fn (string $cle, int $defaut): int => max(0, (int) ($this->parametres->valeur('grille', $cle) ?? $defaut));

        return [
            'cible' => $v('marge_cible_pct', self::CIBLE_DEFAUT),
            'minimum' => $v('marge_min_pct', self::MINIMUM_DEFAUT),
            'remise' => $v('remise_max_pct', self::REMISE_DEFAUT),
        ];
    }

    /** Remise en % d'un prix négocié par rapport au prix de la grille du client. */
    public static function remise(int $prixGrille, int $prix): float
    {
        return $prixGrille <= 0 ? 0.0 : round(max(0, $prixGrille - $prix) / $prixGrille * 100, 1);
    }

    /**
     * Pourquoi un prix négocié demande l'accord de la direction : remise
     * trop forte, ou marge trop faible quand le coût est connu.
     *
     * @return list<string> RAISON_REMISE, RAISON_MARGE
     */
    public static function controler(int $prixGrille, int $prix, ?int $cout, int $remiseMax, int $margeMin): array
    {
        $raisons = [];
        if (self::remise($prixGrille, $prix) > $remiseMax) {
            $raisons[] = self::RAISON_REMISE;
        }
        $marge = CoutProduit::marge($prix, $cout);
        if (null !== $marge && $marge < $margeMin) {
            $raisons[] = self::RAISON_MARGE;
        }

        return $raisons;
    }
}
