<?php

namespace App\Tests\Unit\Service\Statistiques;

use App\Controller\ObjectifsController;
use App\Controller\RapportSemaineController;
use App\Service\Statistiques\ReglagesRapport;
use App\Service\Statistiques\SemaineIso;
use PHPUnit\Framework\TestCase;

final class SemaineIsoTest extends TestCase
{
    public function testLectureDuCode(): void
    {
        $s = SemaineIso::lire('2026-W41');

        self::assertNotNull($s);
        self::assertSame('2026-10-05 00:00', $s->debut->format('Y-m-d H:i'));
        self::assertSame('2026-10-12 00:00', $s->fin()->format('Y-m-d H:i'));
        self::assertSame('2026-10-11', $s->dimanche()->format('Y-m-d'));
        self::assertSame('2026-W41', $s->code());
        self::assertSame('Semaine 41', $s->titre());
        self::assertSame('2026-W41', SemaineIso::lire(' 2026-w41 ')?->code());

        self::assertNull(SemaineIso::lire('2026-41'));
        self::assertNull(SemaineIso::lire('2026-W00'));
        self::assertNull(SemaineIso::lire('semaine dernière'));
    }

    public function testSemaine53(): void
    {
        self::assertSame(53, SemaineIso::semainesDansAnnee(2026));
        self::assertSame(52, SemaineIso::semainesDansAnnee(2025));
        self::assertSame(53, SemaineIso::semainesDansAnnee(2020));
        self::assertSame('2026-12-28', SemaineIso::lire('2026-W53')?->debut->format('Y-m-d'));
        self::assertNull(SemaineIso::lire('2025-W53'));
        self::assertSame('2027-W01', SemaineIso::lire('2026-W53')?->apres()->code());
    }

    public function testSemainePrecedente(): void
    {
        // Mercredi, lundi 8 h ou dimanche soir : la dernière semaine complète.
        self::assertSame('2026-W41', SemaineIso::precedente(new \DateTimeImmutable('2026-10-14 15:00'))->code());
        self::assertSame('2026-W41', SemaineIso::precedente(new \DateTimeImmutable('2026-10-12 08:00'))->code());
        self::assertSame('2026-W40', SemaineIso::precedente(new \DateTimeImmutable('2026-10-11 23:59'))->code());

        // Au changement d'année, l'année ISO peut différer de l'année civile.
        $s = SemaineIso::precedente(new \DateTimeImmutable('2026-01-05 08:00'));
        self::assertSame('2026-W01', $s->code());
        self::assertSame('2025-12-29', $s->debut->format('Y-m-d'));
        self::assertSame('2025-W52', $s->avant()->code());
        self::assertSame('2026-W02', $s->apres()->code());
    }

    public function testLibelles(): void
    {
        self::assertSame('du 5 au 11 octobre 2026', SemaineIso::lire('2026-W41')?->libelle());
        self::assertSame('du 28 septembre au 4 octobre 2026', SemaineIso::lire('2026-W40')?->libelle());
        self::assertSame('du 29 décembre 2025 au 4 janvier 2026', SemaineIso::lire('2026-W01')?->libelle());
        self::assertSame('du 1er au 7 juin 2026', SemaineIso::lire('2026-W23')?->libelle());
    }

    public function testSemaineDemandeeSurLaPage(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');

        self::assertSame('2026-W38', RapportSemaineController::semaineDemandee('2026-W38', $maintenant)->code());
        // La semaine en cours (pas terminée), une semaine future ou une saisie fausse : la semaine dernière.
        self::assertSame('2026-W41', RapportSemaineController::semaineDemandee('2026-W42', $maintenant)->code());
        self::assertSame('2026-W41', RapportSemaineController::semaineDemandee('2027-W02', $maintenant)->code());
        self::assertSame('2026-W41', RapportSemaineController::semaineDemandee('', $maintenant)->code());
        // Pas plus de deux ans en arrière.
        self::assertSame('2024-W41', RapportSemaineController::semaineDemandee('2024-W41', $maintenant)->code());
        self::assertSame('2026-W41', RapportSemaineController::semaineDemandee('2024-W30', $maintenant)->code());
    }

    public function testMomentDEnvoi(): void
    {
        $lundi = new \DateTimeImmutable('2026-10-12 00:00');

        self::assertSame('2026-10-12 08:00', ReglagesRapport::momentEnvoi($lundi, 'lundi', '08:00')->format('Y-m-d H:i'));
        self::assertSame('2026-10-14 07:30', ReglagesRapport::momentEnvoi($lundi, 'mercredi', '07:30')->format('Y-m-d H:i'));
        self::assertSame('2026-10-18 18:00', ReglagesRapport::momentEnvoi($lundi->setTime(14, 0), 'dimanche', '18:00')->format('Y-m-d H:i'));
        self::assertSame('2026-10-12 09:00', ReglagesRapport::momentEnvoi($lundi, 'jour inconnu', '09:00')->format('Y-m-d H:i'));
    }

    public function testMoisDesObjectifs(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');

        self::assertSame('2026-12-01 00:00', ObjectifsController::moisDemande('2026-12', $maintenant)->format('Y-m-d H:i'));
        self::assertSame('2027-01-01', ObjectifsController::moisDemande('2027-01', $maintenant)->format('Y-m-d'));
        self::assertSame('2025-10-01', ObjectifsController::moisDemande('2025-10', $maintenant)->format('Y-m-d'));
        // Au-delà de trois mois en avance ou d'un an en arrière : ce mois-ci.
        self::assertSame('2026-10-01', ObjectifsController::moisDemande('2027-02', $maintenant)->format('Y-m-d'));
        self::assertSame('2026-10-01', ObjectifsController::moisDemande('2025-09', $maintenant)->format('Y-m-d'));
        self::assertSame('2026-10-01', ObjectifsController::moisDemande('n\'importe quoi', $maintenant)->format('Y-m-d'));

        $choix = ObjectifsController::choixMois($maintenant);
        self::assertCount(16, $choix);
        self::assertSame('2027-01', array_key_first($choix));
        self::assertSame('octobre 2026 (en cours)', $choix['2026-10']);
        self::assertSame('novembre 2026 (à venir)', $choix['2026-11']);
        self::assertSame('septembre 2026', $choix['2026-09']);
    }
}
