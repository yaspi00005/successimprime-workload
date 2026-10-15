<?php

namespace App\Tests\Unit\Security;

use App\Security\FichierEnvoye;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers rangés sous public/ (chat, pré-presse) : l'extension vient du
 * contenu, dans une liste fermée, jamais du nom choisi par l'utilisateur.
 */
final class FichierEnvoyeTest extends TestCase
{
    /** @var list<string> */
    private array $fichiers = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiers as $f) {
            @unlink($f);
        }
    }

    private function envoi(string $contenu, string $nom): UploadedFile
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'envoi');
        file_put_contents($chemin, $contenu);
        $this->fichiers[] = $chemin;

        return new UploadedFile($chemin, $nom, null, null, true);
    }

    public function testChatRefuseScriptsEtPagesQuelQueSoitLeNom(): void
    {
        foreach ([
            ['<?php system($_GET["c"]);', 'photo.jpg'],
            ['<?php echo 1;', 'shell.php'],
            ['<!doctype html><html><body><script>alert(1)</script></body></html>', 'facture.pdf'],
            ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.png'],
        ] as [$contenu, $nom]) {
            self::assertNull(FichierEnvoye::extensionPour($this->envoi($contenu, $nom), FichierEnvoye::TYPES_CHAT, FichierEnvoye::TAILLE_MAX_CHAT), $nom);
        }
    }

    public function testChatGardeImagesPdfEtTexteAvecLeurVraieExtension(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        self::assertSame('png', FichierEnvoye::extensionPour($this->envoi($png, 'capture.php'), FichierEnvoye::TYPES_CHAT, FichierEnvoye::TAILLE_MAX_CHAT));
        self::assertSame('pdf', FichierEnvoye::extensionPour($this->envoi("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'devis.pdf'), FichierEnvoye::TYPES_CHAT, FichierEnvoye::TAILLE_MAX_CHAT));
        self::assertSame('txt', FichierEnvoye::extensionPour($this->envoi('Bonjour, la bâche est prête.', 'note.html'), FichierEnvoye::TYPES_CHAT, FichierEnvoye::TAILLE_MAX_CHAT));
        // Trop lourd : refusé.
        self::assertNull(FichierEnvoye::extensionPour($this->envoi($png, 'capture.png'), FichierEnvoye::TYPES_CHAT, 10));
    }

    public function testPrePresseNeGardeQueDesExtensionsInoffensives(): void
    {
        foreach (['php', 'PHP5', 'phtml', 'phar', 'html', 'svg', 'js', 'htaccess', '', null, 'tar.gz', '../x', 'pdfpdfpdf'] as $extension) {
            self::assertSame('bin', FichierEnvoye::extensionPublique($extension), (string) $extension);
        }
        foreach (['pdf', 'jpg', 'PNG', 'tif', 'ai', 'eps', 'psd', 'cdr', 'zip'] as $extension) {
            self::assertSame(strtolower($extension), FichierEnvoye::extensionPublique($extension));
        }
    }
}
