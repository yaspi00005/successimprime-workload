<?php

namespace App\Tests\Unit\Service;

use App\Service\Pointage\CalculPointage as C;
use PHPUnit\Framework\TestCase;

final class CalculPointageTest extends TestCase
{
    private const REGLES = [
        'ouverture' => '08:00', 'fermeture' => '18:00', 'tolerance_minutes' => 10, 'anti_doublon_minutes' => 2,
        'pause_debut' => '13:00', 'pause_fin' => '14:00', 'alerte_depart' => '20:00',
        'points_heure' => 10, 'points_retard' => 10, 'points_soiree' => 30, 'points_nuit' => 100, 'points_oubli' => 20,
    ];

    private static function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testArriveeDepartEtDoublon(): void
    {
        self::assertSame(C::ARRIVEE, C::decider(null, null, self::t('2026-10-10 07:52'), 2));
        self::assertSame(C::DOUBLON, C::decider(self::t('2026-10-10 07:52'), self::t('2026-10-10 07:52'), self::t('2026-10-10 07:53:30'), 2));
        self::assertSame(C::DEPART, C::decider(self::t('2026-10-10 07:52'), self::t('2026-10-10 07:52'), self::t('2026-10-10 18:06'), 2));
        // Nuit au bureau : le pointage du lendemain matin ferme la présence de la veille.
        self::assertSame(C::DEPART, C::decider(self::t('2026-10-10 07:52'), self::t('2026-10-10 07:52'), self::t('2026-10-11 07:40'), 2));
        // Au-delà de 26 h, la présence reste à régulariser et une nouvelle commence.
        self::assertSame(C::ARRIVEE, C::decider(self::t('2026-10-09 07:52'), self::t('2026-10-09 07:52'), self::t('2026-10-10 10:00'), 2));
    }

    public function testExempleDeLaMaquette(): void
    {
        $r = C::presence(self::t('2026-10-10 07:52'), self::t('2026-10-10 18:06'), self::REGLES, self::t('2026-10-10 18:06'));

        self::assertSame(554, $r['presence'], '10 h 14 − 1 h de pause = 9 h 14');
        self::assertSame('9 h 14', C::duree($r['presence']));
        self::assertSame(60, $r['pause']);
        self::assertSame(0, $r['retard']);
        self::assertSame(C::STATUT_COMPLET, $r['statut']);
        self::assertSame(10, $r['total']);
    }

    public function testRetardAuDelaDeLaTolerance(): void
    {
        $r = C::presence(self::t('2026-10-10 08:18'), self::t('2026-10-10 18:02'), self::REGLES, self::t('2026-10-10 19:00'));
        self::assertSame(18, $r['retard']);
        self::assertSame(C::STATUT_RETARD, $r['statut']);
        self::assertSame(-10, $r['total']);

        $tolere = C::presence(self::t('2026-10-10 08:09'), self::t('2026-10-10 18:00'), self::REGLES, self::t('2026-10-10 19:00'));
        self::assertSame(0, $tolere['retard']);
        self::assertSame(10, $tolere['total']);
    }

    public function testBonusSoireeSeulementApresValidation(): void
    {
        $sansValidation = C::presence(self::t('2026-10-10 07:40'), self::t('2026-10-10 19:12'), self::REGLES, self::t('2026-10-10 20:00'));
        self::assertSame(72, $sansValidation['sup']);
        self::assertSame(C::STATUT_HEURES_SUP, $sansValidation['statut']);
        self::assertSame(10, $sansValidation['total']);
        self::assertNotNull($sansValidation['bonusEnAttente']);

        $valide = C::presence(self::t('2026-10-10 07:40'), self::t('2026-10-10 19:12'), self::REGLES, self::t('2026-10-10 20:00'), true);
        self::assertSame(40, $valide['total']);
    }

    public function testNuitAuBureauUnSeulBonus(): void
    {
        $r = C::presence(self::t('2026-10-10 07:50'), self::t('2026-10-11 08:10'), self::REGLES, self::t('2026-10-11 09:00'), true);
        self::assertSame(110, $r['total'], '+10 à l\'heure, +100 nuit, pas de +30 en plus');
    }

    public function testDepartNonPointe(): void
    {
        $avant = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 17:00'));
        self::assertSame(C::STATUT_EN_COURS, $avant['statut']);
        self::assertSame(10, $avant['total']);

        $apres = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 20:01'));
        self::assertSame(C::STATUT_NON_POINTE, $apres['statut']);
        self::assertSame(-10, $apres['total'], '+10 − 20');

        $corrige = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 20:01'), false, 20);
        self::assertSame(10, $corrige['total']);
    }

    public function testNoteSur100(): void
    {
        self::assertSame(100, C::note(220, 22));
        self::assertSame(91, C::note(200, 22));
        self::assertSame(95, C::note(210, 22));
        self::assertSame(100, C::note(400, 22));
        self::assertSame(0, C::note(-50, 22));
        self::assertNull(C::note(0, 0));
    }

    public function testJourDeReposSansRetardEtToutEnHeuresSup(): void
    {
        // Dimanche 11/10/2026, semaine lun-sam.
        $r = C::presence(self::t('2026-10-11 10:00'), self::t('2026-10-11 12:30'), self::REGLES + ['jours' => 'lun-sam'], self::t('2026-10-11 13:00'));

        self::assertSame(0, $r['retard']);
        self::assertSame(150, $r['sup']);
        self::assertSame(C::STATUT_HEURES_SUP, $r['statut']);
        self::assertSame(0, $r['total'], 'pas de point de ponctualité, bonus à valider');
        self::assertNotNull($r['bonusEnAttente']);
    }

    public function testPosteDeNuit(): void
    {
        // Gardien (E03) : 19 h – 7 h, la pause de midi ne le concerne pas.
        $regles = ['ouverture' => '19:00', 'fermeture' => '07:00', 'alerte_depart' => '09:00'] + self::REGLES;
        $r = C::presence(self::t('2026-10-12 18:50'), self::t('2026-10-13 07:04'), $regles, self::t('2026-10-13 08:00'));

        self::assertSame(0, $r['retard']);
        self::assertSame(0, $r['pause']);
        self::assertSame(734, $r['presence'], '12 h 14');
        self::assertSame(4, $r['sup']);
        self::assertSame(C::STATUT_COMPLET, $r['statut']);

        // Encore en poste à 3 h du matin : pas d'alerte « départ non pointé ».
        $r = C::presence(self::t('2026-10-12 18:50'), null, $regles, self::t('2026-10-13 03:00'));
        self::assertSame(C::STATUT_EN_COURS, $r['statut']);
    }

    public function testJoursPropresAUnEmploye(): void
    {
        self::assertSame([1, 3, 4, 5, 6, 7], C::joursOuvres('1,3,4,5,6,7'));
        self::assertSame([1, 2, 3, 4, 5, 6], C::joursOuvres('lun-sam'));
    }
}
