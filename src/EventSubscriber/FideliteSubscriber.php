<?php

namespace App\EventSubscriber;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Paiements;
use App\Service\Fidelite\Fidelite;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Points de fidélité sur l'ERP local (docs/fidelite.md) : un paiement
 * validé rapporte, un paiement annulé ou rejeté retire ce qu'il avait
 * rapporté, une commande annulée (ou supprimée) aussi, et ce qui est
 * rétabli est recompté. Le relevé est écrit après l'enregistrement (les
 * paiements ont alors leur identifiant), dans un second flush.
 *
 * Un incident ici ne bloque pas l'encaissement, déjà enregistré : il est
 * journalisé et « bin/console app:fidelite --rattraper » recompte.
 * Rien ne se calcule sur le site en ligne, qui reçoit le relevé.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class FideliteSubscriber
{
    /** @var array<int, Paiements> */
    private array $paiements = [];

    /** @var array<int, Commandes> */
    private array $commandes = [];

    /** @var array<int, CommandesDetails> */
    private array $lignes = [];

    private bool $enCours = false;

    public function __construct(
        private readonly Fidelite $fidelite,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->enLigne || $this->enCours) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $e) {
            if ($e instanceof Paiements && $e->estValide()) {
                $this->paiements[spl_object_id($e)] = $e;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $e) {
            if ($e instanceof Paiements) {
                if (\array_key_exists('statut', $uow->getEntityChangeSet($e))) {
                    $this->paiements[spl_object_id($e)] = $e;
                }
            } elseif ($e instanceof Commandes) {
                if (\array_key_exists('deleted', $uow->getEntityChangeSet($e))) {
                    $this->commandes[spl_object_id($e)] = $e;
                }
            } elseif ($e instanceof CommandesDetails) {
                $statut = $uow->getEntityChangeSet($e)['statutProduction'] ?? null;
                if (null !== $statut && \in_array(CommandesDetails::PRODUCTION_ANNULEE, [$statut[0], $statut[1]], true)) {
                    $this->lignes[spl_object_id($e)] = $e;
                    if (null !== $e->getCommande()) {
                        $this->commandes[spl_object_id($e->getCommande())] = $e->getCommande();
                    }
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->enCours || ([] === $this->paiements && [] === $this->commandes && [] === $this->lignes)) {
            return;
        }
        $paiements = $this->paiements;
        $commandes = $this->commandes;
        $lignes = $this->lignes;
        $this->paiements = $this->commandes = $this->lignes = [];

        $em = $args->getObjectManager();
        $le = new \DateTimeImmutable();
        /** @var array<int, Clients> $clients */
        $clients = [];
        $this->enCours = true;

        try {
            // La commande d'abord (annulée ou rétablie en entier), puis
            // ses paiements, puis les articles annulés ou rétablis seuls.
            foreach ($commandes as $c) {
                if ($this->fidelite->traiterCommande($c, $le)) {
                    $clients[spl_object_id($c->getClients())] = $c->getClients();
                    $em->flush();
                }
            }
            foreach ($paiements as $p) {
                if ($this->fidelite->traiterPaiement($p, $le)) {
                    $client = $p->getCommande()->getClients();
                    $clients[spl_object_id($client)] = $client;
                    $em->flush();
                }
            }
            foreach ($lignes as $l) {
                if ($this->fidelite->traiterLigne($l, $le)) {
                    $client = $l->getCommande()->getClients();
                    $clients[spl_object_id($client)] = $client;
                    $em->flush();
                }
            }
            foreach ($clients as $client) {
                $this->fidelite->actualiser($client, $le);
            }
            if ([] !== $clients) {
                $em->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Fidélité : relevé non mis à jour ({message}). Lancer app:fidelite --rattraper.', ['message' => $e->getMessage(), 'exception' => $e]);
        } finally {
            $this->enCours = false;
        }
    }
}
