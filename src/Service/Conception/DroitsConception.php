<?php

namespace App\Service\Conception;

use App\Entity\CompteClient;
use App\Entity\DemandeConception;

/**
 * Qui peut quoi dans une demande de conception, pour un compte de
 * l'espace client. Vérifié à chaque envoi de formulaire, pas seulement
 * pour cacher un bouton.
 *
 *  - tout compte voit les demandes de son client ;
 *  - un compte en lecture seule ne fait que suivre, sans montant ;
 *  - un demandeur prépare la demande, et l'impression d'un visuel IA
 *    (validée ensuite dans l'entreprise) ;
 *  - seul un payeur (ou un particulier) paie l'acompte, achète du crédit
 *    IA et génère des images, puisque chaque image est payée.
 */
final class DroitsConception
{
    public static function lectureSeule(CompteClient $compte): bool
    {
        // Compte « Lecture · suivi » d'une entreprise (Mon compte) : ni préparation, ni paiement, ni montant.
        return $compte->estLectureSeule();
    }

    public static function peutPreparer(CompteClient $compte): bool
    {
        return $compte->isActif() && !self::lectureSeule($compte);
    }

    /** Payer l'acompte, acheter du crédit IA, générer une image. */
    public static function peutPayer(CompteClient $compte): bool
    {
        return self::peutPreparer($compte) && $compte->peut(CompteClient::DROIT_PAYEUR);
    }

    /** Une demande de son client seulement. */
    public static function peutVoir(CompteClient $compte, DemandeConception $demande): bool
    {
        return null !== $compte->getClient() && $demande->getClient()?->getId() === $compte->getClient()->getId();
    }

    /** Changer les textes, le support, les éléments : avant l'envoi à l'atelier. */
    public static function peutModifier(CompteClient $compte, DemandeConception $demande): bool
    {
        return self::peutVoir($compte, $demande) && self::peutPreparer($compte) && $demande->estOuverte();
    }
}
