<?php

namespace App\Tests\Unit\Service\Sauvegarde;

use App\Service\Sauvegarde\Chiffrement;
use App\Service\Sauvegarde\HorsSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChiffrementTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/si-chiffre-'.bin2hex(random_bytes(4));
        mkdir($this->dossier);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dossier.'/{,.}*', \GLOB_BRACE | \GLOB_NOSORT) ?: []);
        @rmdir($this->dossier);
    }

    public function testAllerRetourSurPlusieursMorceaux(): void
    {
        $cle = Chiffrement::nouvelleCle();
        $clair = random_bytes((1 << 20) * 2 + 123);
        file_put_contents($this->dossier.'/a', $clair);
        Chiffrement::chiffrer($this->dossier.'/a', $this->dossier.'/a.sihs', $cle);
        self::assertStringNotContainsString(substr($clair, 0, 64), (string) file_get_contents($this->dossier.'/a.sihs'));

        Chiffrement::dechiffrer($this->dossier.'/a.sihs', $this->dossier.'/b', $cle);
        self::assertSame($clair, file_get_contents($this->dossier.'/b'));
    }

    public function testFichierVide(): void
    {
        $cle = Chiffrement::nouvelleCle();
        touch($this->dossier.'/vide');
        Chiffrement::chiffrer($this->dossier.'/vide', $this->dossier.'/vide.sihs', $cle);
        Chiffrement::dechiffrer($this->dossier.'/vide.sihs', $this->dossier.'/vide2', $cle);
        self::assertSame('', file_get_contents($this->dossier.'/vide2'));
    }

    /** @return iterable<string, array{callable(string): string, bool}> */
    public static function alterations(): iterable
    {
        yield 'mauvaise clé' => [static fn (string $c): string => $c, true];
        yield 'un octet modifié' => [static fn (string $c): string => substr_replace($c, \chr(\ord($c[500]) ^ 1), 500, 1), false];
        yield 'tronqué' => [static fn (string $c): string => substr($c, 0, -100), false];
        yield 'données ajoutées' => [static fn (string $c): string => $c.'xx', false];
    }

    #[DataProvider('alterations')]
    public function testCopieAbimeeRefusee(callable $alterer, bool $autreCle): void
    {
        $cle = Chiffrement::nouvelleCle();
        file_put_contents($this->dossier.'/a', random_bytes(5000));
        Chiffrement::chiffrer($this->dossier.'/a', $this->dossier.'/a.sihs', $cle);
        file_put_contents($this->dossier.'/a.sihs', $alterer((string) file_get_contents($this->dossier.'/a.sihs')));
        try {
            Chiffrement::dechiffrer($this->dossier.'/a.sihs', $this->dossier.'/b', $autreCle ? Chiffrement::nouvelleCle() : $cle);
            self::fail('accepté');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->dossier.'/b', 'rien de partiel ne reste');
        }
    }

    public function testLeSiteNeGardeQueLesPlusRecentes(): void
    {
        for ($j = 1; $j <= 16; ++$j) {
            touch(\sprintf('%s/202610%02d-020000-base.sihs', $this->dossier, $j));
        }
        for ($j = 1; $j <= 6; ++$j) {
            touch(\sprintf('%s/202610%02d-020000-fichiers.sihs', $this->dossier, $j));
        }
        touch($this->dossier.'/autre.txt');
        $supprimes = HorsSite::tournerSite($this->dossier);
        self::assertCount(2 + 2, $supprimes);
        self::assertFileDoesNotExist($this->dossier.'/20261001-020000-base.sihs');
        self::assertFileExists($this->dossier.'/20261016-020000-base.sihs');
        self::assertFileExists($this->dossier.'/20261003-020000-fichiers.sihs');
        self::assertFileExists($this->dossier.'/autre.txt', 'le site ne touche qu\'aux copies');
    }
}
