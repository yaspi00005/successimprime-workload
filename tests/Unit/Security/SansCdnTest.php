<?php

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Rien d'un autre site à l'exécution (docs/securite.md, audit D4) :
 * aucune page ne charge un script, un style ou une police depuis un CDN,
 * et chaque fichier de public/vendor appelé par une page existe.
 */
final class SansCdnTest extends TestCase
{
    private const RACINE = __DIR__.'/../../..';

    public function testAucunePageNeChargeDeFichierDUnAutreSite(): void
    {
        $motifs = [
            'script' => '~<script\b[^>]*\bsrc\s*=\s*["\']?(?:https?:)?//~i',
            'link' => '~<link\b[^>]*\bhref\s*=\s*["\']?(?:https?:)?//~i',
            'import' => '~@import\s+(?:url\(\s*)?["\']?(?:https?:)?//~i',
            'police' => '~url\(\s*["\']?(?:https?:)?//[^)]*\.(?:woff2?|ttf|otf|eot)~i',
        ];
        $fautes = [];
        $fichiers = (new Finder())->files()->in([self::RACINE.'/templates', self::RACINE.'/public/css', self::RACINE.'/public/js'])->name(['*.twig', '*.css', '*.js']);
        foreach ($fichiers as $fichier) {
            foreach ($motifs as $nom => $motif) {
                if (preg_match($motif, $fichier->getContents(), $m)) {
                    $fautes[] = $fichier->getRelativePathname().' ('.$nom.') : '.$m[0];
                }
            }
        }

        self::assertSame([], $fautes);
    }

    public function testLesFichiersDePublicVendorAppelesExistent(): void
    {
        $appels = [];
        foreach ((new Finder())->files()->in(self::RACINE.'/templates')->name('*.twig') as $fichier) {
            preg_match_all("~asset\(\s*'(vendor/[^']+)'~", $fichier->getContents(), $m);
            foreach ($m[1] as $chemin) {
                $appels[$chemin] = $fichier->getRelativePathname();
            }
        }

        self::assertArrayHasKey('vendor/sortablejs-1.15.6/Sortable.min.js', $appels);
        self::assertArrayHasKey('vendor/chart.js-4.4.4/chart.umd.js', $appels);
        self::assertArrayHasKey('vendor/poppins-v24/poppins.css', $appels);
        foreach ($appels as $chemin => $page) {
            self::assertFileExists(self::RACINE.'/public/'.$chemin, $page);
        }
    }

    public function testChaqueBibliothequeGardeSaLicence(): void
    {
        foreach ((new Finder())->directories()->in(self::RACINE.'/public/vendor')->depth(0) as $dossier) {
            $licences = (new Finder())->files()->in($dossier->getPathname())->name(['LICENSE*', 'OFL*']);
            self::assertTrue($licences->hasResults(), $dossier->getFilename().' sans licence');
        }

        // Les polices copiées depuis Google Fonts : chaque fichier appelé par le CSS est là.
        foreach ((new Finder())->files()->in(self::RACINE.'/public/vendor')->path('~^[a-z]+-v\d+/~')->name('*.css') as $css) {
            preg_match_all('~url\("([^"]+)"\)~', $css->getContents(), $m);
            self::assertNotEmpty($m[1], $css->getRelativePathname());
            foreach ($m[1] as $police) {
                self::assertFileExists($css->getPath().'/'.$police);
            }
            self::assertStringNotContainsString('//', preg_replace('~/\*.*?\*/~s', '', $css->getContents()), $css->getRelativePathname());
        }
    }
}
