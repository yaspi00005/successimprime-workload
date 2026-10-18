<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Service\AnalyseFichierImpression;
use App\Service\EspaceClient\BordsFichier;
use PHPUnit\Framework\TestCase;

/** « Texte près du bord » (maquette CO03) : un indice, jamais un refus. */
final class BordsFichierTest extends TestCase
{
    public function testMargeSelonLaTaille(): void
    {
        self::assertSame(4.0, BordsFichier::marge(300, 100));
        self::assertSame(2.0, BordsFichier::marge(42, 29.7));
        self::assertSame(0.4, BordsFichier::marge(8.5, 5.5));
    }

    public function testDetailsDansLaZoneDeSecurite(): void
    {
        // Fond uni : rien au bord.
        $uni = array_fill(0, 60, array_fill(0, 180, 40));
        self::assertSame(0.0, BordsFichier::densiteMaxAuxBords($uni, .05, .1));

        // Un motif au centre seulement : rien au bord.
        $centre = $uni;
        for ($y = 25; $y < 35; ++$y) {
            for ($x = 60; $x < 120; $x += 2) {
                $centre[$y][$x] = 250;
            }
        }
        self::assertSame(0.0, BordsFichier::densiteMaxAuxBords($centre, .05, .1));

        // Des traits blancs (texte) collés au bord gauche.
        $bord = $uni;
        for ($y = 10; $y < 50; $y += 2) {
            for ($x = 1; $x < 8; $x += 2) {
                $bord[$y][$x] = 250;
            }
        }
        self::assertGreaterThan(.12, BordsFichier::densiteMaxAuxBords($bord, .05, .1));
    }

    public function testSansTailleOuFichierIlliblePasDeVerdict(): void
    {
        self::assertSame(AnalyseFichierImpression::A_VERIFIER, BordsFichier::controler(__FILE__, null, null)['etat']);
        self::assertSame(AnalyseFichierImpression::A_VERIFIER, BordsFichier::controler(__FILE__, 300, 100, true)['etat']);
        self::assertStringContainsString('œillets', BordsFichier::controler(__FILE__, 300, 100, true)['valeur']);
    }

    public function testImagePng(): void
    {
        if (!\function_exists('imagecreatefrompng')) {
            self::markTestSkipped('GD sans PNG');
        }
        $chemin = tempnam(sys_get_temp_dir(), 'bord').'.png';
        $im = imagecreatetruecolor(600, 200);
        imagefilledrectangle($im, 0, 0, 599, 199, imagecolorallocate($im, 16, 62, 140));
        imagepng($im, $chemin);
        self::assertSame(AnalyseFichierImpression::OK, BordsFichier::controler($chemin, 300, 100)['etat']);

        $blanc = imagecolorallocate($im, 255, 255, 255);
        for ($y = 20; $y < 180; $y += 8) {
            imagefilledrectangle($im, 1, $y, 5, $y + 3, $blanc);
        }
        imagepng($im, $chemin);
        $bord = BordsFichier::controler($chemin, 300, 100, true);
        unlink($chemin);
        self::assertSame(AnalyseFichierImpression::ATTENTION, $bord['etat']);
        self::assertStringContainsString('4 cm', $bord['valeur']);
    }
}
