<?php

namespace App\Tests\Unit\Service\Sauvegarde;

use App\Service\Sauvegarde\ArchiveTar;
use PHPUnit\Framework\TestCase;

final class ArchiveTarTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/si-tar-'.bin2hex(random_bytes(4));
        mkdir($this->dossier.'/source/public/uploads/chat/'.str_repeat('tres-long-dossier/', 8), 0777, true);
        file_put_contents($this->dossier.'/source/public/uploads/a.txt', 'bonjour');
        file_put_contents($this->dossier.'/source/public/uploads/vide.txt', '');
        file_put_contents($this->dossier.'/source/public/uploads/chat/binaire.bin', random_bytes(70000));
        file_put_contents($this->dossier.'/source/public/uploads/chat/'.str_repeat('tres-long-dossier/', 8).'photo.jpg', str_repeat('x', 513));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dossier));
    }

    public function testEcritRelitEtRestaureALidentique(): void
    {
        $archive = ArchiveTar::creer($this->dossier.'/f.tar.gz');
        $archive->ajouterDossier($this->dossier.'/source/public/uploads', 'public/uploads');
        $archive->ajouterDossier($this->dossier.'/absent', 'var/absent');
        $contenu = $archive->fermer();

        self::assertSame(4, $contenu['fichiers']);
        self::assertSame(['fichiers' => 4, 'octets' => $contenu['octets']], ArchiveTar::parcourir($this->dossier.'/f.tar.gz'));

        $resultat = ArchiveTar::extraire($this->dossier.'/f.tar.gz', $this->dossier.'/cible', ['public/uploads']);
        self::assertSame(0, $resultat['ignores']);
        foreach (['a.txt', 'vide.txt', 'chat/binaire.bin', 'chat/'.str_repeat('tres-long-dossier/', 8).'photo.jpg'] as $f) {
            self::assertFileEquals($this->dossier.'/source/public/uploads/'.$f, $this->dossier.'/cible/public/uploads/'.$f, $f);
        }
    }

    public function testLisibleParTarStandard(): void
    {
        $archive = ArchiveTar::creer($this->dossier.'/f.tar.gz');
        $archive->ajouterDossier($this->dossier.'/source/public/uploads', 'public/uploads');
        $archive->fermer();

        $noms = [];
        foreach (new \RecursiveIteratorIterator(new \PharData($this->dossier.'/f.tar.gz')) as $f) {
            $noms[] = $f->getFilename();
        }
        sort($noms);
        self::assertSame(['a.txt', 'binaire.bin', 'photo.jpg', 'vide.txt'], $noms);
    }

    public function testCheminDePlusDe255Caracteres(): void
    {
        $nom = 'public/uploads/'.str_repeat('dossier-tres-tres-long/', 12).'f.txt';
        mkdir(\dirname($this->dossier.'/source/'.$nom), 0777, true);
        file_put_contents($this->dossier.'/source/'.$nom, 'contenu');
        $archive = ArchiveTar::creer($this->dossier.'/f.tar.gz');
        $archive->ajouterFichier($this->dossier.'/source/'.$nom, $nom);
        $archive->fermer();

        ArchiveTar::extraire($this->dossier.'/f.tar.gz', $this->dossier.'/cible', ['public/uploads']);
        self::assertSame('contenu', file_get_contents($this->dossier.'/cible/'.$nom));
        exec('tar tzf '.escapeshellarg($this->dossier.'/f.tar.gz').' 2>/dev/null', $liste, $code);
        if ($code === 0) {
            self::assertSame([$nom], $liste, 'GNU tar lit le nom long');
        }
    }

    public function testRefuseLesCheminsHorsDesDossiersPermis(): void
    {
        $permis = ['public/uploads', 'var/pointage'];
        self::assertTrue(ArchiveTar::cheminPermis('public/uploads/a.jpg', $permis));
        self::assertTrue(ArchiveTar::cheminPermis('var/pointage/2026/b.jpg', $permis));
        foreach (['public/index.php', 'public/uploads/../index.php', '/etc/passwd', 'var/pointage/..', 'C:/x', '.env.local', 'public/uploadsX/a', 'public\\uploads\\a'] as $nom) {
            self::assertFalse(ArchiveTar::cheminPermis($nom, $permis), $nom);
        }
    }

    public function testDetecteUneArchiveAbimee(): void
    {
        $archive = ArchiveTar::creer($this->dossier.'/f.tar.gz');
        $archive->ajouterDossier($this->dossier.'/source/public/uploads', 'public/uploads');
        $archive->fermer();
        $donnees = gzdecode((string) file_get_contents($this->dossier.'/f.tar.gz'));
        file_put_contents($this->dossier.'/tronquee.tar.gz', gzencode(substr($donnees, 0, 40000)));

        $this->expectException(\RuntimeException::class);
        ArchiveTar::parcourir($this->dossier.'/tronquee.tar.gz');
    }
}
