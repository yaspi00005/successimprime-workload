<?php

namespace App\Service\Sondages;

use App\Entity\CommandesDetails;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Repère les lignes qui passent « livrée », quel que soit l'écran
 * (tournée, livraison directe, bon de livraison) : la commande est
 * vérifiée en fin de requête et reçoit son invitation au sondage si elle
 * est entièrement livrée (InvitationsSondage).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class LivraisonSondageListener
{
    public function __construct(
        /** @var \Closure(): InvitationsSondage (différé : il dépend du gestionnaire d'entités) */
        #[AutowireServiceClosure(InvitationsSondage::class)] private readonly \Closure $invitations,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            if (!$entite instanceof CommandesDetails) {
                continue;
            }
            $statut = $uow->getEntityChangeSet($entite)['statutProduction'][1] ?? null;
            $commande = $entite->getCommande()?->getId();
            if (CommandesDetails::PRODUCTION_LIVREE === $statut && null !== $commande) {
                ($this->invitations)()->aVerifier($commande);
            }
        }
    }
}
