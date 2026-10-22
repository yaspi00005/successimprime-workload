<?php

namespace App\Tests\Unit\Security;

use App\Service\Site\MesureAudience;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Rien d'un autre site à l'exécution (docs/securite.md, audit D4) :
 * aucune page ne charge un script, un style ou une police depuis un CDN,
 * et chaque fichier de public/vendor appelé par une page existe. Seule
 * exception, demandée par Yaya : la balise Google de mesure d'audience,
 * sur les pages publiques du site en ligne (MesureAudience).
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

    /**
     * L'adresse de gtag.js n'est écrite que dans public/js/mesure-audience.js,
     * qui n'appelle rien d'autre d'un autre site ; seul le gabarit du site
     * public appelle ce fichier, et seulement quand MesureAudience donne un
     * identifiant (pages publiques du site en ligne).
     */
    public function testSeuleExceptionLaMesureDAudienceDuSitePublic(): void
    {
        $script = 'public/js/mesure-audience.js';
        $gabarit = 'templates/site/_site.html.twig';
        $fautes = [];
        $fichiers = (new Finder())->files()->in([self::RACINE.'/templates', self::RACINE.'/public/css', self::RACINE.'/public/js'])->name(['*.twig', '*.css', '*.js']);
        foreach ($fichiers as $fichier) {
            $chemin = substr(str_replace('\\', '/', $fichier->getPathname()), \strlen(self::RACINE) + 1);
            $contenu = $fichier->getContents();
            if (preg_match('~googletagmanager|google-analytics|analytics\.google|doubleclick~i', $contenu) && $script !== $chemin) {
                $fautes[] = $chemin.' : adresse de Google';
            }
            if (str_contains($contenu, 'mesure-audience.js') && !\in_array($chemin, [$script, $gabarit], true)) {
                $fautes[] = $chemin.' : appelle la mesure d\'audience';
            }
        }
        self::assertSame([], $fautes);

        preg_match_all('~(?:https?:)?//[a-z0-9.-]+\.[a-z]{2,}[^\s\'"]*~i', (string) file_get_contents(self::RACINE.'/'.$script), $adresses);
        self::assertSame([MesureAudience::SCRIPT.'?id='], $adresses[0], 'mesure-audience.js n\'appelle que gtag.js');

        $page = (string) file_get_contents(self::RACINE.'/'.$gabarit);
        self::assertSame(1, substr_count($page, 'mesure-audience.js'));
        self::assertMatchesRegularExpression("~\\{% set mesure_audience = site_mesure_audience\\(\\) %\\}\\s*\\{% if mesure_audience %\\}<script src=\"\\{\\{ asset\\('js/mesure-audience\\.js'\\) \\}\\}\" data-mesure=\"\\{\\{ mesure_audience \\}\\}\" defer></script>\\{% endif %\\}~", $page);
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
