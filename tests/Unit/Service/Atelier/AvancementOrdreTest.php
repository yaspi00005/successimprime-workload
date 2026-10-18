<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Entity\OrdreProduction;
use App\Service\Atelier\AvancementOrdre;
use PHPUnit\Framework\TestCase;

/**
 * Calculs de l'atelier de production (docs/atelier-production.md) :
 * avancement et fin estimée, retards, gâche, enchaînement de la file sur
 * les heures d'ouverture, ordre automatique et entretien préventif.
 */
final class AvancementOrdreTest extends TestCase
{
    private static function d(string $quand): \DateTimeImmutable
    {
        return new \DateTimeImmutable($quand);
    }

    /** Un ordre « en cours » construit sans base : les dates sont posées directement. */
    private static function ordre(int $duree, ?string $demarre, int $pause = 0, ?string $pauseDepuis = null, string $statut = OrdreProduction::STATUT_EN_COURS): OrdreProduction
    {
        $ordre = new OrdreProduction();
        $ordre->planifier(null, $duree);
        $poser = static function (string $champ, mixed $valeur) use ($ordre): void {
            $p = new \ReflectionProperty(OrdreProduction::class, $champ);
            $p->setValue($ordre, $valeur);
        };
        $poser('statut', $statut);
        $poser('demarreLe', null !== $demarre ? self::d($demarre) : null);
        $poser('dureePauseMinutes', $pause);
        $poser('pauseDebuteLe', null !== $pauseDepuis ? self::d($pauseDepuis) : null);

        return $ordre;
    }

    public function testAvancementEtFinEstimee(): void
    {
        $a = AvancementOrdre::avancement(self::ordre(120, '2026-10-19 10:00'), self::d('2026-10-19 11:00'));

        self::assertSame(50, $a['pourcent']);
        self::assertSame(60, $a['restantes']);
        self::assertSame('12:00', $a['finEstimee']?->format('H:i'));
    }

    public function testLesPausesNeComptentPas(): void
    {
        // 60 min depuis le démarrage, 30 min de pause passée, pause en cours depuis 10 min : 20 min de travail.
        $a = AvancementOrdre::avancement(self::ordre(120, '2026-10-19 10:00', 30, '2026-10-19 10:50', OrdreProduction::STATUT_EN_PAUSE), self::d('2026-10-19 11:00'));

        self::assertSame(20, $a['effectives']);
        self::assertSame(16, $a['pourcent']);
        self::assertSame('12:40', $a['finEstimee']?->format('H:i'));
    }

    public function testUnTravailQuiDepasseNestPasFini(): void
    {
        $a = AvancementOrdre::avancement(self::ordre(60, '2026-10-19 08:00'), self::d('2026-10-19 11:00'));

        self::assertSame(99, $a['pourcent']);
        self::assertSame(5, $a['restantes']);
    }

    public function testTravailPasCommence(): void
    {
        $a = AvancementOrdre::avancement(self::ordre(45, null, statut: OrdreProduction::STATUT_A_PRODUIRE), self::d('2026-10-19 11:00'));

        self::assertSame(0, $a['pourcent']);
        self::assertSame(45, $a['restantes']);
        self::assertNull($a['finEstimee']);
    }

    public function testRetards(): void
    {
        $maintenant = self::d('2026-10-19 10:00');
        // Promis le 19 sans heure : jusqu'à 19 h ; la fin estimée à 19:30 donne 30 min de retard.
        self::assertSame(30, AvancementOrdre::retardMinutes(self::d('2026-10-19'), self::d('2026-10-19 19:30'), $maintenant));
        self::assertSame(0, AvancementOrdre::retardMinutes(self::d('2026-10-19'), self::d('2026-10-19 15:00'), $maintenant));
        // Promis le 15 : déjà dépassé, du 15 à 19 h au 19 à 10 h.
        self::assertSame(87 * 60, AvancementOrdre::retardMinutes(self::d('2026-10-15'), null, $maintenant));
        self::assertSame(0, AvancementOrdre::retardMinutes(self::d('2026-10-15'), null, $maintenant, true));
        self::assertSame(0, AvancementOrdre::retardMinutes(null, null, $maintenant));
    }

    public function testTauxDeGache(): void
    {
        self::assertSame(3.0, AvancementOrdre::tauxGache(97, 3));
        self::assertSame(5.0, AvancementOrdre::tauxGache(190, 10));
        self::assertSame(0.0, AvancementOrdre::tauxGache(50, 0));
        self::assertNull(AvancementOrdre::tauxGache(0, 0));
    }

