<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Service\Prepresse\DimensionsFichier;
use PHPUnit\Framework\TestCase;

/** Lecture des dimensions des fichiers déposés : résolution JPEG et PNG, page PDF. */
final class DimensionsFichierTest extends TestCase
{
    public function testResolutionJpegJfif(): void
    {
        // En-tête JFIF : unité 1 (points par pouce), 300 × 300.
        $octets = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x02\x01\x01\x2C\x01\x2C\x00\x00";
        self::assertSame(300.0, DimensionsFichier::dpiJpeg($octets));
        // Unité 2 : points par centimètre (118 ppcm ≈ 300 dpi).
        $octets = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x02\x02\x00\x76\x00\x76\x00\x00";
        self::assertEqualsWithDelta(299.7, DimensionsFichier::dpiJpeg($octets), 0.1);
        self::assertNull(DimensionsFichier::dpiJpeg("\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x02\x00\x00\x01\x00\x01\x00\x00"));
    }

    public function testResolutionPng(): void
    {
        // pHYs : 11811 points par mètre = 300 dpi.
        $png = "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.str_repeat("\x00", 13).'CRC0'.pack('N', 9).'pHYs'.pack('NN', 11811, 11811)."\x01".'CRC0';
        self::assertEqualsWithDelta(300.0, DimensionsFichier::dpiPng($png), 0.1);
    }

    public function testPagePdfTrimBoxPuisMediaBox(): void
    {
        self::assertSame([75.0, 80.0], DimensionsFichier::pagePdf('<< /Type /Page /MediaBox [0 0 229.61 243.78] /TrimBox [8.5 8.5 221.1 235.28] >>'));
        self::assertSame([210.0, 297.0], DimensionsFichier::pagePdf('<< /Type /Page /MediaBox [0 0 595.276 841.89] >>'));
        self::assertNull(DimensionsFichier::pagePdf('pas un pdf'));
    }
}
