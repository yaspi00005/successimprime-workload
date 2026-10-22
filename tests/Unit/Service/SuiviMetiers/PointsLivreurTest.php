<?php

namespace App\Tests\Unit\Service\SuiviMetiers;

use App\Entity\BonLivraison;
use App\Entity\Paiements;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\PointsLivreur;
use PHPUnit\Framework\TestCase;

final class PointsLivreurTest extends TestCase
{
    private function livre(string $remis, ?string $creneau = '08:30-09:30'): BonLivraison
    {
        $b = (new BonLivraison())->setCreneau($creneau)->setPrevuLe(new \DateTimeImmutable('2026-10-15'));
        (new \ReflectionProperty(BonLivraison::class, 'statut'))->setValue($b, BonLivraison::STATUT_LIVRE);
        (new \ReflectionProperty(BonLivraison::class, 'livreLe'))->setValue($b, new \DateTimeImmutable('2026-10-15 '.$remis));

        return $b;
    }

    public function testLivraisonParfaiteDuMaquetteW04(): void
    {
        $b = $this->livre('09:12');
        $b->ajouterPhoto('a.jpg')->ajouterPhoto('b.jpg')->signer('s.png', 'I. Maïga')->noterPosition(12.6392, -8.0029);
        $b->noterEncaissement(18000, 'especes', new \DateTimeImmutable('2026-10-15 09:12'));
        $paiement = new Paiements();
        (new \ReflectionProperty(Paiements::class, 'dateValidation'))->setValue($paiement, new \DateTimeImmutable('2026-10-15 17:00'));
        $b->setPaiementLivraison($paiement);

        self::assertSame(['l_creneau' => 1, 'l_preuve' => 1, 'l_encaissement' => 1], PointsLivreur::faits($b));
        self::assertSame(['gagnes' => 10, 'possibles' => 10, 'note' => 100, 'livrees' => 1, 'ponctualite' => 100], PointsLivreur::totaux([$b], new Bareme()));
    }

    public function testRetardCourtSansPenaliteEtPreuveSansGps(): void
    {
        $b = $this->livre('09:42');
        $b->ajouterPhoto('a.jpg')->signer('s.png', null);
        self::assertSame(12, PointsLivreur::retard($b));
        self::assertSame([], PointsLivreur::faits($b), 'Sans position GPS, la preuve est incomplète.');
        $b->noterPosition(12.6, -8.0);
        self::assertSame(['l_preuve' => 1], PointsLivreur::faits($b));
        self::assertSame(['gagnes' => 2, 'possibles' => 7, 'note' => 29, 'livrees' => 1, 'ponctualite' => 0], PointsLivreur::totaux([$b], new Bareme()));
    }

    public function testRetardLongPenaliseSaufJustifie(): void
    {
        $b = $this->livre('10:15');
        self::assertSame(['l_retard' => 1], PointsLivreur::faits($b));
        self::assertSame(-5, (new Bareme())->points(PointsLivreur::faits($b)));

        $b->setRetardJustifie('Panne de moto');
        self::assertSame([], PointsLivreur::faits($b));
        self::assertSame(['l_preuve' => 1], PointsLivreur::parfaits($b), 'Un retard justifié sort le créneau des points possibles.');
    }

    public function testErreurEtEncaissementNonValide(): void
    {
        $b = $this->livre('09:00', null);
        $b->noterEncaissement(5000, 'orange_money', new \DateTimeImmutable('2026-10-15 09:00'));
        $b->setErreurLivraison('Bâche abîmée');
        self::assertNull(PointsLivreur::retard($b));
        self::assertSame(['l_creneau' => 1, 'l_erreur' => 1], PointsLivreur::faits($b));
        self::assertSame(['l_preuve' => 1, 'l_creneau' => 1, 'l_encaissement' => 1], PointsLivreur::parfaits($b));
    }

    public function testPositionInvalideIgnoree(): void
    {
        $b = new BonLivraison();
        self::assertNull($b->noterPosition(0.0, 0.0)->getRemisePosition());
        self::assertNull($b->noterPosition(95.0, 10.0)->getRemisePosition());
        self::assertSame([12.639201, -8.002912], $b->noterPosition(12.6392011, -8.0029118)->getRemisePosition());
        self::assertSame([], PointsLivreur::faits($b), 'Non remise : pas de points.');
    }
}
