<?php

namespace App\Tests\Unit\Service;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\BonusPlafondClientService;
use PHPUnit\Framework\TestCase;

final class BonusPlafondClientServiceTest extends TestCase
{
    private BonusPlafondClientService $service;

    protected function setUp(): void
    {
        $this->service = new BonusPlafondClientService();
    }

    private function commande(int $totalTtc, ?Clients $client): Commandes
    {
        return (new Commandes())
            ->setTotalTtc($totalTtc)
            ->setClients($client);
    }

    public function testAjouteUnPourcentDuTtcAuPlafond(): void
    {
        $client = (new Clients())->setPlafondCredit(100000);
        $commande = $this->commande(250000, $client);

        self::assertSame(2500, $this->service->appliquer($commande));
        self::assertSame(102500, $client->getPlafondCredit());
        self::assertTrue($commande->isBonusPlafondApplique());
        self::assertSame(2500, $commande->getMontantBonusPlafond());
    }

    public function testNeSAppliqueQuUneFois(): void
    {
        $client = (new Clients())->setPlafondCredit(0);
        $commande = $this->commande(50000, $client);

        self::assertSame(500, $this->service->appliquer($commande));
        self::assertSame(0, $this->service->appliquer($commande));
        self::assertSame(500, $client->getPlafondCredit());
    }

    public function testArrondiAuFrancLePlusProche(): void
    {
        $client = (new Clients())->setPlafondCredit(0);

        self::assertSame(1, $this->service->appliquer($this->commande(140, $client)));
        self::assertSame(2, $this->service->appliquer($this->commande(150, (new Clients())->setPlafondCredit(0))));
        self::assertSame(0, $this->service->appliquer($this->commande(49, (new Clients())->setPlafondCredit(0))));
    }

    public function testSansClientOuMontantNulAucunBonus(): void
    {
        self::assertSame(0, $this->service->appliquer($this->commande(100000, null)));

        $client = (new Clients())->setPlafondCredit(5000);
        $commande = $this->commande(0, $client);

        self::assertSame(0, $this->service->appliquer($commande));
        self::assertSame(5000, $client->getPlafondCredit());
        self::assertFalse($commande->isBonusPlafondApplique());
    }

    public function testPlafondNegatifRemisAZeroAvantBonus(): void
    {
        $client = (new Clients())->setPlafondCredit(-3000);

        self::assertSame(1000, $this->service->appliquer($this->commande(100000, $client)));
        self::assertSame(1000, $client->getPlafondCredit());
    }
}
