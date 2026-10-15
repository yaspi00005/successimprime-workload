<?php

namespace App\Tests\Unit\Twig;

use App\Twig\TempsEcouleExtension;
use PHPUnit\Framework\TestCase;

final class TempsEcouleExtensionTest extends TestCase
{
    public function testFormeLongueEtFormeCourteDeLaCloche(): void
    {
        $f = new TempsEcouleExtension();
        $maintenant = new \DateTimeImmutable('2026-10-15 21:00:00');
        $il = static fn (string $ecart): \DateTimeImmutable => $maintenant->modify($ecart);

        self::assertSame('à l\'instant', $f->tempsEcoule($il('-30 seconds'), true, $maintenant));
        self::assertSame('il y a 16 minutes', $f->tempsEcoule($il('-16 minutes'), false, $maintenant));
        self::assertSame('il y a 16 min', $f->tempsEcoule($il('-16 minutes'), true, $maintenant));
        self::assertSame('il y a 1 heure', $f->tempsEcoule($il('-61 minutes'), false, $maintenant));
        self::assertSame('il y a 3 h', $f->tempsEcoule($il('-3 hours'), true, $maintenant));
        self::assertSame('il y a 2 jours', $f->tempsEcoule($il('-2 days'), true, $maintenant));
        self::assertSame('le 01/09/2026', $f->tempsEcoule(new \DateTimeImmutable('2026-09-01 08:00'), true, $maintenant));
        self::assertSame('', $f->tempsEcoule(null));
    }
}
