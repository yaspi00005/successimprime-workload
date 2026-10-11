<?php

namespace App\Tests\Unit\Entity;

use App\Entity\BulletinPaie;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class BulletinPaieTest extends TestCase
{
    private function bulletin(int $base = 150000): BulletinPaie
    {
        return new BulletinPaie(new User(), new \DateTimeImmutable('2026-10-17 14:00'), $base, new \DateTimeImmutable());
    }

    public function testMoisRameneAuPremierJour(): void
    {
        self::assertSame('2026-10-01 00:00', $this->bulletin()->getMois()->format('Y-m-d H:i'));
    }

    public function testLeSensSuitLeTypeDeLigne(): void
    {
        $b = $this->bulletin();
        self::assertNull($b->ajouterLigne('prime', '', 25000));
        self::assertNull($b->ajouterLigne('absence', '1 jour', 5000));
        self::assertNull($b->ajouterLigne('its', '', -3000));
        self::assertNull($b->ajouterLigne('autre', 'Régularisation', -1000));
        self::assertSame(['Prime', 25000], [$b->getLignes()[0]['libelle'], $b->getLignes()[0]['montant']]);
        self::assertSame(-5000, $b->getLignes()[1]['montant']);
        self::assertSame(-3000, $b->getLignes()[2]['montant']);
        self::assertSame(175000, $b->brut());
        self::assertSame(9000, $b->retenues());
        self::assertSame(166000, $b->net());
        self::assertSame('Indiquez un montant.', $b->ajouterLigne('prime', '', 0));
        self::assertSame('Type de ligne inconnu.', $b->ajouterLigne('cadeau', '', 10));
    }

    public function testValideNeChangePlusEtSeRouvreAvantPaiement(): void
    {
        $b = $this->bulletin();
        $b->ajouterLigne('prime', '', 10000);
        self::assertNull($b->valider(new User(), new \DateTimeImmutable()));
        self::assertSame('Ce bulletin est validé : il ne change plus.', $b->ajouterLigne('prime', '', 1));
        $b->retirerLigne(0);
        $b->setSalaireBase(1);
        self::assertSame(160000, $b->net());
        self::assertNull($b->rouvrir());
        self::assertTrue($b->modifiable());
        $b->retirerLigne(0);
        self::assertSame(150000, $b->net());
        $b->valider(new User(), new \DateTimeImmutable());
        $b->payer(new MouvementTresorerie());
        self::assertSame('Ce bulletin est payé : il ne change plus.', $b->rouvrir());
    }

    public function testNetNulRefuse(): void
    {
        self::assertSame('Le net à payer est nul : vérifiez le salaire de base.', $this->bulletin(0)->valider(new User(), new \DateTimeImmutable()));
    }
}
