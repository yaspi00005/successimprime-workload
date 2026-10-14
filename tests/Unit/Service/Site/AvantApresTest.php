<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\ImageSite;
use App\Entity\Realisation;
use App\Service\Site\AvantApres;
use PHPUnit\Framework\TestCase;

/** Avant / après d'une réalisation : d'après le texte alternatif des photos (docs/atelier-3d.md). */
final class AvantApresTest extends TestCase
{
    private static function image(?string $alt): ImageSite
    {
        return (new ImageSite())->setTexteAlternatif($alt);
    }

    public function testPaireDApresLeTexteAlternatif(): void
    {
        $avant = self::image('Avant : façade nue');
        $apres = self::image('APRES, enseigne posée');
        $paire = AvantApres::paire([self::image('Vue de nuit'), $apres, $avant, self::image('Après bis')]);
        self::assertSame($avant, $paire['avant'] ?? null);
        self::assertSame($apres, $paire['apres'] ?? null);

        self::assertNull(AvantApres::paire([self::image('Avant la pose'), self::image(null)]), 'il faut les deux');
        self::assertNull(AvantApres::paire([self::image('Avantage client'), self::image('Après')]), 'mot entier seulement');
    }

    public function testPlusRecenteRealisationAvecSaPaire(): void
    {
        $sans = (new Realisation())->ajouterImage(self::image('Vitrine'));
        $avec = (new Realisation())->ajouterImage(self::image('Avant'))->ajouterImage(self::image('Après'));
        self::assertSame($avec, AvantApres::plusRecente([$sans, $avec])['realisation'] ?? null);
        self::assertNull(AvantApres::plusRecente([$sans]));
    }
}
