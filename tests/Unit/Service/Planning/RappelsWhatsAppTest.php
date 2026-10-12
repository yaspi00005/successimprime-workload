<?php

namespace App\Tests\Unit\Service\Planning;

use App\Service\Planning\Planning;
use PHPUnit\Framework\TestCase;

final class RappelsWhatsAppTest extends TestCase
{
    public function testMessagesSelonLeMoment(): void
    {
        $le = new \DateTimeImmutable('2026-10-15');

        self::assertStringContainsString('« Déclaration TVA » est prévu le 15/10/2026, dans 3 jours.', Planning::messageWhatsApp('Fanta', 'j5', 'Déclaration TVA', $le, 3));
        self::assertStringContainsString('est prévu demain, 15/10/2026.', Planning::messageWhatsApp('Fanta', 'veille', 'Loyer', $le, 1));
        self::assertStringContainsString('« Loyer » est prévu aujourd\'hui.', Planning::messageWhatsApp('Fanta', 'jour', 'Loyer', $le, 0));
        self::assertStringContainsString('prévu le 15/10/2026, n\'est pas encore fait.', Planning::messageWhatsApp('Fanta', 'retard', 'Loyer', $le, -2));
        self::assertStringStartsWith("Bonjour Fanta,\n", Planning::messageWhatsApp('Fanta', 'jour', 'Loyer', $le, 0));
    }
}
