<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ColisCommandes;
use App\Service\Production\ControlesFinaux;
use PHPUnit\Framework\TestCase;

/**
 * Cases du contrôle final par ligne, taille saisie, jour de tournée
 * proposé, contenu de l'étiquette (PD05, PD06, docs/production-colis.md).
 */
final class ControleFinalPointsTest extends TestCase
{
    public function testCasesDUneBacheAvecFinitions(): void
    {
        $points = ControlesFinaux::points([
            'couleur' => 'rouge',
            'taille' => ['l' => 300.0, 'h' => 100.0, 'texte' => '300 × 100 cm'],
            'format' => null,
            'finitions' => 'Œillets : 16 par bâche, bien sertis',
        ]);

        self::assertSame(['textes', 'couleurs', 'taille', 'finitions', 'traces'], array_keys($points));
        self::assertSame('Couleurs : rouge conforme', $points['couleurs']);
        self::assertSame('Taille mesurée : 300 × 100 cm', $points['taille']);
    }

    public function testCasesDeFlyersSansFinition(): void
    {
        $points = ControlesFinaux::points(['couleur' => null, 'taille' => null, 'format' => 'A5', 'finitions' => '']);

        self::assertSame(['textes', 'couleurs', 'taille', 'traces'], array_keys($points));
        self::assertSame('Couleurs conformes au BAT', $points['couleurs']);
        self::assertSame('Format A5 respecté', $points['taille']);
    }

    public function testTailleSaisieEnCentimetres(): void
    {
        self::assertSame(300.0, ControlesFinaux::cm('300'));
        self::assertSame(299.5, ControlesFinaux::cm(' 299,5 '));
        self::assertSame(1000.0, ControlesFinaux::cm('1 000'));
        self::assertNull(ControlesFinaux::cm(''));
        self::assertNull(ControlesFinaux::cm('abc'));
        self::assertNull(ControlesFinaux::cm('0'));
        self::assertNull(ControlesFinaux::cm('-3'));
    }

    public function testTourneeProposeeLeLendemainSaufLeDimanche(): void
    {
        self::assertSame('2026-10-21', ControlesFinaux::prochainJourOuvre(new \DateTimeImmutable('2026-10-20 23:50'))->format('Y-m-d'));
        // Samedi 24/10 : pas de tournée le dimanche, lundi 26/10.
        self::assertSame('2026-10-26', ControlesFinaux::prochainJourOuvre(new \DateTimeImmutable('2026-10-24 09:00'))->format('Y-m-d'));
    }

    public function testEtiquetteSansRedite(): void
    {
        self::assertSame('85 × 55', ColisCommandes::sansRedite('Cartes de visite', 'Carte 85 × 55'));
        self::assertSame('A5', ColisCommandes::sansRedite('Flyers', 'A5'));
        self::assertSame('Affiche A3', ColisCommandes::sansRedite('Flyers', 'Affiche A3'));
        self::assertSame('œillets', ColisCommandes::finitionCourte('Œillets tous les 50 cm'));
        self::assertSame('ourlet', ColisCommandes::finitionCourte('Ourlet'));
    }
}
