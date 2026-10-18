<?php

namespace App\Service\Stock;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Réserve ou libère le stock d'une commande dès qu'elle est enregistrée,
 * quel que soit l'écran qui l'a créée ou modifiée : commande validée,
 * remise en brouillon, mise à la corbeille, ligne ajoutée, quantité
 * changée, ligne annulée ou restaurée, ligne livrée.
 *
 * onFlush repère les commandes concernées ; postFlush les passe à
 * ReservationsCommande::synchroniser() puis enregistre les réservations
 * (même façon de faire qu'AuditSubscriber).
 *
 * Sur le site en ligne (APP_EN_LIGNE=1) rien n'est fait : le stock est
 * tenu au serveur de l'imprimerie, qui réserve quand la commande arrive
 * par la synchronisation et qu'elle est confirmée.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ReservationsCommandeListener
{
    /** Champs d'une ligne qui changent ce qu'elle consomme. */
    public const CHAMPS_LIGNE = ['quantite', 'surface', 'longueur', 'largeur', 'produit', 'article', 'typeLigne', 'statutProduction'];

    /** Champs d'une commande qui décident si elle réserve. */
    public const CHAMPS_COMMANDE = ['statut', 'deleted', 'validationEntreprise'];

    /** @var array<int, Commandes> */
    private array $aSynchroniser = [];

    private bool $enCours = false;

    public function __construct(
        /** @var \Closure(): ReservationsCommande (différé : il dépend du gestionnaire d'entités) */
        #[AutowireServiceClosure(ReservationsCommande::class)] private readonly \Closure $reservations,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->enLigne || $this->enCours) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entite) {
            if ($entite instanceof Commandes && $entite->isStatut()) {
                $this->marquer($entite);
            } elseif ($entite instanceof CommandesDetails) {
                $this->marquer($entite->getCommande());
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entite) {
            $champs = array_keys($uow->getEntityChangeSet($entite));
            if ($entite instanceof Commandes && [] !== array_intersect($champs, self::CHAMPS_COMMANDE)) {
                $this->marquer($entite);
            } elseif ($entite instanceof CommandesDetails && [] !== array_intersect($champs, self::CHAMPS_LIGNE)) {
                $this->marquer($entite->getCommande());
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->enCours || [] === $this->aSynchroniser) {
            return;
        }
        $em = $args->getObjectManager();
        $commandes = $this->aSynchroniser;
        $this->aSynchroniser = [];
        $this->enCours = true;

        try {
            $change = false;
            foreach ($commandes as $commande) {
                if (null === $commande->getId() || !$em->contains($commande)) {
                    continue;
                }
                $resultat = ($this->reservations)()->synchroniser($commande);
                $change = $change || $resultat['change'] || [] !== $resultat['manquants'];
            }
            if ($change) {
                $em->flush();
            }
        } catch (\Throwable $e) {
            // La commande est déjà enregistrée : un souci de stock ne doit pas la faire échouer.
            $this->logger->error('Réservation du stock impossible : {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            $this->enCours = false;
        }
    }

    private function marquer(?Commandes $commande): void
    {
        if (null !== $commande && !$commande->estSoldeOuverture()) {
            $this->aSynchroniser[spl_object_id($commande)] = $commande;
        }
    }
}
