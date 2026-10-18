<?php

namespace App\Tests\Unit\Service\Rapports;

use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\Planification;
use PHPUnit\Framework\TestCase;

/**
 * Quand part chaque rapport par e-mail (docs/rapports-mail.md) : à son
 * heure, pour la bonne période, rattrapé après une coupure, jamais un
 * vieux rapport.
 */
final class PlanificationTest extends TestCase
{
    private static function d(string $texte): \DateTimeImmutable
    {
        return new \DateTimeImmutable($texte);
    }

    /** L'atelier ferme le dimanche. */
    private static function saufDimanche(): \Closure
    {
        return static fn (\DateTimeImmutable $jour): bool => '7' !== $jour->format('N');
    }

    public function testMomentsParDefaut(): void
    {
        self::assertSame('2026-10-17 20:00', (new Planification('20:00'))->moment(PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17'))->format('Y-m-d H:i'));
        // La semaine 42 (12 au 18 octobre) part le lundi 19 à 7 h ; un mercredi s'il est choisi.
        self::assertSame('2026-10-19 07:00', (new Planification('07:00', 1))->moment(PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42'))->format('Y-m-d H:i'));
        self::assertSame('2026-10-21 07:00', (new Planification('07:00', 3))->moment(PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42'))->format('Y-m-d H:i'));
        // Octobre part le 1er novembre à 7 h ; le 5 s'il est choisi.
        self::assertSame('2026-11-01 07:00', (new Planification('07:00', 1, 1))->moment(PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10'))->format('Y-m-d H:i'));
        self::assertSame('2026-11-05 07:30', (new Planification('07:30', 1, 5))->moment(PeriodeRapport::lire(PeriodeRapport::MOIS, '2026-10'))->format('Y-m-d H:i'));
    }

    public function testRapportDuJourASonHeure(): void
    {
        $plan = new Planification('20:00');

        // Avant 20 h, le dernier dû est celui de la veille (déjà parti : l'historique l'écarte).
        self::assertSame('2026-10-16', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-17 19:59'))?->code());
        self::assertSame('2026-10-17', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-17 20:00'))?->code());
        self::assertSame('2026-10-17', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-17 23:45'))?->code());
    }

    public function testPasDeRapportDuJourUnJourDeFermeture(): void
    {
        $plan = new Planification('20:00');

        // Dimanche soir : rien de nouveau, le samedi reste le dernier.
        self::assertSame('2026-10-17', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-18 21:00'), self::saufDimanche())?->code());
        // Ordinateur éteint samedi soir : le rapport du samedi part encore le lundi matin.
        self::assertSame('2026-10-17', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-19 08:00'), self::saufDimanche())?->code());
        self::assertSame('2026-10-19', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-19 20:05'), self::saufDimanche())?->code());
        // Sans la case « jours d'ouverture », le dimanche a son rapport.
        self::assertSame('2026-10-18', $plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-18 21:00'))?->code());
    }

    public function testUnVieuxRapportNePartPlus(): void
    {
        $plan = new Planification('20:00');

        // Coupure de trois jours : le rapport du jeudi ne part pas le dimanche.
        self::assertNull($plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-18 10:00'), static fn (\DateTimeImmutable $j): bool => $j < self::d('2026-10-16')));
        // Atelier fermé deux semaines : aucun rapport du jour.
        self::assertNull($plan->aEnvoyer(PeriodeRapport::JOUR, self::d('2026-10-18 21:00'), static fn (): bool => false));
    }

    public function testRapportDeLaSemaine(): void
    {
        $plan = new Planification('07:00', 1);

        self::assertNull($plan->aEnvoyer(PeriodeRapport::SEMAINE, self::d('2026-10-19 06:59')));
        self::assertSame('2026-W41', $plan->derniereDue(PeriodeRapport::SEMAINE, self::d('2026-10-19 06:59'))->code());
        self::assertSame('2026-W42', $plan->aEnvoyer(PeriodeRapport::SEMAINE, self::d('2026-10-19 07:00'))?->code());
        // Rattrapé jusqu'au jeudi matin, plus après.
        self::assertSame('2026-W42', $plan->aEnvoyer(PeriodeRapport::SEMAINE, self::d('2026-10-22 06:59'))?->code());
        self::assertNull($plan->aEnvoyer(PeriodeRapport::SEMAINE, self::d('2026-10-22 07:01')));
        // Le jour de fermeture ne compte pas pour la semaine.
        self::assertSame('2026-W42', $plan->aEnvoyer(PeriodeRapport::SEMAINE, self::d('2026-10-19 07:00'), static fn (): bool => false)?->code());
    }

    public function testRapportDuMois(): void
    {
        $plan = new Planification('07:00', 1, 1);

        self::assertNull($plan->aEnvoyer(PeriodeRapport::MOIS, self::d('2026-11-01 06:59')));
        self::assertSame('2026-10', $plan->aEnvoyer(PeriodeRapport::MOIS, self::d('2026-11-01 07:00'))?->code());
        self::assertSame('2026-10', $plan->aEnvoyer(PeriodeRapport::MOIS, self::d('2026-11-08 06:59'))?->code());
        self::assertNull($plan->aEnvoyer(PeriodeRapport::MOIS, self::d('2026-11-08 07:00')));
        // Changement d'année.
        self::assertSame('2026-12', $plan->aEnvoyer(PeriodeRapport::MOIS, self::d('2027-01-01 07:00'))?->code());
    }

    public function testProchainEnvoi(): void
    {
        $dimanche = self::d('2026-10-18 08:00');

        self::assertSame('2026-10-19 20:00', (new Planification('20:00'))->prochain(PeriodeRapport::JOUR, $dimanche, self::saufDimanche())->format('Y-m-d H:i'));
        self::assertSame('2026-10-18 20:00', (new Planification('20:00'))->prochain(PeriodeRapport::JOUR, $dimanche)->format('Y-m-d H:i'));
        self::assertSame('2026-10-19 07:00', (new Planification('07:00', 1))->prochain(PeriodeRapport::SEMAINE, $dimanche)->format('Y-m-d H:i'));
        self::assertSame('2026-11-01 07:00', (new Planification('07:00', 1, 1))->prochain(PeriodeRapport::MOIS, $dimanche)->format('Y-m-d H:i'));
    }

    public function testHeures(): void
    {
        self::assertSame([8, 30], Planification::lireHeure('08:30'));
        self::assertSame([0, 0], Planification::lireHeure('25:00'));
        self::assertSame([0, 0], Planification::lireHeure('8h'));
        self::assertSame('20 h', Planification::heureLisible('20:00'));
        self::assertSame('7 h 30', Planification::heureLisible('07:30'));
    }
}
