<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\EnvoiRip;
use PHPUnit\Framework\TestCase;

/**
 * Ce que le navigateur peut poser dans le PDF du rouleau : lu dans le
 * fichier, jamais d'après son nom (un fichier de « Mes produits » n'a pas
 * de nom d'origine).
 */
final class EnvoiRipTest extends TestCase
{
    /** @var list<string> */
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $f) {
            @unlink($f);
        }
    }

    public function testTypeLuDansLeContenu(): void
    {
        self::assertSame('pdf', EnvoiRip::typeMontable($this->fichier("%PDF-1.7\n%âãÏÓ\n")));
        self::assertSame('png', EnvoiRip::typeMontable($this->fichier("\x89PNG\r\n\x1A\n\0\0\0\rIHDR")));
        self::assertSame('jpeg', EnvoiRip::typeMontable($this->fichier("\xFF\xD8\xFF\xE0\0\x10JFIF")));
        // Un PDF peut commencer par quelques octets avant l'en-tête.
        self::assertSame('pdf', EnvoiRip::typeMontable($this->fichier("\r\n%PDF-1.4\n")));
    }

    public function testAutresFormatsEnFichiersSepares(): void
    {
        self::assertNull(EnvoiRip::typeMontable($this->fichier('8BPS photoshop')));
        self::assertNull(EnvoiRip::typeMontable($this->fichier('<svg xmlns="http://www.w3.org/2000/svg"></svg>')));
        self::assertNull(EnvoiRip::typeMontable('/chemin/qui/n/existe/pas.pdf'));
    }

    private function fichier(string $contenu): string
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'rip');
        file_put_contents($chemin, $contenu);
        $this->fichiers[] = $chemin;

        return $chemin;
    }
}
