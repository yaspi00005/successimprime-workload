<?php

namespace App\Tests\Unit\Controller;

use App\Controller\UtilisateursController;
use PHPUnit\Framework\TestCase;

final class UtilisateursControllerTest extends TestCase
{
    public function testLIdentifiantSeCreeAvecLePrenomEtLeNom(): void
    {
        self::assertSame('mtoure', UtilisateursController::identifiant('Mariam', 'Touré'));
        self::assertSame('akeita', UtilisateursController::identifiant(' Anne Marie ', 'KEÏTA'));
        self::assertSame('bcoulibaly', UtilisateursController::identifiant('Bakary', 'Coulibaly'));
        self::assertSame('ssissokodi', UtilisateursController::identifiant('Seydou', 'Sissoko-Diarra'));
        self::assertSame('seydou', UtilisateursController::identifiant('Seydou', ''));
    }

    public function testLeNumeroWhatsAppPrendLIndicatifDuMali(): void
    {
        self::assertSame('22376005555', UtilisateursController::numeroWhatsApp('76 00 55 55'));
        self::assertSame('22376005555', UtilisateursController::numeroWhatsApp('+223 76 00 55 55'));
    }
}
