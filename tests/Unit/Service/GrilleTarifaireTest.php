<?php

namespace App\Tests\Unit\Service;

use App\Service\GrilleTarifaire;
use PHPUnit\Framework\TestCase;

final class GrilleTarifaireTest extends TestCase
{
    public function testEntier(): void
    {
        self::assertNull(GrilleTarifaire::entier('  '));
        self::assertSame(4800, GrilleTarifaire::entier('4 800'));
        self::assertSame(25000, GrilleTarifaire::entier("25\u{202F}000"));
        self::assertFalse(GrilleTarifaire::entier('4,5'));
        self::assertFalse(GrilleTarifaire::entier('-10'));
    }
}
