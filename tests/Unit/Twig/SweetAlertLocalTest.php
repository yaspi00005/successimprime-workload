<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Les boîtes de confirmation (SweetAlert2) viennent du dossier public :
 * sans Internet à l'atelier, « Enregistrer le paiement » doit encore
 * ouvrir sa confirmation et enregistrer l'encaissement.
 */
final class SweetAlertLocalTest extends TestCase
{
    private const FICHIER = 'vendor/sweetalert2/sweetalert2.all.min.js';

    public function testLaCopieLocaleExisteAvecSaLicence(): void
    {
        $dossier = \dirname(__DIR__, 3).'/public/';

        self::assertFileExists($dossier.self::FICHIER);
        self::assertFileExists($dossier.'vendor/sweetalert2/LICENSE');
        self::assertStringContainsString('sweetalert2 v11', (string) file_get_contents($dossier.self::FICHIER, length: 200));
    }

    public function testAucunGabaritNeLaChargeDepuisUnCdn(): void
    {
        $gabarits = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/templates', \FilesystemIterator::SKIP_DOTS));
        $fautifs = [];
        $locaux = 0;

        foreach ($gabarits as $fichier) {
            $contenu = (string) file_get_contents((string) $fichier);
            if (preg_match('#https?://[^"\']*sweetalert#i', $contenu) || str_contains($contenu, "asset('assets/plugins/sweet-alert")) {
                $fautifs[] = $fichier->getFilename();
            }
            $locaux += substr_count($contenu, "asset('".self::FICHIER."')");
        }

        self::assertSame([], $fautifs);
        self::assertGreaterThan(20, $locaux, 'Les écrans qui confirment par SweetAlert chargent la copie locale.');
    }

    public function testLePaiementDUneCommandeChargeLaCopieLocale(): void
    {
        $gabarit = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/commandes/paiement.html.twig');

        self::assertStringContainsString("asset('".self::FICHIER."')", $gabarit);
    }
}
