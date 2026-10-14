<?php

namespace App\Twig;

use App\Service\Statistiques\Export\Tableau;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Affichage des chiffres des statistiques : valeur d'une cellule d'export
 * selon son format, et libellé et couleur de l'état d'un objectif.
 */
final class StatistiquesExtension extends AbstractExtension
{
    public const ETATS = [
        'atteint' => ['libelle' => 'Atteint', 'couleur' => 'vert'],
        'en_bonne_voie' => ['libelle' => 'En bonne voie', 'couleur' => 'vert'],
        'a_surveiller' => ['libelle' => 'À surveiller', 'couleur' => 'orange'],
        'en_retard' => ['libelle' => 'En retard', 'couleur' => 'rouge'],
        'manque' => ['libelle' => 'Non atteint', 'couleur' => 'rouge'],
        'a_venir' => ['libelle' => 'Début de mois', 'couleur' => 'gris'],
        'sans_objectif' => ['libelle' => 'Sans objectif', 'couleur' => 'gris'],
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('valeur_export', [Tableau::class, 'afficher']),
            new TwigFunction('etat_objectif', static fn (string $etat): array => self::ETATS[$etat] ?? self::ETATS['sans_objectif']),
        ];
    }
}
