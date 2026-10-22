<?php

namespace App\Tests\Unit\Twig;

use App\Twig\ReglagesExtension;
use PHPUnit\Framework\TestCase;

final class ReglagesExtensionTest extends TestCase
{
    public function testDateEnFrancaisSansIntl(): void
    {
        $date = new \DateTimeImmutable('2026-10-05 08:07');

        self::assertSame('lundi 5 octobre 2026', ReglagesExtension::dateFr($date, 'EEEE d MMMM y'));
        self::assertSame('lun. 05/10 · 08:07', ReglagesExtension::dateFr($date, 'EEE dd/MM · HH:mm'));
        self::assertSame('août 2026', ReglagesExtension::dateFr(new \DateTimeImmutable('2026-08-01'), 'MMMM yyyy'));
    }
}
