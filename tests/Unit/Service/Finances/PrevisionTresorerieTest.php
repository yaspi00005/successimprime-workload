<?php

namespace App\Tests\Unit\Service\Finances;

use App\Service\Finances\PrevisionTresorerie;
use PHPUnit\Framework\TestCase;

final class PrevisionTresorerieTest extends TestCase
{
    public function testProbabiliteSelonRetardHistoriqueEtScenario(): void
    {
        self::assertSame(0.9, PrevisionTresorerie::probabilite(-5, null));
        self::assertSame(0.9, PrevisionTresorerie::probabilite(0, null));
        self::assertSame(0.75, PrevisionTresorerie::probabilite(10, null));
        self::assertSame(0.5, PrevisionTresorerie::probabilite(45, null));
        self::assertSame(0.3, PrevisionTresorerie::probabilite(75, null));
        self::assertSame(0.1, PrevisionTresorerie::probabilite(120, null));

        // Bon payeur : +5 points ; paie d'habitude avec plus de 45 jours de retard : −15.
        self::assertSame(0.95, PrevisionTresorerie::probabilite(0, 3));
        self::assertSame(0.75, PrevisionTresorerie::probabilite(0, 60));
        self::assertSame(0.9, PrevisionTresorerie::probabilite(0, 30));

        // Scénarios : prudent ×0,85 ; jamais plus de 100 %, jamais négatif.
        self::assertSame(0.765, PrevisionTresorerie::probabilite(0, null, 0.85));
        self::assertSame(1.0, PrevisionTresorerie::probabilite(0, 3, 1.1));
        self::assertSame(0.0, PrevisionTresorerie::probabilite(120, 60, 1.0));
    }

