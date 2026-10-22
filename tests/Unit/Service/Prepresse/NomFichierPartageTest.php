<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Service\Prepresse\NomFichierPartage;
use PHPUnit\Framework\TestCase;

/** Noms des fichiers posés dans le dossier partagé (import chaque minute). */
final class NomFichierPartageTest extends TestCase
{
    public function testNumeroDeCommandeOuDeLigne(): void
    {
        $l = NomFichierPartage::lire('CMD-000378_5,5x7.pdf');
        self::assertSame('commande', $l['type']);
        self::assertSame('CMD-000378', $l['numero']);
        self::assertSame('5,5x7.pdf', $l['reste']);

        $l = NomFichierPartage::lire('cmd-000378-10-2026 affiche.pdf');
        self::assertSame('commande', $l['type']);
        self::assertSame('CMD-000378-10-2026', $l['numero']);

        $l = NomFichierPartage::lire('123_affiche.pdf');
        self::assertSame('ligne', $l['type']);
        self::assertSame(123, $l['ligne']);

        self::assertNull(NomFichierPartage::lire('logo Fofana.pdf')['type']);
    }

    public function testFichiersIgnores(): void
    {
        foreach (['.DS_Store', '~$devis.docx', 'Thumbs.db', 'desktop.ini', 'bache.pdf.part', 'video.crdownload', 'gros.tmp', 'x.!ut'] as $nom) {
            self::assertTrue(NomFichierPartage::estIgnore($nom), $nom);
        }
        self::assertFalse(NomFichierPartage::estIgnore('CMD-000378_5,5x7.pdf'));
    }

    public function testCopieEnCours(): void
    {
        $t = 1_000_000;
        self::assertTrue(NomFichierPartage::enCoursDeCopie(100, 200, $t - 300, $t), 'la taille bouge');
        self::assertTrue(NomFichierPartage::enCoursDeCopie(100, 100, $t - 10, $t), 'modifié il y a 10 s');
        self::assertFalse(NomFichierPartage::enCoursDeCopie(100, 100, $t - 31, $t));
    }

    public function testNomSurSansChemin(): void
    {
        self::assertTrue(NomFichierPartage::nomSur('CMD-000378_5,5x7.pdf'));
        self::assertFalse(NomFichierPartage::nomSur('../config/secrets.php'));
        self::assertFalse(NomFichierPartage::nomSur('sous/dossier.pdf'));
        self::assertFalse(NomFichierPartage::nomSur('..'));
        self::assertFalse(NomFichierPartage::nomSur(''));
    }

    public function testNomRange(): void
    {
        self::assertSame('CMD-000378_5,5x7cm.pdf', NomFichierPartage::nomRange('CMD-000378', '5,5 × 7 cm', 'n importe quoi.PDF'));
    }
}
