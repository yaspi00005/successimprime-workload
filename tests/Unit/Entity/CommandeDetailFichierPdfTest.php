<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CommandeDetailFichier;
use PHPUnit\Framework\TestCase;

final class CommandeDetailFichierPdfTest extends TestCase
{
    public function testPdfReconnuParTypeOuParExtension(): void
    {
        self::assertTrue((new CommandeDetailFichier())->setTypeMime('application/pdf')->estPdf());
        self::assertTrue((new CommandeDetailFichier())->setTypeMime('application/x-pdf')->estPdf());
        self::assertTrue((new CommandeDetailFichier())->setTypeMime('application/octet-stream')->setNomOriginal('Bâche FINAL.PDF')->estPdf());
        self::assertFalse((new CommandeDetailFichier())->setTypeMime('image/png')->setNomOriginal('logo.png')->estPdf());
    }
}
