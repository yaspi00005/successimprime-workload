<?php

namespace App\Form;

/**
 * Mode de configuration réellement appliqué à une ligne de devis ou de
 * commande quand le formulaire est envoyé.
 *
 * Le choix du mode n'est plus proposé à l'agent (groupe de radios caché)
 * et CommandesController enregistre toute ligne produit en « manuel ».
 * Une ancienne ligne enregistrée en « automatique » renvoie pourtant son
 * radio coché : la traiter en automatique réappliquait la configuration
 * du catalogue (largeur et longueur effacées ou remplacées, prix du
 * catalogue) et vidait ses finitions, d'où le refus « La largeur et la
 * longueur sont obligatoires » sur une ligne au m² qu'on n'avait pas
 * touchée. Seul l'ancien mode « libre » est encore reconnu ; tout le
 * reste est « manuel » : la ligne garde ses dimensions, son prix et ses
 * finitions tels qu'enregistrés.
 */
final class ModeConfigurationLigne
{
    /**
     * @param array<mixed> $donnees données envoyées pour une ligne
     */
    public static function soumis(array $donnees): string
    {
        $mode = $donnees['modeConfiguration'] ?? '';

        return \is_string($mode) && 'libre' === strtolower(trim($mode)) ? 'libre' : 'manuel';
    }
}
