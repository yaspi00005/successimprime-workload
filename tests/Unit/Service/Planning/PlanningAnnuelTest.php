<?php

namespace App\Tests\Unit\Service\Planning;

use App\Entity\Echeance;
use App\Service\Planning\Calendrier;
use App\Service\Planning\PlanningAnnuel;
use PHPUnit\Framework\TestCase;

final class PlanningAnnuelTest extends TestCase
{
    private static function echeance(string $titre, string $categorie, string $frequence, string $debut, ?int $montant = null): Echeance
    {
        return (new Echeance())->setTitre($titre)->setCategorie($categorie)->setFrequence($frequence)
            ->setJourMois((int) substr($debut, 8, 2))->setDebut(new \DateTimeImmutable($debut))->setMontantEstime($montant);
    }

    public function testUneLigneParEcheanceAvecSesMoisEtSonBudget(): void
    {
        $annee = PlanningAnnuel::annee([
            self::echeance('Loyer', 'fournisseurs', Calendrier::MOIS, '2026-01-05', 450000),
            self::echeance('Bâches', 'stock', Calendrier::TRIMESTRE, '2026-01-10', 1400000),
            self::echeance('Assurances', 'administratif', Calendrier::ANNEE, '2026-03-10'),
            self::echeance('Ménage', 'entretien', Calendrier::JOUR, '2026-01-01'),
        ], new Calendrier(), 2026);

        self::assertSame(['Loyer', 'Bâches', 'Assurances'], array_column($annee['lignes'], 'titre'));
        [$loyer, $baches, $assurances] = $annee['lignes'];
        self::assertTrue($loyer['barre']);
        self::assertSame('12 × 450 000 F', $loyer['budget']);
        self::assertSame([1 => 1, 0, 0, 1, 0, 0, 1, 0, 0, 1, 0, 0], $baches['mois']);
        self::assertSame('4 × 1,4 M F', $baches['budget']);
        self::assertSame('mars', $assurances['budget']);
        self::assertSame(12 * 450000 + 4 * 1400000, $annee['budget']);
        self::assertSame(12 * 450000 + 4 * 1400000, $annee['achats']);
    }

    public function testTextesDuBudget(): void
    {
        self::assertSame('mensuel', PlanningAnnuel::budget(Calendrier::MOIS, array_fill(1, 12, 1), 0));
        self::assertSame('juillet – août', PlanningAnnuel::budget(Calendrier::UNE_FOIS, [1 => 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, 0, 0], 0));
        self::assertSame('2 fois', PlanningAnnuel::budget(Calendrier::ANNEE, [1 => 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0], 0));
        self::assertSame('2,6 M F', PlanningAnnuel::compact(2600000));
        self::assertSame('1 M F', PlanningAnnuel::compact(1000000));
        self::assertSame('300 000 F', PlanningAnnuel::compact(300000));
    }

    public function testLesReperesNeGardentQueLesSaisons(): void
    {
        $reperes = PlanningAnnuel::reperes(new Calendrier(['2026-05-27' => 'Tabaski', '2026-05-01' => 'Fête du travail']), 2026);

        self::assertSame(['Tabaski'], $reperes[5]);
        self::assertSame(['Rentrée'], $reperes[9]);
        self::assertSame(['Fin d\'année'], $reperes[12]);
        self::assertSame([], $reperes[1]);
    }
}
