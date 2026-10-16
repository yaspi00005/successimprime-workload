<?php

namespace App\Tests\Unit\Service;

use App\Entity\Paiements;
use App\Service\OperationsAValider;
use PHPUnit\Framework\TestCase;

final class OperationsAValiderTest extends TestCase
{
    public function testMontantFaceAuResteDu(): void
    {
        self::assertSame('Montant = reste dû', OperationsAValider::controlesPaiement(45000, 45000, Paiements::MODE_ESPECES, null, false)[0]['libelle']);
        self::assertSame(OperationsAValider::OK, OperationsAValider::controlesPaiement(20000, 45000, Paiements::MODE_ESPECES, null, false)[0]['etat']);
        self::assertSame(OperationsAValider::ERREUR, OperationsAValider::controlesPaiement(50000, 45000, Paiements::MODE_ESPECES, null, false)[0]['etat']);
    }

    public function testReferenceDesPaiementsHorsEspeces(): void
    {
        self::assertCount(1, OperationsAValider::controlesPaiement(1000, 1000, Paiements::MODE_ESPECES, null, false));
        self::assertSame('Référence manquante', OperationsAValider::controlesPaiement(1000, 1000, Paiements::MODE_CHEQUE, null, false)[1]['libelle']);
        self::assertSame('Référence déjà utilisée', OperationsAValider::controlesPaiement(1000, 1000, Paiements::MODE_VIREMENT, 'VIR1', true)[1]['libelle']);
        self::assertSame(OperationsAValider::OK, OperationsAValider::controlesPaiement(1000, null, Paiements::MODE_VIREMENT, 'VIR1', false)[0]['etat']);
    }

    public function testAvoirUtiliseEnLigneCouvertParLeSolde(): void
    {
        self::assertSame([['libelle' => 'Couvert par le solde du client', 'etat' => OperationsAValider::OK]], OperationsAValider::controleAvoir(5000, 5000));
        self::assertSame(OperationsAValider::ERREUR, OperationsAValider::controleAvoir(5000, 4999)[0]['etat']);
        // Plusieurs paiements d'un même règlement : chacun sa référence, aucun « déjà utilisée ».
        self::assertNotSame(OperationsAValider::normaliserReference('OM MP261015.1015.A1 1/2'), OperationsAValider::normaliserReference('OM MP261015.1015.A1 2/2'));
    }

    public function testReferenceNormalisee(): void
    {
        self::assertSame('VIR7781', OperationsAValider::normaliserReference(' vir 7781 '));
        self::assertNull(OperationsAValider::normaliserReference('  '));
    }

    public function testDelai(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-11 12:00');
        self::assertSame([], OperationsAValider::controleDelai(new \DateTimeImmutable('2026-10-10 13:00'), $maintenant));
        self::assertSame('Attend depuis 30 h', OperationsAValider::controleDelai(new \DateTimeImmutable('2026-10-10 06:00'), $maintenant)[0]['libelle']);
        self::assertSame('Attend depuis 3 j', OperationsAValider::controleDelai(new \DateTimeImmutable('2026-10-08 10:00'), $maintenant)[0]['libelle']);
    }
}
