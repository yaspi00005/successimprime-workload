<?php

namespace App\Tests\Unit\Service\Site;

use App\Controller\DemandesContactController;
use App\Controller\SiteReclamationController;
use App\Service\EspaceClient\ReclamationsClients;
use App\Service\Site\AtelierDirect;
use App\Service\Site\EngagementsSite;
use App\Service\Site\TableauEngagements;
use PHPUnit\Framework\TestCase;

/** Nos engagements (EC01 à EC03, EC09) : jamais un chiffre inventé, jamais un montant sans compte. */
final class EngagementsSiteTest extends TestCase
{
    public function testPourcentageArrondiVersLeBasEtVideSansMesure(): void
    {
        self::assertNull(TableauEngagements::pourcentage(0, 0));
        self::assertSame(99, TableauEngagements::pourcentage(199, 200), '99,5 % ne devient pas 100 %');
        self::assertSame(100, TableauEngagements::pourcentage(4, 4));
        self::assertSame(70, TableauEngagements::pourcentage(7, 10));
    }

    public function testChiffresPubliesSeulementSiValides(): void
    {
        self::assertNull(EngagementsSite::lireChiffres([]));
        self::assertNull(EngagementsSite::lireChiffres(['a_la_date' => 0, 'bat' => 'abc', 'reponse' => 140]));

        $c = EngagementsSite::lireChiffres(['bat' => 100, 'a_la_date' => '70', 'inconnu' => 50, 'periode' => ' octobre 2026 ', 'publie_le' => '2026-10-19T10:00:00+00:00']);
        self::assertSame(['a_la_date', 'bat'], array_column($c['chiffres'], 'cle'), 'ordre de la page, clés connues seulement');
        self::assertSame('70', $c['chiffres'][0]['valeur']);
        self::assertSame('octobre 2026', $c['periode']);
        self::assertSame('2026-10-19', $c['publieLe']->format('Y-m-d'));
    }

    public function testPointsDeCeQueNousNePromettonsPas(): void
    {
        self::assertSame(['Un tirage en 1 h', 'Le prix le plus bas'], EngagementsSite::points(' Un tirage   en 1 h | | Le prix le plus bas '));
        self::assertSame([], EngagementsSite::points(''));
    }

    public function testAtelierEnDirectSeulementSiFrais(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 10:00:00');
        $frais = ['maj' => $maintenant->modify('-5 minutes')->format(\DATE_ATOM), 'machines' => 9, 'total' => 4, 'm2' => 12.34, 'travaux' => 7];

        $l = AtelierDirect::lire($frais, $maintenant);
        self::assertSame(4, $l['machines'], 'jamais plus de machines que le parc');
        self::assertSame(12.3, $l['m2']);
        self::assertNull(AtelierDirect::lire(['maj' => $maintenant->modify('-'.(AtelierDirect::FRAICHEUR + 1).' minutes')->format(\DATE_ATOM)] + $frais, $maintenant));
        self::assertNull(AtelierDirect::lire([], $maintenant));
        self::assertTrue(AtelierDirect::vide(['machines' => 0, 'm2' => 0.0, 'travaux' => 0]));
        self::assertFalse(AtelierDirect::vide(['machines' => 0, 'm2' => 0.0, 'travaux' => 1]));
    }

    public function testReponseSansMontant(): void
    {
        self::assertSame('Avoir de … sur la prochaine commande.', SiteReclamationController::sansMontant('Avoir de 5 000 F sur la prochaine commande.'));
        self::assertSame('Remise de … et de ….', SiteReclamationController::sansMontant('Remise de 21 000 FCFA et de 3500 francs.'));
        self::assertSame('Réimpression le 21/10 à 10 h.', SiteReclamationController::sansMontant('Réimpression le 21/10 à 10 h.'));
    }

    public function testMotifDeLaReclamation(): void
    {
        self::assertSame('retard', ReclamationsClients::motifPour('Date annoncée, date tenue'));
        self::assertSame('couleur', ReclamationsClients::motifPour('BAT avant impression'));
        self::assertSame('autre', ReclamationsClients::motifPour('Prix annoncé = prix payé'));
    }

    public function testMoisDuTableau(): void
    {
        [$debut, $fin, $mois] = DemandesContactController::mois('2026-02');
        self::assertSame('2026-02-01', $debut->format('Y-m-d'));
        self::assertSame('2026-03-01', $fin->format('Y-m-d'));
        self::assertSame('2026-02', $mois);
        self::assertSame((new \DateTimeImmutable())->format('Y-m'), DemandesContactController::mois('2026-13')[2]);
        self::assertSame('février 2026', DemandesContactController::nomMois($debut));
    }
}
