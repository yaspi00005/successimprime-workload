<?php

namespace App\Tests\Unit\Service;

use App\Service\Montage;
use PHPUnit\Framework\TestCase;

final class MontageTest extends TestCase
{
    public function testFormatsLusDansLeReglage(): void
    {
        self::assertSame(['A4', 'A3', 'SRA3'], Montage::formats('A4 · A3 · SRA3'));
        self::assertSame(['A3', 'SRA3'], Montage::formats('sra3, a3'));
        self::assertSame([], Montage::formats('B2'));
    }

    public function testDonneesNettoyees(): void
    {
        $d = Montage::donnees(['poses' => '4', 'feuille' => 'SRA3', 'quantite' => '1000', 'feuilles' => '258', 'gache' => '3', 'verso' => '1'], 7, 'Konica');
        self::assertSame(['poses' => 4, 'feuille' => 'SRA3', 'quantite' => 1000, 'feuilles' => 258, 'gache' => 3.0, 'verso' => true, 'machine' => 7, 'machineNom' => 'Konica'], $d);
        self::assertSame('4 poses par feuille SRA3 · 258 feuilles à sortir en recto-verso (gâche 3 %) · Konica', Montage::resume($d));
        self::assertNull(Montage::donnees(['poses' => '0']));
        self::assertSame('perso', Montage::donnees(['poses' => 1, 'feuille' => '<b>'])['feuille']);
        self::assertSame('1 pose par feuille A4', Montage::resume(Montage::donnees(['poses' => 1, 'feuille' => 'A4'])));
    }
}
