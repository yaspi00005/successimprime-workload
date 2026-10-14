<?php

namespace App\Security;

use App\Entity\CompteClient;

/**
 * Ce qu'un compte « Lecture · suivi » (maquette EC04) peut ouvrir et
 * envoyer dans l'espace client. Il voit ses commandes, ses livraisons et
 * ses fichiers ; il ne commande pas, ne valide ni BAT ni devis, ne paie
 * pas, n'ajoute personne et ne modifie rien. Vérifié à chaque requête
 * (EspaceClientSubscriber), en plus des contrôles de chaque page.
 */
final class DroitsEspace
{
    /** Ce qu'il peut encore envoyer : ce qui ne regarde que lui. */
    public const ENVOIS_LECTURE = [
        'app_espace_notification_lue',
        'app_espace_notifications_tout_lu',
        'app_espace_notifications_preferences',
        'app_espace_push_abonner',
        'app_espace_push_desabonner',
        'app_espace_mot_de_passe',
        'app_espace_appareils_deconnecter',
        // Assistant : ses outils ne lui montrent que ce qu'un compte en lecture voit.
        'app_espace_assistant_message',
        'app_espace_assistant_note',
        'app_espace_assistant_historique',
    ];

    /** Pages qui ne servent qu'à commander, payer, accepter ou écrire : refusées, même à l'ouverture. */
    public const PAGES_ECRITURE = [
        'app_espace_nouvelle_commande',
        'app_espace_produits',
        'app_espace_devis',
        'app_espace_devis_pdf',
        'app_espace_payer',
        'app_espace_payer_retour',
        'app_espace_factures',
        'app_espace_facture_pdf',
        'app_espace_fidelite',
        'app_espace_reclamation_nouvelle',
        'app_espace_avis_commande',
        'app_espace_avis_sondage',
        'app_espace_utilisateur_nouveau',
        'app_espace_utilisateur',
        'app_espace_messages',
        // Atelier 3D (docs/atelier-3d.md) : il ne sert qu'à commander.
        'app_espace_atelier',
        'app_espace_atelier_prix',
        'app_espace_creations',
        'app_espace_creation_fichier',
    ];

    /** Le compte peut-il faire cette requête ? */
    public static function permis(CompteClient $compte, string $methode, string $route): bool
    {
        if (!$compte->estLectureSeule() || '' === $route) {
            return true;
        }
        if (!\in_array(strtoupper($methode), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return \in_array($route, self::ENVOIS_LECTURE, true);
        }

        return !\in_array($route, self::PAGES_ECRITURE, true);
    }
}
