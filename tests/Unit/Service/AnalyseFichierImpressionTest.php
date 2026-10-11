<?php

namespace App\Tests\Unit\Service;

use App\Service\AnalyseFichierImpression;
use PHPUnit\Framework\TestCase;

final class AnalyseFichierImpressionTest extends TestCase
{
    public function testResolutionSelonLaTaille(): void
    {
        self::assertSame(300, AnalyseFichierImpression::resolutionConseillee(21));
        self::assertSame(150, AnalyseFichierImpression::resolutionConseillee(100));
        self::assertSame(72, AnalyseFichierImpression::resolutionConseillee(200));
    }

    public function testRollUpA118Dpi(): void
    {
        $controle = AnalyseFichierImpression::controleResolution(3950, 9300, 85, 200);

        self::assertSame(AnalyseFichierImpression::OK, $controle['etat']);
        self::assertStringStartsWith('118 dpi', $controle['valeur']);
    }

    public function testFichierTropPetit(): void
    {
        self::assertSame(AnalyseFichierImpression::ERREUR, AnalyseFichierImpression::controleResolution(800, 1880, 85, 200)['etat']);
        self::assertSame(AnalyseFichierImpression::ATTENTION, AnalyseFichierImpression::controleResolution(1900, 2700, 21, 29.7)['etat']);
    }

    public function testProportions(): void
    {
        self::assertSame(AnalyseFichierImpression::OK, AnalyseFichierImpression::controleProportions(850, 2000, 85, 200)['etat']);
        self::assertSame(AnalyseFichierImpression::ATTENTION, AnalyseFichierImpression::controleProportions(2000, 850, 85, 200)['etat'], 'Fichier tourné.');
        self::assertSame(AnalyseFichierImpression::ERREUR, AnalyseFichierImpression::controleProportions(1000, 1000, 85, 200)['etat']);
    }

    public function testPdfCmjnAvecPolicesIncorporees(): void
    {
        $chemin = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($chemin, "%PDF-1.4\n<< /Type /Page /MediaBox [0 0 595.28 841.89] >>\n/ColorSpace /DeviceCMYK\n<< /Type /Font /FontDescriptor 5 0 R >>\n<< /FontFile2 6 0 R >>");
        $controles = (new AnalyseFichierImpression())->analyser($chemin, 21, 29.7);
        unlink($chemin);

        $parLibelle = array_column($controles, 'etat', 'libelle');
        self::assertSame(AnalyseFichierImpression::OK, $parLibelle['Mode colorimétrique']);
        self::assertSame(AnalyseFichierImpression::OK, $parLibelle['Polices']);
        self::assertSame(AnalyseFichierImpression::OK, $parLibelle['Ratio des dimensions']);
    }
}
