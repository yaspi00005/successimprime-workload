<?php

namespace App\Tests\Unit\Security;

use App\Security\EssaisCodeFacture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/** /f/{code} : une connexion qui essaie des codes au hasard est arrêtée. */
final class EssaisCodeFactureTest extends TestCase
{
    public function testCodesFauxBloquentLaConnexionSeulement(): void
    {
        $essais = new EssaisCodeFacture(new RateLimiterFactory(['id' => 'facture_suivi_ip', 'policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'], new InMemoryStorage()));

        // Ouvrir de vraies factures ne compte pas.
        for ($i = 0; $i < 50; ++$i) {
            self::assertFalse($essais->bloque('41.73.1.1'));
        }
        for ($i = 0; $i < 19; ++$i) {
            $essais->noterCodeFaux('41.73.1.1');
        }
        self::assertFalse($essais->bloque('41.73.1.1'));
        $essais->noterCodeFaux('41.73.1.1');

        self::assertTrue($essais->bloque('41.73.1.1'));
        self::assertFalse($essais->bloque('41.73.1.2'));
    }
}
