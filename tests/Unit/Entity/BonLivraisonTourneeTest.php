<?php

namespace App\Tests\Unit\Entity;

use App\Entity\BonLivraison;
use PHPUnit\Framework\TestCase;

final class BonLivraisonTourneeTest extends TestCase
{
    private function bon(string $statut): BonLivraison
    {
        $b = new BonLivraison();
        (new \ReflectionProperty(BonLivraison::class, 'statut'))->setValue($b, $statut);

        return $b;
    }

    public function testLesEtapesManqueesPrennentLaMemeHeure(): void
    {
        $b = $this->bon(BonLivraison::STATUT_VALIDE);
        $le = new \DateTimeImmutable('2026-10-16 14:05');
        $b->avancer('arrive', $le);
        self::assertSame([$le, $le, $le], [$b->getChargeLe(), $b->getEnRouteLe(), $b->getArriveLe()]);
        self::assertSame('arrive', $b->etape());
        $plusTard = $le->modify('+1 hour');
        $b->avancer('charge', $plusTard);
        self::assertSame($le, $b->getChargeLe());
    }

    public function testOnNAvancePasUnBonNonValide(): void
    {
        $this->expectException(\LogicException::class);
        $this->bon(BonLivraison::STATUT_BROUILLON)->avancer('charge', new \DateTimeImmutable());
    }

    public function testEncaissementProvisoireEtCredit(): void
    {
        $b = $this->bon(BonLivraison::STATUT_VALIDE);
        $b->noterEncaissement(116500, 'orange_money', new \DateTimeImmutable());
        self::assertTrue($b->encaissementProvisoire());
        $b->noterEncaissement(5000, 'credit', new \DateTimeImmutable());
        self::assertNull($b->getEncaisseMontant());
        self::assertFalse($b->encaissementProvisoire());
        $this->expectException(\InvalidArgumentException::class);
        $b->noterEncaissement(1, 'cheque', new \DateTimeImmutable());
    }
}
