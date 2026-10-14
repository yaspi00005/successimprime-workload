<?php

namespace App\Service\Site;

/**
 * Icône d'une carte du site (engagements, guide, à propos), choisie
 * d'après les mots de son titre : rien à régler dans l'admin.
 */
final class IconesSite
{
    /** Icône => mots qui la déclenchent, dans l'ordre de priorité. */
    public const MOTS = [
        'bat' => ['bat', 'bon à tirer', 'accord'],
        'reimpression' => ['réimpression', 'reimpression', 'garantie', 'défaut'],
        'delai' => ['délai', 'delai', 'rapide', 'heure'],
        'prix' => ['prix', 'tarif', 'paiement', 'orange money', 'acompte'],
        'fichier' => ['format', 'fichier', 'pdf', 'gabarit'],
        'resolution' => ['résolution', 'resolution', 'dpi', 'netteté'],
        'ciseaux' => ['fonds perdus', 'fond perdu', 'découpe', 'marge'],
        'couleurs' => ['couleur', 'cmjn'],
        'qualite' => ['qualité', 'qualite', 'contrôle'],
        'proximite' => ['proximité', 'proximite', 'whatsapp', 'écoute', 'conseil'],
        'livraison' => ['livraison', 'livrer', 'pose', 'retrait'],
        'equipe' => ['direction', 'équipe', 'equipe', 'accueil'],
        'pao' => ['pao', 'graphis', 'création', 'maquette'],
        'imprimante' => ['imprimante', 'machine', 'atelier', 'grand format', 'solvant', 'dtf', 'laser', 'impression'],
    ];

    public const DEFAUT = 'etoile';

    public static function pour(string $titre): string
    {
        $titre = mb_strtolower($titre);
        foreach (self::MOTS as $icone => $mots) {
            foreach ($mots as $mot) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($mot, '/').'/u', $titre)) {
                    return $icone;
                }
            }
        }

        return self::DEFAUT;
    }
}
