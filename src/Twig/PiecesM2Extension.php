<?php

namespace App\Twig;

use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Service\PiecesParMetreCarre;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pièces au m² dans les gabarits (docs/saisie-devis-commandes.md) :
 * - pieces_m2_espacement() : espacement (mm) du réglage, pour les pages qui
 *   font le calcul (templates/partials/_pieces_m2.html.twig, saisie) ;
 * - pieces_m2_ligne(ligne, court) : ce qu'une ligne facturée en pièces au
 *   m² montre (fiche, PDF), vide pour une autre ligne. Jamais de montant.
 */
final class PiecesM2Extension extends AbstractExtension
{
    public function __construct(private readonly PiecesParMetreCarre $piecesM2)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pieces_m2_espacement', $this->piecesM2->espacement(...)),
            new TwigFunction('pieces_m2_ligne', self::ligne(...)),
        ];
    }

    /**
     * « 361 pièces au m² (19 × 19, espacement 1 mm) · 2,78 m² facturés »,
     * ou en court « 361 pièces au m² ».
     */
    public static function ligne(mixed $ligne, bool $court = false): string
    {
        $calcul = $ligne instanceof CommandesDetails || $ligne instanceof DevisDetails ? $ligne->getPiecesAuM2() : null;
        if (null === $calcul) {
            return '';
        }
        if ($court) {
            return number_format($calcul['parM2'], 0, ',', "\u{a0}").' pièce'.($calcul['parM2'] > 1 ? 's' : '').' au m²';
        }

        return PiecesParMetreCarre::libelle($calcul).' · '.number_format($calcul['surface'], 2, ',', "\u{a0}").' m² facturés';
    }
}
