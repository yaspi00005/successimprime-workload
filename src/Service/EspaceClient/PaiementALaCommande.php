<?php

namespace App\Service\EspaceClient;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Service\Paiement\OrangeMoney;

/**
 * Paiement à la commande (étape 4 de la nouvelle commande, maquette CO04) :
 * l'acompte ou le tout d'une commande en ligne encore « à confirmer », payé
 * par le même règlement que « Factures et paiements » (ReglementsEnLigne,
 * un passage chez Orange Money, un paiement « en attente » par commande).
 * Rien n'est validé tout seul : la caisse valide le paiement au local, et la
 * production attend la confirmation de l'imprimerie.
 *
 * « Factures et paiements » ne propose que les commandes confirmées
 * (FacturesClient::payable) ; ici, seulement la commande en ligne que ce
 * client vient de passer.
 */
final class PaiementALaCommande
{
    public const ACOMPTE = 'acompte';
    public const TOUT = 'tout';

    /**
     * Commande encore « à confirmer » qui se paie déjà en ligne : passée en
     * ligne par ce client (pas au comptoir), ni supprimée, ni en attente ou
     * refusée par le valideur de son entreprise. Le droit de payer
     * (CompteClient::DROIT_PAYEUR) est vérifié par la page.
     */
    public static function payable(Commandes $commande, CompteClient $compte): bool
    {
        return !$commande->isStatut()
            && Commandes::CANAL_EN_LIGNE === $commande->getCanal()
            && !$commande->isDeleted()
            && !$commande->attendValidationEntreprise()
            && !$commande->estRefuseeParEntreprise()
            && null !== $commande->getClients()
            && null !== $compte->getClient()
            && $commande->getClients()->getId() === $compte->getClient()->getId();
    }

    /**
     * L'élément à payer : l'acompte (pourcentage du client, arrondi au franc
     * supérieur, moins ce qui est déjà payé ou en validation) ou tout ce qui
     * reste. Null quand il n'y a rien à payer.
     */
    public static function element(Commandes $commande, string $part, int $pourcentage, \DateTimeImmutable $maintenant): ?ElementAPayer
    {
        $aPayer = OrangeMoney::aPayer($commande);
        if ($aPayer <= 0) {
            return null;
        }
        $jour = $maintenant->setTime(0, 0);
        $pourcentage = max(0, min(100, $pourcentage));
        if (self::ACOMPTE === $part && $pourcentage > 0 && $pourcentage < 100) {
            $total = (int) $commande->getTotalTtc();
            $couvert = $total - $aPayer;
            $montant = min($aPayer, DelaisCommande::acompte($total, $pourcentage) - $couvert);
            // Acompte déjà couvert : il ne reste que le tout.
            if ($montant > 0 && $montant < $aPayer) {
                return new ElementAPayer($commande, null, ElementAPayer::ACOMPTE, $montant, $aPayer, $jour, ElementAPayer::A_PAYER, $pourcentage);
            }
        }

        return new ElementAPayer($commande, null, ElementAPayer::COMMANDE, $aPayer, $aPayer, $jour, ElementAPayer::A_PAYER);
    }
}
