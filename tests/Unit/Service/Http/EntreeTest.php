<?php

namespace App\Tests\Unit\Service\Http;

use App\Service\Http\Entree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class EntreeTest extends TestCase
{
    public function testChampVideOuIllisibleDonneLaValeurParDefaut(): void
    {
        $donnees = new InputBag(['bat' => '', 'facture' => '900578', 'texte' => 'abc', 'liste' => ['1'], 'n' => ' 12 ', 'moins' => '-3']);

        self::assertSame(0, Entree::entier($donnees, 'bat'));
        self::assertSame(-1, Entree::entier($donnees, 'bat', -1));
        self::assertSame(900578, Entree::entier($donnees, 'facture'));
        self::assertSame(0, Entree::entier($donnees, 'texte'));
        self::assertSame(0, Entree::entier($donnees, 'liste'));
        self::assertSame(12, Entree::entier($donnees, 'n'));
        self::assertSame(-3, Entree::entier($donnees, 'moins'));
        self::assertSame(5, Entree::entier($donnees, 'absent', 5));
    }
}
