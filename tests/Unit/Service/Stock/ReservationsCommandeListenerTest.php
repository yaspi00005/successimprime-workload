<?php

namespace App\Tests\Unit\Service\Stock;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\StockReservation;
use App\Service\Stock\ReservationsCommande;
use App\Service\Stock\ReservationsCommandeListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Un seul point d'entrée pour tous les chemins de commande : le listener
 * repère à l'enregistrement les commandes dont le stock doit être revu.
 */
final class ReservationsCommandeListenerTest extends TestCase
{
    /** @var list<Commandes> commandes passées à la synchronisation */
    private array $synchronisees = [];

    public function testNouvelleCommandeValideeEstSynchronisee(): void
    {
        $commande = self::commande(true);
        $this->flush(insertions: [$commande]);

        self::assertSame([$commande], $this->synchronisees);
    }

    public function testNouveauBrouillonNEstPasRevuMaisSesLignesLeSont(): void
    {
        $brouillon = self::commande(false);
        $this->flush(insertions: [$brouillon]);
        self::assertSame([], $this->synchronisees);

        // Une ligne ajoutée (espace client, Mes produits, devis converti) : la commande est revue.
        $ligne = (new CommandesDetails())->setCommande($brouillon);
        $this->flush(insertions: [$ligne]);
        self::assertSame([$brouillon], $this->synchronisees);
    }

    public function testValidationCorbeilleEtRefusSontSuivis(): void
    {
        foreach (['statut', 'deleted', 'validationEntreprise'] as $champ) {
            $this->synchronisees = [];
            $commande = self::commande(true);
            $this->flush(misesAJour: [[$commande, [$champ => [false, true]]]]);
            self::assertSame([$commande], $this->synchronisees, $champ);
        }
    }

    public function testChangementsDeLigneQuiComptent(): void
    {
        foreach (['quantite', 'surface', 'longueur', 'produit', 'article', 'typeLigne', 'statutProduction'] as $champ) {
            $this->synchronisees = [];
            $commande = self::commande(true);
            $this->flush(misesAJour: [[(new CommandesDetails())->setCommande($commande), [$champ => [1, 2]]]]);
            self::assertSame([$commande], $this->synchronisees, $champ);
        }
    }

    public function testChangementSansEffetSurLeStockIgnore(): void
    {
        $commande = self::commande(true);
        $this->flush(misesAJour: [
            [$commande, ['observation' => ['a', 'b']]],
            [(new CommandesDetails())->setCommande($commande), ['designation' => ['a', 'b']]],
            [new StockReservation(), ['statut' => ['active', 'liberee']]],
        ]);

        self::assertSame([], $this->synchronisees);
    }

    public function testSoldeOuvertureIgnore(): void
    {
        $commande = self::commande(true);
        (new \ReflectionProperty($commande, 'soldeOuverture'))->setValue($commande, true);
        $this->flush(insertions: [$commande]);

        self::assertSame([], $this->synchronisees);
    }

    public function testSiteEnLigneNeTouchePasAuStock(): void
    {
        $this->flush(insertions: [self::commande(true)], enLigne: true);

        self::assertSame([], $this->synchronisees);
    }

    public function testUneErreurDeStockNeFaitPasEchouerLEnregistrement(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $listener = new ReservationsCommandeListener(static fn () => throw new \RuntimeException('base indisponible'), $logger);

        [$em] = $this->em([self::commande(true)], []);
        $listener->onFlush(new OnFlushEventArgs($em));
        $listener->postFlush(new PostFlushEventArgs($em));
    }

    /**
     * @param list<object>                                $insertions
     * @param list<array{0: object, 1: array<string, array{0: mixed, 1: mixed}>}> $misesAJour
     */
    private function flush(array $insertions = [], array $misesAJour = [], bool $enLigne = false): void
    {
        // La fabrique de ReservationsCommande note la commande puis échoue : rien d'autre n'est appelé.
        $listener = new ReservationsCommandeListener(
            fn () => throw new SynchronisationNotee(),
            $this->createStub(LoggerInterface::class),
            $enLigne,
        );
        [$em] = $this->em($insertions, $misesAJour);
        $listener->onFlush(new OnFlushEventArgs($em));

        $proprietes = (new \ReflectionProperty($listener, 'aSynchroniser'))->getValue($listener);
        $this->synchronisees = array_values($proprietes);
        $listener->postFlush(new PostFlushEventArgs($em));
        self::assertSame([], (new \ReflectionProperty($listener, 'aSynchroniser'))->getValue($listener));
    }

    /**
     * @param list<object> $insertions
     * @param list<array{0: object, 1: array<string, mixed>}> $misesAJour
     *
     * @return array{0: EntityManagerInterface}
     */
    private function em(array $insertions, array $misesAJour): array
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn(array_map(static fn (array $m): object => $m[0], $misesAJour));
        $uow->method('getEntityChangeSet')->willReturnCallback(static function (object $e) use ($misesAJour): array {
            foreach ($misesAJour as [$entite, $changements]) {
                if ($entite === $e) {
                    return $changements;
                }
            }

            return [];
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('contains')->willReturn(true);

        return [$em];
    }

    private static function commande(bool $validee): Commandes
    {
        $c = (new Commandes())->setStatut($validee);
        (new \ReflectionProperty($c, 'id'))->setValue($c, 41);

        return $c;
    }
}

final class SynchronisationNotee extends \RuntimeException
{
}
