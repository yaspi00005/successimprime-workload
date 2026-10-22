<?php

namespace App\Tests\Unit\Service\Clients;

use App\Entity\Clients;
use App\Service\Clients\CalculsFicheClient;
use PHPUnit\Framework\TestCase;

final class CalculsFicheClientTest extends TestCase
{
    public function testPanierMoyen(): void
    {
        self::assertSame(0, CalculsFicheClient::panierMoyen([]));
        self::assertSame(25000, CalculsFicheClient::panierMoyen([20000, 30000]));
        // Arrondi au franc inférieur.
        self::assertSame(33333, CalculsFicheClient::panierMoyen([10000, 40000, 50000]));
    }

    public function testEvolution(): void
    {
        self::assertSame(25, CalculsFicheClient::evolution(125000, 100000));
        self::assertSame(-50, CalculsFicheClient::evolution(50000, 100000));
        self::assertNull(CalculsFicheClient::evolution(50000, 0));
    }

    public function testPlafondSansPlafond(): void
    {
        $p = CalculsFicheClient::plafondUtilise(40000, null);
        self::assertSame('aucun', $p['niveau']);
        self::assertNull($p['pourcentage']);
        self::assertSame(0, CalculsFicheClient::plafondUtilise(40000, 0)['plafond']);
    }

    public function testPlafondUtilise(): void
    {
        $p = CalculsFicheClient::plafondUtilise(30000, 100000);
        self::assertSame(30, $p['pourcentage']);
        self::assertSame(30, $p['jauge']);
        self::assertSame(70000, $p['disponible']);
        self::assertSame(0, $p['depassement']);
        self::assertSame('ok', $p['niveau']);

        self::assertSame('attention', CalculsFicheClient::plafondUtilise(60000, 100000)['niveau']);
        self::assertSame('alerte', CalculsFicheClient::plafondUtilise(90000, 100000)['niveau']);
        // Un solde négatif (avance du client) compte pour zéro.
        self::assertSame(0, CalculsFicheClient::plafondUtilise(-5000, 100000)['encours']);
    }

    public function testPlafondDepasse(): void
    {
        $p = CalculsFicheClient::plafondUtilise(130000, 100000);
        self::assertSame(130, $p['pourcentage']);
        self::assertSame(100, $p['jauge']);
        self::assertSame(0, $p['disponible']);
        self::assertSame(30000, $p['depassement']);
        self::assertSame('alerte', $p['niveau']);
    }

    public function testCaParMois(): void
    {
        $mois = CalculsFicheClient::caParMois([
            ['date' => new \DateTimeImmutable('2026-01-10'), 'montant' => 10000],
            ['date' => new \DateTimeImmutable('2026-01-25'), 'montant' => 5000],
            ['date' => new \DateTimeImmutable('2026-10-01'), 'montant' => 7000],
            ['date' => new \DateTimeImmutable('2025-01-10'), 'montant' => 99999],
        ], 2026);
        self::assertCount(12, $mois);
        self::assertSame(15000, $mois[1]);
        self::assertSame(7000, $mois[10]);
        self::assertSame(0, $mois[12]);
        self::assertSame(22000, array_sum($mois));
    }

    public function testDelaiPaiementMoyen(): void
    {
        self::assertNull(CalculsFicheClient::delaiPaiementMoyen([]));
        self::assertSame(15, CalculsFicheClient::delaiPaiementMoyen([
            ['debut' => new \DateTimeImmutable('2026-09-01 17:00'), 'solde' => new \DateTimeImmutable('2026-09-11 08:00')],
            ['debut' => new \DateTimeImmutable('2026-09-01'), 'solde' => new \DateTimeImmutable('2026-09-21')],
        ]));
        // Payé avant la facture : 0 jour, pas un délai négatif.
        self::assertSame(0, CalculsFicheClient::delaiPaiementMoyen([
            ['debut' => new \DateTimeImmutable('2026-09-10'), 'solde' => new \DateTimeImmutable('2026-09-05')],
        ]));
    }

    public function testDateSolde(): void
    {
        $paiements = [
            ['date' => new \DateTimeImmutable('2026-09-20'), 'montant' => 30000],
            ['date' => new \DateTimeImmutable('2026-09-05'), 'montant' => 20000],
            ['date' => new \DateTimeImmutable('2026-09-30'), 'montant' => 10000],
        ];
        self::assertSame('2026-09-20', CalculsFicheClient::dateSolde(50000, $paiements)?->format('Y-m-d'));
        self::assertNull(CalculsFicheClient::dateSolde(70000, $paiements));
        self::assertNull(CalculsFicheClient::dateSolde(0, $paiements));
    }

    public function testActiviteParSemaine(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-18 10:00');
        $semaines = CalculsFicheClient::activiteParSemaine([
            new \DateTimeImmutable('2026-10-18 08:00'),
            new \DateTimeImmutable('2026-10-15'),
            new \DateTimeImmutable('2026-10-08'),
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2026-10-25'),
        ], $maintenant);
        self::assertCount(52, $semaines);
        self::assertSame(2, $semaines[51]);
        self::assertSame(1, $semaines[50]);
        self::assertSame(3, array_sum($semaines));
    }

    public function testIntensite(): void
    {
        self::assertSame(0, CalculsFicheClient::intensite(0, 8));
        self::assertSame(0, CalculsFicheClient::intensite(3, 0));
        self::assertSame(1, CalculsFicheClient::intensite(1, 8));
        self::assertSame(2, CalculsFicheClient::intensite(4, 8));
        self::assertSame(4, CalculsFicheClient::intensite(8, 8));
    }

    public function testSatisfaction(): void
    {
        self::assertSame(['moyenne' => null, 'avis' => 0], CalculsFicheClient::satisfaction([]));
        self::assertSame(['moyenne' => 4.3, 'avis' => 3], CalculsFicheClient::satisfaction([5, 4, null, 4, 9]));
    }

    public function testJoursAccordes(): void
    {
        self::assertNull(CalculsFicheClient::joursAccordes(null));
        self::assertSame(0, CalculsFicheClient::joursAccordes('comptant'));
        self::assertSame(30, CalculsFicheClient::joursAccordes('30_jours_fdm'));
        self::assertNull(CalculsFicheClient::joursAccordes('inconnu'));
        // Chaque délai de la fiche a ses jours.
        foreach (array_keys(Clients::DELAIS_PAIEMENT) as $cle) {
            self::assertNotNull(CalculsFicheClient::joursAccordes($cle), $cle);
        }
    }

    public function testIlYA(): void
    {
        self::assertNull(CalculsFicheClient::ilYA(null));
        self::assertSame('aujourd\'hui', CalculsFicheClient::ilYA(0));
        self::assertSame('hier', CalculsFicheClient::ilYA(1));
        self::assertSame('il y a 12 jours', CalculsFicheClient::ilYA(12));
    }

    public function testCanauxDesFactures(): void
    {
        $c = new Clients();
        $c->setFacturesCanaux(['email', 'fax', 'whatsapp', 'email']);
        self::assertSame(['whatsapp', 'email'], $c->getFacturesCanaux());
        self::assertSame('WhatsApp + e-mail', $c->getFacturesCanauxLabel());
        $c->setCharteCouleurs(['7a1f5c', '#FFF', 'rouge', '#1e1e1e']);
        self::assertSame(['#7A1F5C', '#FFFFFF', '#1E1E1E'], $c->getCharteCouleurs());
    }
}
