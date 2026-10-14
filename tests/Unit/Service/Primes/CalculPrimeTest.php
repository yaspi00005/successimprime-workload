<?php

namespace App\Tests\Unit\Service\Primes;

use App\Service\Primes\CalculPrime;
use PHPUnit\Framework\TestCase;

final class CalculPrimeTest extends TestCase
{
    private const CRITERES = [
        ['cle' => 'ventes', 'poids' => 30, 'objectif' => 3_000_000],
        ['cle' => 'transformation', 'poids' => 15, 'objectif' => 60],
        ['cle' => 'satisfaction', 'poids' => 15, 'objectif' => 4.5],
        ['cle' => 'encaissement', 'poids' => 10, 'objectif' => 90],
        ['cle' => 'erreurs', 'poids' => 10, 'objectif' => 0],
        ['cle' => 'ponctualite', 'poids' => 10, 'objectif' => 100],
        ['cle' => 'appreciation', 'poids' => 10, 'objectif' => null],
    ];

    /** Exemple de la maquette N02 : 87, 100, 96, 92, 85, 90, 90 → 91, palier 90 à 100, toute la prime. */
    public function testScoreEtPrimeDeLaMaquette(): void
    {
        $r = CalculPrime::evaluer(self::CRITERES, self::mesures([87, 100, 96, 92, 85, 90, 90]), 50_000);

        self::assertSame(91, $r['score']);
        self::assertSame(100, $r['taux']);
        self::assertSame(50_000, $r['prime']);
        self::assertSame([], $r['manquants']);
        self::assertSame(30.0, $r['criteres'][0]['poidsApplique']);
    }

    /** Un critère sans données ne vaut pas 0 : son poids est réparti sur les autres, et la fiche le dit. */
    public function testCritereSansDonneesPoidsReparti(): void
    {
        // Pas d'avis clients ce mois-ci (satisfaction, 15 %).
        $r = CalculPrime::evaluer(self::CRITERES, self::mesures([80, 80, null, 80, 80, 80, 80]), 50_000);

        self::assertSame(80, $r['score'], 'avec un 0 à la place, le score tomberait à 68');
        self::assertSame(['Satisfaction de ses clients'], $r['manquants']);
        $satisfaction = $r['criteres'][2];
        self::assertNull($satisfaction['note']);
        self::assertSame(0.0, $satisfaction['poidsApplique']);
        self::assertSame('pas de données ce mois-ci', $satisfaction['mesure']);
        // 30 % sur 85 % disponibles → 35,3 % appliqués ; la somme reste 100 %.
        self::assertSame(35.3, $r['criteres'][0]['poidsApplique']);
        self::assertEqualsWithDelta(100.0, array_sum(array_column($r['criteres'], 'poidsApplique')), 0.2);
    }

    public function testSansAucuneDonneePasDeScoreNiDePrime(): void
    {
        $r = CalculPrime::evaluer(self::CRITERES, [], 50_000);

        self::assertNull($r['score']);
        self::assertSame(0, $r['taux']);
        self::assertSame(0, $r['prime']);
        self::assertCount(7, $r['manquants']);
    }

    /** Paliers : 90 → 100 %, 80 → 75 %, 70 → 50 %, 60 → 25 %, en dessous rien. */
    public function testScoreVersPartDeLaPrime(): void
    {
        foreach ([100 => 100, 90 => 100, 89 => 75, 80 => 75, 79 => 50, 70 => 50, 69 => 25, 60 => 25, 59 => 0, 0 => 0] as $score => $taux) {
            self::assertSame($taux, CalculPrime::taux($score), 'score '.$score);
        }
        self::assertSame(0, CalculPrime::taux(null));
        self::assertSame('90 à 100', CalculPrime::libellePalier(91));
        self::assertSame('80 à 89', CalculPrime::libellePalier(84));
        self::assertSame('moins de 60', CalculPrime::libellePalier(42));

        // Maquette N01 : Oumar 88 → 75 % de 40 000 = 30 000 F ; Moussa 78 → 50 % de 50 000 = 25 000 F.
        self::assertSame(30_000, CalculPrime::evaluer([['cle' => 'productivite', 'poids' => 100, 'objectif' => 100]], ['productivite' => ['note' => 88, 'mesure' => '']], 40_000)['prime']);
        self::assertSame(25_000, CalculPrime::evaluer([['cle' => 'ventes', 'poids' => 100, 'objectif' => 1]], ['ventes' => ['note' => 78, 'mesure' => '']], 50_000)['prime']);
    }

    public function testPrimeSuspendue(): void
    {
        $r = CalculPrime::evaluer(self::CRITERES, self::mesures([95, 95, 95, 95, 95, 95, 95]), 50_000, true);

        self::assertSame(95, $r['score']);
        self::assertSame(100, $r['taux']);
        self::assertSame(0, $r['prime']);
    }

    public function testNotesDesMesures(): void
    {
        self::assertSame(87, CalculPrime::noteObjectif(2_620_000, 3_000_000));
        self::assertSame(100, CalculPrime::noteObjectif(65, 60), 'plafonnée à 100');
        self::assertSame(96, CalculPrime::noteSatisfaction(4.8));
        self::assertSame(100, CalculPrime::noteErreurs(0));
        self::assertSame(85, CalculPrime::noteErreurs(1));
        self::assertSame(0, CalculPrime::noteErreurs(9));
        self::assertSame(100, CalculPrime::noteErreurs(2, 2), 'erreurs tolérées par l\'objectif');
    }

    public function testLesBaremesParDefautFont100Pourcent(): void
    {
        foreach (CalculPrime::BAREMES as $role => $bareme) {
            self::assertArrayHasKey($role, CalculPrime::ROLES);
            self::assertSame(100, array_sum(array_column($bareme['criteres'], 'poids')), $role);
            foreach ($bareme['criteres'] as $c) {
                self::assertArrayHasKey($c['cle'], CalculPrime::CRITERES);
            }
        }
    }

    /**
     * @param list<?int> $notes dans l'ordre de CRITERES
     *
     * @return array<string, array{note: ?int, mesure: string}>
     */
    private static function mesures(array $notes): array
    {
        $mesures = [];
        foreach (self::CRITERES as $k => $c) {
            if (null !== $notes[$k]) {
                $mesures[$c['cle']] = ['note' => $notes[$k], 'mesure' => 'mesure '.$c['cle']];
            }
        }

        return $mesures;
    }
}
