<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Clients;
use App\Entity\MouvementSoldeClient;
use App\Entity\Paiements;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Ligne du relevé du compte d'un client (docs/finances.md) : ce que le
 * client lit dans « Mon solde » et ce que garde la fiche.
 */
final class MouvementSoldeClientTest extends TestCase
{
    public function testLibellesDuReleve(): void
    {
        $client = new Clients();
        $le = new \DateTimeImmutable('2026-10-18 09:00');
        $ligne = static fn (string $type, int $montant): MouvementSoldeClient => MouvementSoldeClient::nouveau($client, $type, $montant, max(0, $montant), null, $le);

        self::assertSame('Monnaie gardée', $ligne(MouvementSoldeClient::MONNAIE, 2500)->getLibelle());
        self::assertSame('Dépôt en espèces', $ligne(MouvementSoldeClient::DEPOT, 20000)->setMode(Paiements::MODE_ESPECES)->getLibelle());
        self::assertSame('Dépôt Wave', $ligne(MouvementSoldeClient::DEPOT, 20000)->setMode(Paiements::MODE_WAVE)->getLibelle());
        self::assertSame('Argent rendu', $ligne(MouvementSoldeClient::REMBOURSEMENT, -5000)->getLibelle());
        self::assertSame('Solde d\'ouverture', $ligne(MouvementSoldeClient::OUVERTURE, 3000)->getLibelle());
        self::assertSame('Solde d\'ouverture annulé', $ligne(MouvementSoldeClient::OUVERTURE, -3000)->getLibelle());
        self::assertTrue($ligne(MouvementSoldeClient::MONNAIE, 2500)->estCredit());
        self::assertFalse($ligne(MouvementSoldeClient::UTILISATION, -2500)->estCredit());
    }

    public function testQuiEtQuoi(): void
    {
        $ligne = MouvementSoldeClient::nouveau(new Clients(), MouvementSoldeClient::DEPOT, 1000, 1000, (new User())->setUsername('demo.aminata'), new \DateTimeImmutable())
            ->setReference('  D-000012 ')
            ->setMotif('   ');

        self::assertSame('demo.aminata', $ligne->getParNom(), 'gardé si le compte de l\'employé disparaît');
        self::assertSame('D-000012', $ligne->getReference());
        self::assertNull($ligne->getMotif());
        self::assertSame('import', MouvementSoldeClient::nouveau(new Clients(), MouvementSoldeClient::OUVERTURE, 1000, 1000, null, new \DateTimeImmutable(), 'import')->getParNom());
    }

    public function testTypeInconnuRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MouvementSoldeClient::nouveau(new Clients(), 'cadeau', 1000, 1000, null, new \DateTimeImmutable());
    }
}
