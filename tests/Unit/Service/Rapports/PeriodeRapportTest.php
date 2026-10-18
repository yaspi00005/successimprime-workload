<?php

namespace App\Tests\Unit\Service\Rapports;

use App\Service\Rapports\PeriodeRapport;
use PHPUnit\Framework\TestCase;

/**
 * Bornes des périodes des rapports par e-mail (docs/rapports-mail.md) :
 * début compris, fin exclue, à minuit ; semaines ISO du lundi au lundi.
 */
final class PeriodeRapportTest extends TestCase
{
    private static function d(string $texte): \DateTimeImmutable
    {
        return new \DateTimeImmutable($texte);
    }

    public function testJourDeMinuitAMinuit(): void
    {
        $p = PeriodeRapport::contenant(PeriodeRapport::JOUR, self::d('2026-10-17 23:59:59'));

        self::assertSame('2026-10-17 00:00:00', $p->debut->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-18 00:00:00', $p->fin->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-17', $p->code());
        self::assertSame('samedi 17 octobre 2026', $p->libelle());
        // Minuit pile appartient au jour qui commence.
        self::assertSame('2026-10-18', PeriodeRapport::contenant(PeriodeRapport::JOUR, self::d('2026-10-18 00:00:00'))->code());
    }

    public function testSemaineIsoDuLundiAuLundi(): void
    {
        $p = PeriodeRapport::contenant(PeriodeRapport::SEMAINE, self::d('2026-10-18 23:00'));

        self::assertSame('2026-10-12', $p->debut->format('Y-m-d'));
        self::assertSame('2026-10-19 00:00', $p->fin->format('Y-m-d H:i'));
        self::assertSame('2026-W42', $p->code());
        self::assertSame('2026-10-18', $p->dernierJour()->format('Y-m-d'));
        self::assertSame('semaine 42, du 12 au 18 octobre 2026', $p->libelle());
        // Le lundi à minuit commence la semaine suivante.
        self::assertSame('2026-W43', PeriodeRapport::contenant(PeriodeRapport::SEMAINE, self::d('2026-10-19 00:00'))->code());
    }

    public function testSemaineAChevalSurDeuxAnnees(): void
    {
        // 2026 a 53 semaines : le vendredi 1er janvier 2027 est encore dans la 53e.
        $p = PeriodeRapport::contenant(PeriodeRapport::SEMAINE, self::d('2027-01-01 10:00'));

        self::assertSame('2026-W53', $p->code());
        self::assertSame('2026-12-28', $p->debut->format('Y-m-d'));
        self::assertSame('2027-01-04', $p->fin->format('Y-m-d'));
        self::assertSame('2027-W01', $p->apres()->code());
        self::assertSame('2026-W52', $p->avant()->code());
    }

    public function testMoisDuPremierAuPremier(): void
    {
        $p = PeriodeRapport::contenant(PeriodeRapport::MOIS, self::d('2028-02-29 18:00'));

        self::assertSame('2028-02-01 00:00', $p->debut->format('Y-m-d H:i'));
        self::assertSame('2028-03-01 00:00', $p->fin->format('Y-m-d H:i'));
        self::assertSame('2028-02', $p->code());
        self::assertSame('février 2028', $p->libelle());
        self::assertSame('2028-01', $p->avant()->code());
        self::assertSame('2027-12', PeriodeRapport::contenant(PeriodeRapport::MOIS, self::d('2028-01-31'))->avant()->code());
    }

    public function testComparaison(): void
    {
        // Un samedi se compare au samedi d'avant, pas au vendredi.
        $samedi = PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17');
        self::assertSame('2026-10-10', $samedi->comparaison()->code());
        self::assertSame('samedi 10/10', $samedi->libelleComparaison());
        self::assertSame('2026-10-16', $samedi->avant()->code());

        self::assertSame('2026-W41', PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42')->comparaison()->code());
        self::assertSame('semaine 41', PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42')->libelleComparaison());
        self::assertSame('2026-09', PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10')->comparaison()->code());
        self::assertSame('septembre', PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10')->libelleComparaison());
    }

    public function testCodesLusEtRefuses(): void
    {
        foreach ([[PeriodeRapport::JOUR, '2026-10-17'], [PeriodeRapport::SEMAINE, '2026-W42'], [PeriodeRapport::MOIS, '2026-10']] as [$type, $code]) {
            self::assertSame($code, PeriodeRapport::lire($type, $code)?->code(), $code);
        }

        self::assertNull(PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-02-30'));
        self::assertNull(PeriodeRapport::lire(PeriodeRapport::JOUR, '17/10/2026'));
        self::assertNull(PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-13'));
        self::assertNull(PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10-01'));
        self::assertNull(PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-10'));
        self::assertNull(PeriodeRapport::lire('annee', '2026'));
        self::assertFalse(PeriodeRapport::estType('annee'));

        $this->expectException(\InvalidArgumentException::class);
        PeriodeRapport::contenant('annee', self::d('2026-10-17'));
    }

    public function testTermineeSeulementAMinuit(): void
    {
        $p = PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17');

        self::assertFalse($p->terminee(self::d('2026-10-17 20:00')));
        self::assertTrue($p->terminee(self::d('2026-10-18 00:00')));
    }

    public function testSujetSansChiffreDeVente(): void
    {
        self::assertSame('Rapport du jour · samedi 17 octobre 2026', PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17')->sujet());
        self::assertSame('Rapport de la semaine 42 · du 12 au 18 octobre 2026', PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42')->sujet());
        self::assertSame('Rapport du mois · octobre 2026', PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10')->sujet());
        self::assertSame('jeudi 1er octobre', PeriodeRapport::jourLong(self::d('2026-10-01'), false));
    }
}
