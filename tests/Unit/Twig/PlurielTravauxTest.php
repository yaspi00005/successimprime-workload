<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * « 4 travaux » et non « 4 travailx » (planning des machines, liste des
 * commandes) : le pluriel de « travail » ne s'obtient pas en ajoutant x.
 */
final class PlurielTravauxTest extends TestCase
{
    public function testAucunGabaritNeFabriqueTravailx(): void
    {
        $gabarits = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/templates', \FilesystemIterator::SKIP_DOTS));
        $fautifs = [];

        foreach ($gabarits as $fichier) {
            if (preg_match('/travail\{\{[^}]*\'x\'/', (string) file_get_contents((string) $fichier))) {
                $fautifs[] = $fichier->getFilename();
            }
        }

        self::assertSame([], $fautifs);
    }
}
