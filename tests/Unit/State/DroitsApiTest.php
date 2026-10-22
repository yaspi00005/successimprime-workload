<?php

namespace App\Tests\Unit\State;

use ApiPlatform\State\Pagination\Pagination;
use App\ApiResource\Client;
use App\ApiResource\Commande;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\State\ClientProvider;
use App\State\CommandeProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Les montants payés et le crédit ne sortent de l'API qu'avec le droit
 * « Paiements » ; le champ reste présent, à null.
 */
final class DroitsApiTest extends TestCase
{
    public function testPaiementsCachesSansLeDroit(): void
    {
        $commande = $this->commande();

        $sans = $this->convertir(new CommandeProvider(new Pagination(), $this->createStub(EntityManagerInterface::class), $this->securite(false)), $commande);
        self::assertInstanceOf(Commande::class, $sans);
        self::assertSame(25000, $sans->totalTtc);
        self::assertNull($sans->totalPaye);
        self::assertNull($sans->resteAPayer);
        self::assertNull($sans->statutPaiement);
        self::assertSame('Awa Diarra', $sans->client?->nom);

        $avec = $this->convertir(new CommandeProvider(new Pagination(), $this->createStub(EntityManagerInterface::class), $this->securite(true)), $commande);
        self::assertSame(25000, $avec->resteAPayer);
    }

    public function testCreditDuClientCacheSansLeDroit(): void
    {
        $client = $this->client()->setPlafondCredit(100000);

        $sans = $this->convertir(new ClientProvider(new Pagination(), $this->createStub(EntityManagerInterface::class), $this->securite(false)), $client);
        self::assertInstanceOf(Client::class, $sans);
        self::assertNull($sans->plafondCredit);
        self::assertNull($sans->soldeCredit);
        self::assertSame('Awa Diarra', $sans->nom);

        $avec = $this->convertir(new ClientProvider(new Pagination(), $this->createStub(EntityManagerInterface::class), $this->securite(true)), $client);
        self::assertSame(100000, $avec->plafondCredit);
    }

    private function convertir(object $provider, object $entite): object
    {
        return (new \ReflectionMethod($provider, 'convertir'))->invoke($provider, $entite);
    }

    private function securite(bool $paiements): Security
    {
        $securite = $this->createStub(Security::class);
        $securite->method('isGranted')->willReturnCallback(static fn (string $role): bool => 'ROLE_PAIEMENT_VOIR' === $role && $paiements);

        return $securite;
    }

    private function client(): Clients
    {
        $client = (new Clients())->setNom('Diarra')->setPrenom('Awa');
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($client, 7);

        return $client;
    }

    private function commande(): Commandes
    {
        $commande = (new Commandes())->setClients($this->client())->setNumero('CMD-000001-10-2026');
        (new \ReflectionProperty(Commandes::class, 'id'))->setValue($commande, 12);
        (new \ReflectionProperty(Commandes::class, 'totalTtc'))->setValue($commande, 25000);
        (new \ReflectionProperty(Commandes::class, 'montantApayer'))->setValue($commande, 25000);

        return $commande;
    }
}
