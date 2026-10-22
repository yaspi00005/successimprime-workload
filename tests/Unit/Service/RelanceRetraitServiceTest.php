<?php

namespace App\Tests\Unit\Service;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\RelanceRetraitService;
use PHPUnit\Framework\TestCase;

final class RelanceRetraitServiceTest extends TestCase
{
    public function testRelanceDueAJPlusNPuisTousLesNJours(): void
    {
        self::assertFalse(RelanceRetraitService::estDue(2, 3));
        self::assertTrue(RelanceRetraitService::estDue(3, 3));
        self::assertFalse(RelanceRetraitService::estDue(4, 3));
        self::assertTrue(RelanceRetraitService::estDue(9, 3));
        self::assertFalse(RelanceRetraitService::estDue(5, 0));
    }

    public function testMessageSansMontantQuandLaCommandeEstSoldee(): void
    {
        $commande = $this->createStub(Commandes::class);
        $client = $this->createStub(Clients::class);
        $client->method('getNomComplet')->willReturn('Aminata Keïta');
        $commande->method('getClients')->willReturn($client);
        $commande->method('getNumero')->willReturn('C-0074');
        $commande->method('getResteAPayer')->willReturn(0);

        $message = RelanceRetraitService::message($commande, new \DateTimeImmutable('2026-10-07'));

        self::assertStringContainsString('Aminata Keïta', $message);
        self::assertStringContainsString('C-0074 est prête chez Succès Imprim depuis le 07/10', $message);
        self::assertStringNotContainsString('Reste à payer', $message);
    }
}
