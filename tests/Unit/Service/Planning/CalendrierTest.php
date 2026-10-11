<?php

namespace App\Tests\Unit\Service\Planning;

use App\Service\Planning\Calendrier;
use PHPUnit\Framework\TestCase;

final class CalendrierTest extends TestCase
{
    private static function dates(array $occurrences): array
    {
        return array_map(static fn (array $o): string => $o['date']->format('Y-m-d'), $occurrences);
    }

    public function testImpotsLe25DecalesAuJourOuvrePrecedent(): void
    {
        $c = new Calendrier();
        $regle = ['frequence' => 'mois', 'jourMois' => 25, 'debut' => '2026-01-25', 'decalage' => 'avant'];
        $o = $c->occurrences($regle, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-12-31'));
        self::assertSame(['2026-10-23', '2026-11-25', '2026-12-24'], self::dates($o));
        self::assertSame('le 25 est un dimanche', $o[0]['raison']);
        self::assertSame('2026-10-25', $o[0]['origine']->format('Y-m-d'));
        self::assertSame('férié : Noël', $o[2]['raison']);
        self::assertNull($o[1]['raison']);
    }

    public function testFournisseurLe1erEtReportOuGarde(): void
    {
        $c = new Calendrier();
        $regle = ['frequence' => 'mois', 'jourMois' => 1, 'debut' => '2026-01-01'];
        self::assertSame(['2026-10-30'], self::dates($c->occurrences($regle, new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-05'))), 'Le 1er novembre 2026 est un dimanche.');
        self::assertSame(['2026-11-02'], self::dates($c->occurrences($regle + ['decalage' => 'apres'], new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-05'))));
        self::assertSame(['2026-11-01'], self::dates($c->occurrences(['decalage' => 'garder'] + $regle, new \DateTimeImmutable('2026-10-26'), new \DateTimeImmutable('2026-11-05'))));
    }

    public function testDernierJourTrimestreEtFin(): void
    {
        $c = new Calendrier();
        $regle = ['frequence' => 'trimestre', 'jourMois' => 0, 'debut' => '2027-01-01', 'decalage' => 'garder', 'finNombre' => 3];
        self::assertSame(['2027-01-31', '2027-04-30', '2027-07-31'], self::dates($c->occurrences($regle, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2028-12-31'))));
        $regle = ['frequence' => 'mois', 'jourMois' => 31, 'debut' => '2027-01-01', 'decalage' => 'garder', 'finLe' => '2027-03-15'];
        self::assertSame(['2027-01-31', '2027-02-28'], self::dates($c->occurrences($regle, new \DateTimeImmutable('2027-01-01'), new \DateTimeImmutable('2027-12-31'))));
    }

    public function testJourOuvreEtSemaineEtFeteMobile(): void
    {
        $c = new Calendrier(['2027-03-10' => 'Fin du Ramadan']);
        $jour = ['frequence' => 'jour', 'debut' => '2027-03-05'];
        self::assertSame(['2027-03-05', '2027-03-08', '2027-03-09', '2027-03-11'], self::dates($c->occurrences($jour, new \DateTimeImmutable('2027-03-01'), new \DateTimeImmutable('2027-03-11'))));
        $semaine = ['frequence' => 'semaine', 'joursSemaine' => [1, 4], 'debut' => '2027-03-01'];
        self::assertSame(['2027-03-01', '2027-03-04', '2027-03-08', '2027-03-11'], self::dates($c->occurrences($semaine, new \DateTimeImmutable('2027-03-01'), new \DateTimeImmutable('2027-03-14'))));
        self::assertSame('Chaque lundi et jeudi', Calendrier::resume($semaine));
        $boutique = new Calendrier([], [1, 2, 3, 4, 5], [1, 2, 3, 4, 5, 6]);
        self::assertSame(['2026-10-09', '2026-10-10', '2026-10-12'], self::dates($boutique->occurrences(['frequence' => 'jour', 'debut' => '2026-10-09'], new \DateTimeImmutable('2026-10-09'), new \DateTimeImmutable('2026-10-12'))), 'Le samedi compte pour la clôture de caisse.');
        self::assertSame('2026-10-23', $boutique->decaler(new \DateTimeImmutable('2026-10-25'))['date']->format('Y-m-d'), 'Le décalage suit les jours des administrations.');
        self::assertSame('Le 25 de chaque mois', Calendrier::resume(['frequence' => 'mois', 'jourMois' => 25]));
    }

    public function testUneFois(): void
    {
        $c = new Calendrier();
        $regle = ['frequence' => 'une_fois', 'debut' => '2026-10-10'];
        self::assertSame(['2026-10-09'], self::dates($c->occurrences($regle, new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'))), 'Samedi : avancé au vendredi.');
        self::assertSame([], $c->occurrences($regle, new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-11-30')));
    }
}
