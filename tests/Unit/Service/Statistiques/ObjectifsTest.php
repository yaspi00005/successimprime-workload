<?php

namespace App\Tests\Unit\Service\Statistiques;

use App\Service\Statistiques\DroitsStatistiques;
use App\Service\Statistiques\Objectifs;
use PHPUnit\Framework\TestCase;

final class ObjectifsTest extends TestCase
{
    private const DEBUT = '2026-10-01 00:00';
    private const FIN = '2026-11-01 00:00';

    /** Avancement au 11 octobre à 0 h : 10 jours écoulés sur 31. */
    private static function auOnze(float $realise, ?int $objectif, bool $cumul = true): array
    {
        return Objectifs::avancement($realise, $objectif, new \DateTimeImmutable(self::DEBUT), new \DateTimeImmutable(self::FIN), new \DateTimeImmutable('2026-10-11 00:00'), $cumul);
    }

    public function testProjectionAuRythmeActuel(): void
    {
        $a = self::auOnze(3000000, 9000000);

        self::assertSame(33, $a['part']);
        self::assertSame(32, $a['ecoule']);
        // 3 M en 10 jours sur 31 : 9,3 M en fin de mois.
        self::assertEqualsWithDelta(9300000, $a['projection'], 1);
        self::assertSame(103, $a['part_projetee']);
        self::assertEqualsWithDelta(2903225.8, $a['attendu'], 0.1);
        self::assertSame('en_bonne_voie', $a['etat']);
    }

    public function testEtatsSelonLaProjection(): void
    {
        self::assertSame('a_surveiller', self::auOnze(2700000, 9000000)['etat']);
        self::assertSame('en_retard', self::auOnze(1000000, 9000000)['etat']);
        self::assertSame('atteint', self::auOnze(9000000, 9000000)['etat']);
        self::assertSame('sans_objectif', self::auOnze(1000000, null)['etat']);
        // 0 vaut « pas d'objectif ».
        $sans = self::auOnze(1000000, 0);
        self::assertSame('sans_objectif', $sans['etat']);
        self::assertNull($sans['objectif']);
        self::assertNull($sans['part']);
        self::assertNull($sans['attendu']);
    }

    public function testMoisTermine(): void
    {
        $a = Objectifs::avancement(8000000, 9000000, new \DateTimeImmutable(self::DEBUT), new \DateTimeImmutable(self::FIN), new \DateTimeImmutable('2026-11-03 10:00'));

        self::assertSame('manque', $a['etat']);
        self::assertSame(100, $a['ecoule']);
        self::assertEqualsWithDelta(8000000, $a['projection'], 0.1);
        self::assertSame(89, $a['part_projetee']);
    }

    public function testPasDeProjectionLePremierJour(): void
    {
        $a = Objectifs::avancement(500000, 9000000, new \DateTimeImmutable(self::DEBUT), new \DateTimeImmutable(self::FIN), new \DateTimeImmutable('2026-10-01 10:00'));

        self::assertNull($a['projection']);
        self::assertNull($a['part_projetee']);
        self::assertSame('a_venir', $a['etat']);

        $avant = Objectifs::avancement(0, 9000000, new \DateTimeImmutable(self::DEBUT), new \DateTimeImmutable(self::FIN), new \DateTimeImmutable('2026-09-20'));
        self::assertSame(0, $avant['ecoule']);
        self::assertSame('a_venir', $avant['etat']);
    }

    public function testTauxNonCumule(): void
    {
        // Un taux ne se projette pas : il se compare tel quel à l'objectif.
        $a = self::auOnze(76, 80, false);

        self::assertEqualsWithDelta(76, $a['projection'], 0.01);
        self::assertSame(95, $a['part_projetee']);
        self::assertEqualsWithDelta(80, $a['attendu'], 0.01);
        self::assertSame('a_surveiller', $a['etat']);
    }

    public function testProrataDUneSemaineACheval(): void
    {
        // Lundi 28 septembre → dimanche 4 octobre : 3 jours de septembre, 4 d'octobre.
        $debut = new \DateTimeImmutable('2026-09-28');
        $fin = new \DateTimeImmutable('2026-10-05');

        self::assertSame(700000, Objectifs::prorata(['2026-09' => 3000000, '2026-10' => 3100000], $debut, $fin));
        self::assertSame(400000, Objectifs::prorata(['2026-09' => null, '2026-10' => 3100000], $debut, $fin));
        self::assertNull(Objectifs::prorata(['2026-09' => null, '2026-10' => 0], $debut, $fin));
        self::assertNull(Objectifs::prorata([], $debut, $fin));
    }