    public function testDateAttendue(): void
    {
        $aujourdhui = new \DateTimeImmutable('2026-10-13 15:30');
        $jour = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d');

        // À échoir : l'échéance, plus le retard habituel du client et le décalage du scénario.
        self::assertSame('2026-10-20', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-10-20'), $aujourdhui, null)));
        self::assertSame('2026-11-01', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-10-20'), $aujourdhui, 5, 7)));
        // Due aujourd'hui : attendue demain.
        self::assertSame('2026-10-14', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-10-13'), $aujourdhui, null)));

        // Échue : après relance, 7 jours par tranche de retard.
        self::assertSame('2026-10-20', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-10-03'), $aujourdhui, 40)));
        self::assertSame('2026-10-27', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-08-29'), $aujourdhui, null)));
        self::assertSame('2026-11-10', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-05-01'), $aujourdhui, null)));
        self::assertSame('2026-10-27', $jour(PrevisionTresorerie::dateAttendue(new \DateTimeImmutable('2026-10-03'), $aujourdhui, null, 7)));
    }

    public function testRetardMoyenDuClient(): void
    {
        self::assertNull(PrevisionTresorerie::retardMoyen([]));
        self::assertNull(PrevisionTresorerie::retardMoyen([12]));
        // Payé en avance compte pour 0.
        self::assertSame(10, PrevisionTresorerie::retardMoyen([10, -3, 20]));
    }

    public function testSoldesJourParJourEtPointBas(): void
    {
        $aujourdhui = new \DateTimeImmutable('2026-10-13');
        $points = PrevisionTresorerie::soldes(
            4000000,
            ['2026-10-14' => 100000, '2026-10-16' => 500000],
            // La dette en retard d'hier compte dès demain.
            ['2026-10-12' => 300000, '2026-10-15' => 2000000],
            $aujourdhui,
            4,
        );

        self::assertCount(5, $points);
        self::assertSame([4000000, 3800000, 1800000, 2300000, 2300000], array_column($points, 'solde'));
        self::assertSame(300000, $points[1]['sorties']);

        $bas = PrevisionTresorerie::pointBas($points);
        self::assertSame(1800000, $bas['solde']);
        self::assertSame(2, $bas['index']);
        self::assertSame('2026-10-15', $bas['date']->format('Y-m-d'));

        self::assertSame('2026-10-15', PrevisionTresorerie::premierSousSeuil($points, 2000000)['date']->format('Y-m-d'));
        self::assertNull(PrevisionTresorerie::premierSousSeuil($points, 1000000));
        // À égalité, le premier jour.
        self::assertSame(0, PrevisionTresorerie::pointBas(PrevisionTresorerie::soldes(100, [], [], $aujourdhui, 3))['index']);
    }

    public function testReperesEtCauseDuPointBas(): void
    {
        $aujourdhui = new \DateTimeImmutable('2026-10-13');
        $echeances = [
            self::echeance('2026-10-10', 'Sahel Plast', 485000, 'fournisseur'),
            self::echeance('2026-10-23', 'Patente', 400000, 'fiscal'),
            self::echeance('2026-10-30', 'Loyer boutique et atelier', 450000, 'charge'),
            self::echeance('2026-11-01', 'Encres Pro', 168000, 'fournisseur'),
            self::echeance('2026-11-05', 'Salaires d\'octobre (net)', 2100000, 'salaires'),
            self::echeance('2026-11-20', 'Internet', 45000, 'charge'),
        ];

        // La dette en retard est marquée demain ; le 01/11 est à moins de 5 jours
        // des salaires et Internet est sous le minimum : ni l'un ni l'autre.
        $reperes = PrevisionTresorerie::reperes($echeances, $aujourdhui, 5, 100000);
        self::assertSame(['Fournisseurs 14/10', 'Taxes 23/10', 'Loyer 30/10', 'Salaires 05/11'], array_column($reperes, 'libelle'));
        // Les plus gros d'abord quand il faut choisir.
        self::assertSame(['Fournisseurs 14/10', 'Salaires 05/11'], array_column(PrevisionTresorerie::reperes($echeances, $aujourdhui, 2, 100000), 'libelle'));

        self::assertSame('après les salaires', PrevisionTresorerie::cause($echeances, $aujourdhui, new \DateTimeImmutable('2026-11-06')));
        self::assertSame('après « Loyer boutique et atelier »', PrevisionTresorerie::cause($echeances, $aujourdhui, new \DateTimeImmutable('2026-10-31')));
        self::assertSame('après les paiements fournisseurs', PrevisionTresorerie::cause($echeances, $aujourdhui, new \DateTimeImmutable('2026-10-14')));
        self::assertNull(PrevisionTresorerie::cause($echeances, $aujourdhui, $aujourdhui));
    }

    public function testEchelleEtTrace(): void
    {
        self::assertSame(['min' => 2000000, 'max' => 6000000, 'pas' => 1000000], PrevisionTresorerie::echelle(2000000, 5300000));
        self::assertSame(['min' => 0, 'max' => 60000000, 'pas' => 20000000], PrevisionTresorerie::echelle(2000000, 57000000));
        self::assertSame('2,5 M', PrevisionTresorerie::millions(2500000));
        self::assertSame('−1 M', PrevisionTresorerie::millions(-1000000));
        self::assertSame('0', PrevisionTresorerie::millions(0));

        $aujourdhui = new \DateTimeImmutable('2026-10-13');
        $points = PrevisionTresorerie::soldes(4000000, [], ['2026-10-15' => 2000000], $aujourdhui, 4);
        $trace = PrevisionTresorerie::trace($points, 2000000, [['date' => new \DateTimeImmutable('2026-10-15'), 'libelle' => 'Salaires 15/10', 'total' => 2000000]]);

        self::assertSame(100.0, $trace['seuil_y']);
        self::assertTrue($trace['seuil_visible']);
        self::assertSame(0.0, $trace['depart']['y']);
        self::assertSame(['x' => 50.0, 'y' => 100.0], ['x' => $trace['reperes'][0]['x'], 'y' => $trace['reperes'][0]['y']]);
        self::assertSame('13/10', $trace['dates'][0]['libelle']);
        self::assertStringStartsWith('M0.0,0.0 L250.0,0.0 L500.0,300.0', $trace['ligne']);
        self::assertSame(['2 M', '2,5 M', '3 M', '3,5 M', '4 M'], array_column($trace['graduations'], 'libelle'));
        self::assertSame(100.0, $trace['graduations'][0]['y']);
    }

    public function testMessageAlerte(): void
    {
        $base = [
            'seuil' => 2000000,
            'aujourdhui' => new \DateTimeImmutable('2026-10-13'),
            'sous_seuil' => null,
            'point_bas' => ['date' => new \DateTimeImmutable('2026-11-05'), 'solde' => 1450000],
            'a_relancer' => [['client' => 'Hôtel Djoliba', 'reste' => 412000]],
        ];
        self::assertNull(PrevisionTresorerie::messageAlerte($base));

        $message = PrevisionTresorerie::messageAlerte(['sous_seuil' => ['date' => new \DateTimeImmutable('2026-11-05'), 'solde' => 1450000]] + $base);
        self::assertSame('Trésorerie : le solde prévu passe sous 2 000 000 F le 05/11 (point bas 1 450 000 F le 05/11). À relancer : Hôtel Djoliba 412 000 F.', $message);

        $long = PrevisionTresorerie::messageAlerte(['sous_seuil' => ['date' => new \DateTimeImmutable('2026-10-13'), 'solde' => 1450000], 'a_relancer' => array_fill(0, 3, ['client' => str_repeat('Établissements ', 8), 'reste' => 1])] + $base);
        self::assertStringStartsWith('Trésorerie déjà sous le seuil de 2 000 000 F', $long);
        self::assertSame(255, mb_strlen($long));
    }

    /**
     * @return array{date: \DateTimeImmutable, titre: string, montant: int, type: string}
     */
    private static function echeance(string $date, string $titre, int $montant, string $type): array
    {
        return ['date' => new \DateTimeImmutable($date), 'titre' => $titre, 'montant' => $montant, 'type' => $type];
    }
}
