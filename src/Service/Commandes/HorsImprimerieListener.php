<?php

namespace App\Service\Commandes;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Commande « hors imprimerie » (docs/hors-imprimerie.md) : matériel,
 * informatique… vendus sans rien fabriquer. Ses lignes ne passent ni par
 * la prépresse ni par l'atelier, quel que soit l'écran, le service ou la
 * synchronisation qui enregistre la commande :
 *
 * - case cochée (commande créée, case cochée ensuite, ligne ajoutée ou
 *   remise « à produire » par la saisie) : les lignes encore « à
 *   produire » et pas commencées passent « non requise », sans prépresse ;
 * - case décochée : les lignes de produit « non requise » redeviennent
 *   « à produire », avec la prépresse. Articles et lignes libres restent
 *   en livraison directe, comme à la saisie.
 *
 * Une ligne commencée (prépresse, production), livrée ou annulée n'est
 * jamais touchée.
 *
 * Passe avant les autres écouteurs (réservations de stock, journal de
 * synchronisation, journal d'activité) pour qu'ils voient les statuts posés.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 90)]
final class HorsImprimerieListener
{
    /**
     * Applique la règle à une ligne ; true si elle a changé.
     *
     * $hors : la commande est hors imprimerie (sinon : retour à l'imprimerie).
     */
    public static function appliquer(CommandesDetails $ligne, bool $hors): bool
    {
        if ($hors) {
            if (!self::pasCommencee($ligne)) {
                return false;
            }
            // setProductionNecessaire(false) passe la ligne « à produire » en « non requise ».
            $ligne->setPrePresseNecessaire(false)->setProductionNecessaire(false);

            return true;
        }

        if (CommandesDetails::PRODUCTION_NON_REQUISE !== $ligne->getStatutProduction() || CommandesDetails::TYPE_PRODUIT !== $ligne->getTypeLigne()) {
            return false;
        }
        // setProductionNecessaire(true) la remet « à produire ».
        $ligne->setProductionNecessaire(true)->setPrePresseNecessaire(true);

        return true;
    }

    /** « À produire », sans production commencée ni contrôle prépresse. */
    public static function pasCommencee(CommandesDetails $ligne): bool
    {
        return CommandesDetails::PRODUCTION_A_PRODUIRE === $ligne->getStatutProduction()
            && null === $ligne->getProductionDebuteLe()
            && $ligne->getControlesPrePresse()->isEmpty();
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, array{0: CommandesDetails, 1: bool}> $aRegler ligne et règle à appliquer */
        $aRegler = [];
        $commande = static function (Commandes $c) use (&$aRegler, $uow): void {
            foreach ($c->getCommandesDetails() as $ligne) {
                if (!$uow->isScheduledForDelete($ligne)) {
                    $aRegler[spl_object_id($ligne)] = [$ligne, $c->isHorsImprimerie()];
                }
            }
        };

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof Commandes && $entite->isHorsImprimerie()) {
                $commande($entite);
            } elseif ($entite instanceof CommandesDetails && true === $entite->getCommande()?->isHorsImprimerie()) {
                $aRegler[spl_object_id($entite)] = [$entite, true];
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if ($entite instanceof Commandes && \array_key_exists('horsImprimerie', $uow->getEntityChangeSet($entite))) {
                $commande($entite);
            } elseif ($entite instanceof CommandesDetails && true === $entite->getCommande()?->isHorsImprimerie()) {
                $aRegler[spl_object_id($entite)] ??= [$entite, true];
            }
        }

        foreach ($aRegler as [$ligne, $hors]) {
            if (self::appliquer($ligne, $hors)) {
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(CommandesDetails::class), $ligne);
            }
        }
    }
}
