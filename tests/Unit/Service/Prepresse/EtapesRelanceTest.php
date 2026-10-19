<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Service\Prepresse\EtapesRelance;
use PHPUnit\Framework\TestCase;

/** Relances des fichiers manquants : J+1, J+3, J+7, J+15, une fois chacune. */
final class EtapesRelanceTest extends TestCase
{
    private const TOUTES = ['J1' => true, 'J3' => true, 'J7' => true, 'J15' => true];

    public function testEtapeDueSelonLesJours(): void
    {
        $commande = new \DateTimeImmutable('2026-10-01 09:00');
        self::assertNull(EtapesRelance::aFaire($commande, new \DateTimeImmutable('2026-10-01 20:00'), [], self::TOUTES));
        self::assertSame('J1', EtapesRelance::aFaire($commande, new \DateTimeImmutable('2026-10-02 09:30'), [], self::TOUTES));
        self::assertSame('J3', EtapesRelance::aFaire($commande, new \DateTimeImmutable('2026-10-04 10:00'), ['J1'], self::TOUTES));
        self::assertSame('J7', EtapesRelance::aFaire($commande, new \DateTimeImmutable('2026-10-08 10:00'), ['J1', 'J3'], self::TOUTES));
        self::assertSame('J15', EtapesRelance::aFaire($commande, new \DateTimeImmutable('2026-10-16 10:00'), ['J1', 'J3', 'J7'], self::TOUTES));
    }

    public function testUneSeuleFoisMemeEnRelancantLaTache(): void
    {
        $commande = new \DateTimeImmutable('2026-10-01 09:00');
        $plusTard = new \DateTimeImmutable('2026-10-04 10:00');
        self::assertNull(EtapesRelance::aFaire($commande, $plusTard, ['J3'], self::TOUTES));
        // Une étape plus loin déjà faite : on ne revient pas en arrière.
        self::assertNull(EtapesRelance::aFaire($commande, new \DateTimeImmutable('2026-10-02 10:00'), ['J3'], self::TOUTES));
    }

    public function testEnRetardSeuleLaDerniereEtape(): void
    {
        // Tâche arrêtée une semaine : pas trois messages d'un coup, seulement J+7.
        self::assertSame('J7', EtapesRelance::aFaire(new \DateTimeImmutable('2026-10-01 09:00'), new \DateTimeImmutable('2026-10-09 09:00'), [], self::TOUTES));
    }

    public function testEtapeDesactiveeDansLesReglages(): void
    {
        $actives = ['J1' => true, 'J3' => false, 'J7' => true, 'J15' => false];
        self::assertNull(EtapesRelance::aFaire(new \DateTimeImmutable('2026-10-01 09:00'), new \DateTimeImmutable('2026-10-05 09:00'), ['J1'], $actives));
        self::assertNull(EtapesRelance::aFaire(new \DateTimeImmutable('2026-10-01 09:00'), new \DateTimeImmutable('2026-10-20 09:00'), ['J1', 'J7'], $actives));
    }

    public function testFrise(): void
    {
        $frise = EtapesRelance::frise(['J1', 'J3']);
        self::assertSame(['J1', 'J3', 'J7'], array_column($frise, 'etape'));
        self::assertSame([true, true, false], array_column($frise, 'faite'));
    }

    public function testMessageListeLesFormatsManquants(): void
    {
        self::assertSame(
            'Bonjour Fofana, votre commande CMD-000378 (étiquettes vinyle, 6 formats) attend encore 4 fichiers : 5,5 × 7 · 5,5 × 5 · 5,5 × 4 · 5,5 × 5,5 cm. Envoyez-les ici : https://succesimprim.com/c/abc ou en réponse. Merci ! Succès Imprim',
            EtapesRelance::message('Fofana', 'CMD-000378', 'étiquettes vinyle', 6, ['5,5 × 7', '5,5 × 5', '5,5 × 4', '5,5 × 5,5'], 'https://succesimprim.com/c/abc'),
        );
        self::assertStringContainsString('attend encore 1 fichier : 85 × 200 cm.', EtapesRelance::message('Sacko', 'CMD-1', 'roll-up', 1, ['85 × 200'], null));
    }
}
