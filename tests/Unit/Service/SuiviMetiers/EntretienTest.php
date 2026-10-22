<?php

namespace App\Tests\Unit\Service\SuiviMetiers;

use App\Entity\Echeance;
use App\Entity\Tache;
use App\Entity\User;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\Entretien;
use PHPUnit\Framework\TestCase;

final class EntretienTest extends TestCase
{
    private function tache(bool $fermeture = false, int $id = 1): Tache
    {
        $e = (new Echeance())->setTitre('Atelier')->setCategorie('entretien')->setHeure('07:30')->setHeureFin('08:00')->setFermeture($fermeture)->setEtapes(['Sol', 'Machines']);
        $t = (new Tache(new \DateTimeImmutable('2026-10-12')))->reprendre($e);
        (new \ReflectionProperty(Tache::class, 'id'))->setValue($t, $id);

        return $t;
    }

    public function testFaiteDansLeCreneauAvecPhoto(): void
    {
        $t = $this->tache();
        $t->setPhoto('2026/10/x.jpg');
        $t->cocher(new User(), new \DateTimeImmutable('2026-10-12 07:55'));

        self::assertTrue(Entretien::dansLeCreneau($t));
        self::assertSame(['e_creneau' => 1], Entretien::faits($t, new \DateTimeImmutable('2026-10-12 12:00')));
    }

    public function testSansPhotoPasDePoint(): void
    {
        $t = $this->tache();
        $t->cocher(new User(), new \DateTimeImmutable('2026-10-12 07:55'));

        self::assertFalse(Entretien::dansLeCreneau($t));
    }

    public function testRetardDePlusDe30Minutes(): void
    {
        $t = $this->tache();
        self::assertSame(20, Entretien::retard($t, new \DateTimeImmutable('2026-10-12 08:20')));
        self::assertSame([], Entretien::faits($t, new \DateTimeImmutable('2026-10-12 08:20')));
        self::assertSame(['e_retard' => 1], Entretien::faits($t, new \DateTimeImmutable('2026-10-12 08:45')));

        $t->setPhoto('2026/10/x.jpg');
        $t->cocher(new User(), new \DateTimeImmutable('2026-10-12 08:10'));
        self::assertSame(10, Entretien::retard($t, new \DateTimeImmutable('2026-10-12 18:00')));
        self::assertFalse(Entretien::dansLeCreneau($t));
    }

    public function testFermetureCompleteEtControle(): void
    {
        $t = $this->tache(true);
        $t->definirEtapes([0, 1]);
        $t->setPhoto('2026/10/x.jpg');
        $t->cocher(new User(), new \DateTimeImmutable('2026-10-12 07:50'));
        $t->controler(false, new User(), new \DateTimeImmutable('2026-10-12 09:00'));

        $faits = Entretien::faits($t, new \DateTimeImmutable('2026-10-12 12:00'));
        self::assertSame(['e_creneau' => 1, 'e_fermeture' => 1, 'e_non_conforme' => 1], $faits);
        self::assertSame(3 + 5 - 3, (new Bareme())->points($faits));
    }

    public function testFermetureIncompletePasDeBonus(): void
    {
        $t = $this->tache(true);
        $t->definirEtapes([0]);
        $t->cocher(new User(), new \DateTimeImmutable('2026-10-12 07:50'));

        self::assertArrayNotHasKey('e_fermeture', Entretien::faits($t, new \DateTimeImmutable('2026-10-12 12:00')));
    }

    public function testTotauxIgnoreLesTachesPasEncoreDues(): void
    {
        $faite = $this->tache(false, 1);
        $faite->setPhoto('2026/10/x.jpg');
        $faite->cocher(new User(), new \DateTimeImmutable('2026-10-12 07:40'));
        $pasDue = $this->tache(false, 2);

        $r = Entretien::totaux([$faite, $pasDue], new Bareme(), new \DateTimeImmutable('2026-10-12 07:45'));
        self::assertSame(3, $r['gagnes']);
        self::assertSame(3, $r['possibles']);
        self::assertSame(100, $r['note']);
    }

    public function testTirageStableParmiLesTachesFaites(): void
    {
        $taches = [];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $t = $this->tache(false, $id);
            if ($id !== 3) {
                $t->cocher(new User(), new \DateTimeImmutable('2026-10-12 07:40'));
            }
            $taches[] = $t;
        }
        $jour = new \DateTimeImmutable('2026-10-12');
        $tirage = Entretien::tirage($taches, $jour, 2);

        self::assertCount(2, $tirage);
        self::assertNotContains(3, $tirage);
        self::assertSame($tirage, Entretien::tirage(array_reverse($taches), $jour, 2));
        self::assertSame([], Entretien::tirage($taches, $jour, 0));
    }
}