    public function testRepartitionDUneFactureParCategorie(): void
    {
        $parts = Objectifs::repartir(1000, [
            ['categorie' => 'Bâche', 'ttc' => 20000],
            ['categorie' => 'Flyers', 'ttc' => 10000],
            ['categorie' => 'Bâche', 'ttc' => 0],
        ]);

        // Le reste de l'arrondi va à la plus grosse part ; la somme reste exacte.
        self::assertSame(['Bâche' => 667, 'Flyers' => 333], $parts);
        self::assertSame(['Autre' => 5000], Objectifs::repartir(5000, []));
        self::assertSame(['DTF' => 5000], Objectifs::repartir(5000, [['categorie' => 'DTF', 'ttc' => 0]]));
    }

    public function testLectureDUneSaisie(): void
    {
        self::assertSame(9000000, Objectifs::lireValeur(' 9 000 000 F '));
        self::assertSame(9000000, Objectifs::lireValeur("9\u{202f}000\u{a0}000"));
        self::assertSame(85, Objectifs::lireValeur('85 %'));
        self::assertSame(500, Objectifs::lireValeur('500 m²'));
        self::assertSame(2, Objectifs::lireValeur('1,5'));
        self::assertSame(0, Objectifs::lireValeur('0'));
        self::assertSame(0, Objectifs::lireValeur('-12'));
        self::assertSame(Objectifs::VALEUR_MAX, Objectifs::lireValeur('99999999999'));
        self::assertNull(Objectifs::lireValeur(''));
        self::assertNull(Objectifs::lireValeur('beaucoup'));
    }

    public function testCeQueChacunPeutVoir(): void
    {
        $direction = DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_STATS_PERSO']);
        $sansMontants = DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL']);
        $agent = DroitsStatistiques::depuisRoles(['ROLE_STATS_PERSO', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR']);

        self::assertTrue(Objectifs::peutVoir('ca', 'categorie', $direction));
        self::assertTrue(Objectifs::peutVoir('taux_encaissement', 'global', $direction));

        // Chiffres globaux sans les indicateurs commerciaux : pas de CA, mais les commandes.
        self::assertFalse(Objectifs::peutVoir('ca', 'global', $sansMontants));
        self::assertFalse(Objectifs::peutVoir('ca', 'agent', $sansMontants));
        self::assertTrue(Objectifs::peutVoir('commandes', 'agent', $sansMontants));
        self::assertTrue(Objectifs::peutVoir('surface', 'machine', $sansMontants));
        self::assertFalse(Objectifs::peutVoir('taux_encaissement', 'global', $sansMontants));

        // Un agent ne voit que ses propres objectifs, jamais ceux des autres ni de l'entreprise.
        self::assertFalse(Objectifs::peutVoir('ca', 'global', $agent));
        self::assertFalse(Objectifs::peutVoir('ca', 'agent', $agent));
        self::assertTrue(Objectifs::peutVoir('ca', 'agent', $agent, true));
        self::assertTrue(Objectifs::peutVoir('commandes', 'agent', $agent, true));
        self::assertFalse(Objectifs::peutVoir('ca', 'global', $agent, true));
        self::assertFalse(Objectifs::peutVoir('ca', 'agent', DroitsStatistiques::aucun(), true));

        // Axe ou indicateur inconnu.
        self::assertFalse(Objectifs::peutVoir('ca', 'machine', $direction));
        self::assertFalse(Objectifs::peutVoir('marge', 'global', $direction));
    }

    public function testLesMontantsDeVenteDemandentLesChiffresGlobaux(): void
    {
        self::assertFalse(DroitsStatistiques::depuisRoles(['ROLE_STATS_VENTES'])['ventes']);
        self::assertTrue(DroitsStatistiques::depuisRoles(['ROLE_STATS_VENTES', 'ROLE_STATS_GLOBAL'])['ventes']);
        self::assertSame([], array_filter(DroitsStatistiques::aucun()));
        self::assertSame(array_keys(DroitsStatistiques::ROLES), array_keys(DroitsStatistiques::calculer(static fn (): bool => true)));
    }
}
