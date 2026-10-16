<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * La feuille de style de Select2 vient du thème, chargée une fois par la
 * mise en page. L'ancien chemin assets/plugins/select2/select2.min.css
 * n'existe pas (404 dans la console sur la liste des commandes et la
 * saisie des devis et commandes) : plus aucun gabarit ne le demande.
 */
final class Select2CssTest extends TestCase
{
    private const RACINE = __DIR__.'/../../..';

    public function testAucunGabaritNeDemandeLAncienChemin(): void
    {
        $fautifs = [];
        foreach ((new Finder())->files()->in(self::RACINE.'/templates')->name('*.twig') as $fichier) {
            if (str_contains($fichier->getContents(), 'assets/plugins/select2')) {
                $fautifs[] = $fichier->getRelativePathname();
            }
        }

        self::assertSame([], $fautifs);
    }

    public function testLaMiseEnPageChargeCelleDuTheme(): void
    {
        $base = (string) file_get_contents(self::RACINE.'/templates/base.html.twig');

        self::assertStringContainsString("asset('theme/vendor/select2/css/select2.min.css')", $base);
    }
}
