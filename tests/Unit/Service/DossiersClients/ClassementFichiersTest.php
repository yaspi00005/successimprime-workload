<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Service\DossiersClients\AnalyseDossiers;
use App\Service\DossiersClients\ClassementFichiers;
use PHPUnit\Framework\TestCase;

/** Garde-fous du classement et du téléchargement : rien hors du dossier. */
final class ClassementFichiersTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/dc-test-'.bin2hex(random_bytes(4));
        mkdir($this->dossier.'/Clients/A/01 Conceptions', 0777, true);
        file_put_contents($this->dossier.'/Clients/A/01 Conceptions/a.ai', 'x');
        file_put_contents($this->dossier.'/secret.txt', 'x');
    }

    protected function tearDown(): void
    {
        @unlink($this->dossier.'/Clients/A/01 Conceptions/a.ai');
        @unlink($this->dossier.'/secret.txt');
        @rmdir($this->dossier.'/Clients/A/01 Conceptions');
        @rmdir($this->dossier.'/Clients/A');
        @rmdir($this->dossier.'/Clients');
        @rmdir($this->dossier);
    }

    public function testCheminDansLeDossier(): void
    {
        self::assertSame(realpath($this->dossier.'/Clients/A/01 Conceptions/a.ai'), ClassementFichiers::dans($this->dossier.'/Clients/A', '01 Conceptions/a.ai'));
    }

    public function testCheminHorsDuDossierRefuse(): void
    {
        $racine = $this->dossier.'/Clients/A';
        self::assertNull(ClassementFichiers::dans($racine, '../../secret.txt'));
        self::assertNull(ClassementFichiers::dans($racine, '01 Conceptions/../../../secret.txt'));
        self::assertNull(ClassementFichiers::dans($racine, $this->dossier.'/secret.txt'));
        self::assertNull(ClassementFichiers::dans($racine, "01 Conceptions/a.ai\0.jpg"));
        self::assertNull(ClassementFichiers::dans($racine, '01 Conceptions'));
        self::assertNull(ClassementFichiers::dans($racine, 'absent.ai'));
    }

    public function testNomPropre(): void
    {
        self::assertSame('photo-facade.jpg', ClassementFichiers::nomPropre('photo:facade', 'JPG'));
        self::assertSame('a-b.pdf', ClassementFichiers::nomPropre('a/b.pdf', 'pdf'));
        self::assertSame('fichier.png', ClassementFichiers::nomPropre(' ... ', 'png'));
        self::assertSame('kakemono.ai', ClassementFichiers::nomPropre('kakemono.ai', 'ai'));
    }

    public function testDimensionsCourtes(): void
    {
        self::assertSame('85 × 200 cm', AnalyseDossiers::dimensionsCourtes('850 × 2 000 mm · taille du produit inconnue'));
        self::assertSame('85 × 55 mm', AnalyseDossiers::dimensionsCourtes('85 × 55 mm · taille du produit inconnue'));
        self::assertSame('1200 × 900 px', AnalyseDossiers::dimensionsCourtes('1200 × 900 px : taille du produit inconnue'));
    }
}