    public function testLaFileSuitLesHeuresDOuverture(): void
    {
        // Samedi 18 h, deux heures de travail : une heure samedi, une heure lundi (dimanche fermé).
        $c = AvancementOrdre::enchainer([120, 30], self::d('2026-10-24 18:00'));

        self::assertSame('2026-10-24 18:00', $c[0]['debut']->format('Y-m-d H:i'));
        self::assertSame('2026-10-26 09:00', $c[0]['fin']->format('Y-m-d H:i'));
        self::assertSame('2026-10-26 09:30', $c[1]['fin']->format('Y-m-d H:i'));
        self::assertSame('2026-10-19 08:00', AvancementOrdre::ouvert(self::d('2026-10-18 20:00'))->format('Y-m-d H:i'));
        self::assertSame('2026-10-19 08:00', AvancementOrdre::ouvert(self::d('2026-10-19 07:00'))->format('Y-m-d H:i'));
    }

    public function testUnTravailDeplaceGardeSonCreneau(): void
    {
        $c = AvancementOrdre::placer([
            ['duree' => 30],
            ['duree' => 60, 'fixe' => self::d('2026-10-19 09:00')],
            ['duree' => 90],
        ], self::d('2026-10-19 08:00'));

        self::assertSame(['08:00', '08:30'], [$c[0]['debut']->format('H:i'), $c[0]['fin']->format('H:i')]);
        self::assertSame(['09:00', '10:00'], [$c[1]['debut']->format('H:i'), $c[1]['fin']->format('H:i')]);
        // 90 min ne tiennent pas dans le trou de 08:30 à 09:00 : après le créneau réservé.
        self::assertSame(['10:00', '11:30'], [$c[2]['debut']->format('H:i'), $c[2]['fin']->format('H:i')]);
    }

    public function testOrdreAutomatique(): void
    {
        $ordre = AvancementOrdre::ordreAutomatique([
            ['id' => 1, 'matiere' => 'Vinyle', 'promis' => self::d('2026-10-20')],
            ['id' => 2, 'enCours' => true, 'matiere' => 'Bâche'],
            ['id' => 3, 'bloque' => true, 'promis' => self::d('2026-10-19')],
            ['id' => 4, 'retard' => true, 'matiere' => 'Bâche', 'promis' => self::d('2026-10-15')],
            ['id' => 5, 'matiere' => 'Bâche', 'promis' => self::d('2026-10-20')],
            ['id' => 6, 'matiere' => 'Bâche', 'promis' => self::d('2026-10-19')],
        ]);

        // En cours, retard, date promise ; le 20, la bâche (déjà sur la machine) avant le vinyle ; bloqué en bas.
        self::assertSame([2, 4, 6, 5, 1, 3], $ordre);
        self::assertSame(2, AvancementOrdre::changementsRouleau(['Bâche', 'bâche', null, 'Vinyle', 'Bâche']));
    }

    public function testEntretienPreventif(): void
    {
        $maintenant = self::d('2026-10-19 10:00');
        self::assertSame('ok', AvancementOrdre::etatEntretien('semaine', 1, self::d('2026-10-17 10:00'), 0, $maintenant));
        self::assertSame('bientot', AvancementOrdre::etatEntretien('semaine', 1, self::d('2026-10-13 10:00'), 0, $maintenant));
        self::assertSame('a_faire', AvancementOrdre::etatEntretien('semaine', 1, self::d('2026-10-11 10:00'), 0, $maintenant));
        self::assertSame('ok', AvancementOrdre::etatEntretien('jour', 1, self::d('2026-10-19 08:10'), 0, $maintenant));
        self::assertSame('a_faire', AvancementOrdre::etatEntretien('jour', 1, self::d('2026-10-17 18:00'), 0, $maintenant));
        self::assertSame('bientot', AvancementOrdre::etatEntretien('m2', 100, self::d('2026-10-01'), 85, $maintenant));
        self::assertSame('a_faire', AvancementOrdre::etatEntretien('heures', 50, null, 0, $maintenant));
    }

    public function testChargeEtDurees(): void
    {
        self::assertSame(50, AvancementOrdre::charge(330, 660));
        self::assertSame(100, AvancementOrdre::charge(800, 660));
        self::assertSame(121, AvancementOrdre::charge(800, 660, true));
        self::assertSame(0, AvancementOrdre::minutesOuvertes(self::d('2026-10-18')));
        self::assertSame('3 h 20', AvancementOrdre::duree(200));
        self::assertSame('2 h', AvancementOrdre::duree(120));
        self::assertSame('45 min', AvancementOrdre::duree(45));
        self::assertSame('2 jours', AvancementOrdre::duree(2880));
    }
}
