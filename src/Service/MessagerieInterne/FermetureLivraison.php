<?php

namespace App\Service\MessagerieInterne;

use App\Entity\CommandesDetails;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Groupe de projet fermé tout seul à la livraison (maquette MI02) : une
 * ligne de commande qui passe « livrée », quel que soit l'écran (tournée,
 * livraison directe, bon de livraison), fait vérifier en fin de requête
 * les groupes liés à sa commande (ou au devis de cette commande). La
 * commande app:messagerie-interne:fermer rattrape le reste chaque minute.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class FermetureLivraison implements EventSubscriberInterface
{
    /** @var array<int, true> */
    private array $aVerifier = [];

    public function __construct(
        /** @var \Closure(): Groupes */
        #[AutowireServiceClosure(Groupes::class)] private readonly \Closure $groupes,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'fermerEnAttente', ConsoleEvents::TERMINATE => 'fermerEnAttente'];
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
                $this->aVerifier[$commande] = true;
            }
        }
    }

    public function fermerEnAttente(): void
    {
        $ids = array_keys($this->aVerifier);
        $this->aVerifier = [];
        if ([] === $ids) {
            return;
        }
        try {
            ($this->groupes)()->fermerLivres($ids);
        } catch (\Throwable $e) {
            $this->logger->warning('Groupes de projet non fermés à la livraison : {erreur}', ['erreur' => $e->getMessage()]);
        }
    }
}
