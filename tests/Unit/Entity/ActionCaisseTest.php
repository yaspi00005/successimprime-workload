<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ActionCaisse;
use PHPUnit\Framework\TestCase;

final class ActionCaisseTest extends TestCase
{
    private static function ligne(): ActionCaisse
    {
        return new ActionCaisse('facture', 'facture:1', 'Hôtel Niger', new \DateTimeImmutable('2026-10-10 17:00'), new \DateTimeImmutable('2026-10-12 11:00'));
    }

    public function testFaiteDansLeDelai(): void
    {
        $a = self::ligne();
        $a->faire('promesse', null, null, new \DateTimeImmutable('2026-10-12 10:29'), 10);

        self::assertSame(10, $a->total());
        self::assertSame('résultat seul', $a->preuve());
    }

    public function testPenaliteUneSeuleFoisEtLigneResteAFaire(): void
    {
        $a = self::ligne();
        $apres = new \DateTimeImmutable('2026-10-12 11:30');

        self::assertTrue($a->estEnRetard($apres));
        self::assertTrue($a->penaliser(15));
        self::assertFalse($a->penaliser(15));
        self::assertSame(ActionCaisse::A_FAIRE, $a->getEtat());

        $a->noterContact('appel', $apres);
        $a->faire('paye', null, null, $apres, 10);
        self::assertSame(-15, $a->total(), 'faite après le délai : pas de +10');
        self::assertSame('appel + résultat', $a->preuve());
    }

    public function testControleAnnuleLesPointsGagnesPasLaPenalite(): void
    {
        $a = self::ligne();
        $a->faire('fait', 'note', null, new \DateTimeImmutable('2026-10-12 09:00'), 10);
        $a->controler(new \App\Entity\User(), new \DateTimeImmutable(), true, 'Client jamais appelé');

        self::assertSame(0, $a->total());
    }
}
