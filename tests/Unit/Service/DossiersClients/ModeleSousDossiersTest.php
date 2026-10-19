<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Service\DossiersClients\ModeleSousDossiers;
use PHPUnit\Framework\TestCase;

final class ModeleSousDossiersTest extends TestCase
{
    public function testModeleParDefaut(): void
    {
        $m = new ModeleSousDossiers();

        self::assertSame(['01 Conceptions', '02 Fichiers reçus', '03 BAT envoyés', '04 Prêts à imprimer', '05 Photos de pose', '06 Documents'], array_column($m->entrees(), 'dossier'));
    }

    public function testDossiersExistantsReconnusMalgreLeurNom(): void
    {
        $m = new ModeleSousDossiers();

        self::assertSame('bat', $m->reconnaitre('03 BAT'));
        self::assertSame('bat', $m->reconnaitre('BAT envoyés'));
        self::assertSame('prets', $m->reconnaitre('04 - Prets a imprimer'));
        self::assertSame('photos', $m->reconnaitre('Photos de pose'));
        self::assertNull($m->reconnaitre('Archives 2024'));
    }

    public function testLectureDuReglage(): void
    {
        $l = ModeleSousDossiers::lire([
            ['nom' => '01 Maquettes', 'couleur' => '#ff0000'],
            ['nom' => 'mauvais/nom'],
            ['nom' => 'Sans couleur', 'couleur' => 'rouge'],
        ]);

        self::assertCount(2, $l);
        self::assertSame('Maquettes', $l[0]['nom']);
        self::assertSame('#FF0000', $l[0]['couleur']);
        self::assertSame('#6B7280', $l[1]['couleur']);
    }
}
