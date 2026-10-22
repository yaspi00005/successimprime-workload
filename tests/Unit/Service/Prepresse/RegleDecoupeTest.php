<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Service\Prepresse\RegleDecoupe;
use PHPUnit\Framework\TestCase;

final class RegleDecoupeTest extends TestCase
{
    public function testFinitionsDeDecoupe(): void
    {
        self::assertTrue(RegleDecoupe::estFinitionDecoupe('Découpe à la forme'));
        self::assertTrue(RegleDecoupe::estFinitionDecoupe('DECOUPE'));
        self::assertTrue(RegleDecoupe::estFinitionDecoupe('Mise à la forme : contour'));
        self::assertTrue(RegleDecoupe::estFinitionDecoupe('Plotter'));
        self::assertFalse(RegleDecoupe::estFinitionDecoupe('Lamination'));
        self::assertFalse(RegleDecoupe::estFinitionDecoupe('Œillets tous les 50 cm'));
        self::assertFalse(RegleDecoupe::estFinitionDecoupe(null));
    }

    public function testNomsDeFichiersDeDecoupe(): void
    {
        self::assertTrue(RegleDecoupe::estNomFichierDecoupe('decoupe_5x7.pdf'));
        self::assertTrue(RegleDecoupe::estNomFichierDecoupe('Étiquette DÉCOUPE.ai'));
        self::assertTrue(RegleDecoupe::estNomFichierDecoupe('CutContour.eps'));
        self::assertTrue(RegleDecoupe::estNomFichierDecoupe('logo-cut.pdf'));
        self::assertTrue(RegleDecoupe::estNomFichierDecoupe('CMD-000378_contour.svg'));
        self::assertFalse(RegleDecoupe::estNomFichierDecoupe('biscuit.pdf'));
        self::assertFalse(RegleDecoupe::estNomFichierDecoupe('affiche_55x70.pdf'));
        // L'extension ne compte pas.
        self::assertFalse(RegleDecoupe::estNomFichierDecoupe('logo.cut'));
    }

    public function testRequisDapresLesFinitionsOuLaCase(): void
    {
        self::assertTrue(RegleDecoupe::requis(['Lamination', 'Découpe à la forme'], null));
        self::assertFalse(RegleDecoupe::requis(['Lamination'], null));
        self::assertFalse(RegleDecoupe::requis([], null));
        // La case de la prépresse l'emporte, dans les deux sens.
        self::assertTrue(RegleDecoupe::requis(['Lamination'], true));
        self::assertFalse(RegleDecoupe::requis(['Découpe à la forme'], false));
    }

    public function testEtat(): void
    {
        self::assertSame(RegleDecoupe::PRESENT, RegleDecoupe::etat(true, true, false));
        self::assertSame(RegleDecoupe::PRESENT, RegleDecoupe::etat(false, true, false));
        self::assertSame(RegleDecoupe::NON_REQUIS, RegleDecoupe::etat(false, false, false));
        self::assertSame(RegleDecoupe::MANQUANT, RegleDecoupe::etat(true, false, false));
        self::assertSame(RegleDecoupe::SANS_FICHIER, RegleDecoupe::etat(true, false, true));
    }
}
