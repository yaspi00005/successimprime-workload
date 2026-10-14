<?php

namespace App\Service\Societes;

use App\Entity\AppartientASociete;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\Societe;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;

/**
 * Pose la société des documents à l'enregistrement (docs/societes.md),
 * quel que soit l'écran, le service ou la synchronisation qui les crée :
 * personne ne la saisit.
 *
 * - Nouvelle donnée sans société : la règle de SocieteCourante.
 * - Émetteur changé sur un devis ou une facture : la société suit la
 *   nouvelle entreprise, et les paiements de la facture avec elle.
 * - Une commande créée depuis un devis prend la société du devis ; à sa
 *   facture, elle prend celle de la facture (avec ses paiements sans
 *   facture), sauf si ses factures sont de plusieurs sociétés.
 *
 * Passe avant les autres écouteurs (synchronisation, journal d'activité)
 * pour qu'ils voient la société posée.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 100)]
final class RattachementSociete
{
    public function __construct(private readonly SocieteCourante $societes)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        /** @var array<int, AppartientASociete> $posees */
        $posees = [];
        $insertions = $uow->getScheduledEntityInsertions();

        // 1. Devis et factures : la société de l'émetteur, à la création ou quand il change.
        $factures = [];
        foreach ([...$insertions, ...$uow->getScheduledEntityUpdates()] as $entite) {
            if (!$entite instanceof Devis && !$entite instanceof Factures) {
                continue;
            }
            $nouvelle = $uow->isScheduledForInsert($entite);
            if ($nouvelle ? null !== $entite->getSociete() : !\array_key_exists('emetteur', $uow->getEntityChangeSet($entite))) {
                continue;
            }
            $avant = $entite->getSociete();
            $this->poser($entite, $this->societes->pourEmetteur($entite->getEmetteur()), $posees);
            if ($entite instanceof Factures) {
                $factures[] = $entite;
                foreach ($nouvelle ? [] : $entite->getPaiements() as $paiement) {
                    if (self::meme($paiement->getSociete(), $avant)) {
                        $this->poser($paiement, $entite->getSociete(), $posees);
                    }
                }
            }
        }

        // 2. Devis transformé en commande : la commande, créée dans le même enregistrement, le suit.
        foreach ([...$insertions, ...$uow->getScheduledEntityUpdates()] as $entite) {
            if ($entite instanceof Devis && null !== $entite->getCommande() && null === $entite->getCommande()->getSociete() && $uow->isScheduledForInsert($entite->getCommande())) {
                $this->poser($entite->getCommande(), $this->societes->pour($entite), $posees);
            }
        }

        // 3. Facture créée, ou d'un autre émetteur : sa commande la suit.
        foreach ($insertions as $entite) {
            if ($entite instanceof Factures && !\in_array($entite, $factures, true)) {
                $factures[] = $entite;
            }
        }
        foreach ($factures as $facture) {
            $this->commandeSuitSaFacture($facture, $uow, $posees);
        }

        // 4. Tout le reste : la règle de SocieteCourante (document lié, sinon société par défaut).
        foreach ($insertions as $entite) {
            if ($entite instanceof AppartientASociete && null === $entite->getSociete()) {
                $this->poser($entite, $this->societes->pour($entite), $posees);
            }
        }

        foreach ($posees as $entite) {
            $uow->recomputeSingleEntityChangeSet($em->getClassMetadata($entite::class), $entite);
        }
    }

    /**
     * La commande prend la société de sa facture, avec ses paiements sans
     * facture qui avaient la société de la commande. Rien ne bouge si la
     * commande a déjà une facture (non annulée) d'une autre société.
     *
     * @param array<int, AppartientASociete> $posees
     */
    private function commandeSuitSaFacture(Factures $facture, UnitOfWork $uow, array &$posees): void
    {
        $commande = $facture->getCommande();
        $societe = $facture->getSociete();
        if (!$commande instanceof Commandes || null === $societe || self::meme($commande->getSociete(), $societe) || $uow->isScheduledForDelete($commande)) {
            return;
        }
        foreach ($commande->getFactures() as $autre) {
            if ($autre !== $facture && !$autre->estAnnulee() && !self::meme($autre->getSociete(), $societe)) {
                return;
            }
        }

        $avant = $commande->getSociete();
        $this->poser($commande, $societe, $posees);
        foreach ($commande->getPaiements() as $paiement) {
            if (null === $paiement->getFacture() && self::meme($paiement->getSociete(), $avant)) {
                $this->poser($paiement, $societe, $posees);
            }
        }
    }

    /**
     * @param array<int, AppartientASociete> $posees
     */
    private function poser(AppartientASociete $entite, ?Societe $societe, array &$posees): void
    {
        if (null === $societe || self::meme($entite->getSociete(), $societe)) {
            return;
        }
        $entite->setSociete($societe);
        $posees[spl_object_id($entite)] = $entite;
    }

    private static function meme(?Societe $a, ?Societe $b): bool
    {
        return $a === $b || (null !== $a && null !== $b && null !== $a->getId() && $a->getId() === $b->getId());
    }
}
