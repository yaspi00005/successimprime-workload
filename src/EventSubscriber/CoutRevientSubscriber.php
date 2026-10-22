<?php

namespace App\EventSubscriber;

use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Service\CalculateurCoutRevient;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Enregistre le cout de revient calcule sur chaque ligne de commande
 * creee, ou modifiee dans ce qui le fait varier (dimensions, quantite,
 * produit, machine, faces, finitions). Une ligne qui ne change que de
 * statut garde le cout du jour de sa saisie, meme si les prix d'achat
 * ont bouge depuis.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class CoutRevientSubscriber
{
    private const CHAMPS_DU_COUT = [
        'largeur',
        'longueur',
        'surface',
        'quantite',
        'produit',
        'produitConfiguration',
        'machine',
        'nombreFaces',
    ];

    public function __construct(
        private readonly CalculateurCoutRevient $calculateur,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $lignes = [];

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof CommandesDetails) {
                $lignes[spl_object_id($entite)] = $entite;
            } elseif ($entite instanceof CommandeDetailFinition && $entite->getCommandeDetail()) {
                $lignes[spl_object_id($entite->getCommandeDetail())] = $entite->getCommandeDetail();
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if ($entite instanceof CommandesDetails
                && [] !== array_intersect(self::CHAMPS_DU_COUT, array_keys($uow->getEntityChangeSet($entite)))) {
                $lignes[spl_object_id($entite)] = $entite;
            } elseif ($entite instanceof CommandeDetailFinition && $entite->getCommandeDetail()) {
                $lignes[spl_object_id($entite->getCommandeDetail())] = $entite->getCommandeDetail();
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            if ($entite instanceof CommandeDetailFinition && $entite->getCommandeDetail()
                && !$uow->isScheduledForDelete($entite->getCommandeDetail())) {
                $lignes[spl_object_id($entite->getCommandeDetail())] = $entite->getCommandeDetail();
            }
        }

        $metadonnees = $em->getClassMetadata(CommandesDetails::class);

        foreach ($lignes as $ligne) {
            $cout = $this->calculateur->pourLigne($ligne)['total'];

            if ($cout === $ligne->getCoutRevient()) {
                continue;
            }

            $ligne->setCoutRevient($cout);
            $uow->recomputeSingleEntityChangeSet($metadonnees, $ligne);
        }
    }
}
