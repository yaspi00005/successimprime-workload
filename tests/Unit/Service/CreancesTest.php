<?php

namespace App\Tests\Unit\Service;

use App\Entity\Commandes;
use App\Service\Creances;
use PHPUnit\Framework\TestCase;

final class CreancesTest extends TestCase
{
    public function testTranches(): void
    {
        self::assertSame('0_30', Creances::tranche(-5));
        self::assertSame('0_30', Creances::tranche(30));
        self::assertSame('31_60', Creances::tranche(31));
        self::assertSame('61_90', Creances::tranche(90));
        self::assertSame('plus_90', Creances::tranche(91));
    }

    public function testStatut(): void
    {
        self::assertSame('payee', Creances::statut(0, 40)['cle']);
        self::assertSame('a_echoir', Creances::statut(1000, 0)['cle']);
        self::assertSame('Échue 10 j', Creances::statut(1000, 10)['libelle']);
        self::assertSame('retard', Creances::statut(1000, 31)['cle']);
    }

    public function testEcheanceParDefautLaLivraison(): void
    {
        $commande = (new Commandes())->setDateLivraison(new \DateTimeImmutable('2026-10-20'));
        self::assertSame('2026-10-20', Creances::echeance(null, $commande)?->format('Y-m-d'));
    }

    public function testMessageGroupeParClient(): void
    {
        $message = Creances::messageRelance('Awa Mode', [
            ['document' => 'facture F-0098', 'reste' => 45000, 'echeance' => new \DateTimeImmutable('2026-10-15')],
            ['document' => 'commande CMD-1', 'reste' => 5000, 'echeance' => null],
        ]);
        self::assertStringContainsString('Bonjour Awa Mode', $message);
        self::assertStringContainsString('- facture F-0098 : 45 000 F (échéance du 15/10/2026)', $message);
        self::assertStringContainsString('Total : 50 000 F', $message);
    }
}
