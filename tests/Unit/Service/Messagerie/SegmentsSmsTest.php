<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Service\Messagerie\SegmentsSms;
use PHPUnit\Framework\TestCase;

/** Crédits SMS et e-mail : SMS comptés comme chez l'opérateur (GSM 03.38). */
final class SegmentsSmsTest extends TestCase
{
    public function testAlphabetGsm160Puis153(): void
    {
        self::assertSame(0, SegmentsSms::compter(''));
        self::assertSame(1, SegmentsSms::compter('Bonjour, votre commande est prête à retirer.'));
        self::assertSame(1, SegmentsSms::compter(str_repeat('a', 160)));
        self::assertSame(2, SegmentsSms::compter(str_repeat('a', 161)));
        self::assertSame(2, SegmentsSms::compter(str_repeat('a', 306)));
        self::assertSame(3, SegmentsSms::compter(str_repeat('a', 307)));
    }

    public function testCaracteresEtendusCompentDouble(): void
    {
        // 159 lettres + « € » (2) = 161 : deux SMS.
        self::assertSame(2, SegmentsSms::compter(str_repeat('a', 159).'€'));
        self::assertSame(1, SegmentsSms::compter(str_repeat('a', 158).'€'));
    }

    public function testUnCaractereHorsAlphabetPasseA70(): void
    {
        // « ê » n'est pas dans l'alphabet GSM : tout le message passe en UCS-2.
        self::assertSame(1, SegmentsSms::compter('Fête : '.str_repeat('a', 63)));
        self::assertSame(2, SegmentsSms::compter('Fête : '.str_repeat('a', 64)));
        self::assertSame(2, SegmentsSms::compter(str_repeat('ç', 134)));
        self::assertSame(3, SegmentsSms::compter(str_repeat('ç', 135)));
        // Un emoji compte deux caractères UCS-2.
        self::assertSame(2, SegmentsSms::compter(str_repeat('a', 69).'🎉'));
    }
}
