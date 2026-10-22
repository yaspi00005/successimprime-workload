<?php

namespace App\Tests\Unit\Security;

use App\Security\EntetesSecurite;
use App\Security\FichierEnvoye;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fichiers envoyés par le personnel (chat, pré-presse) : l'extension vient
 * du contenu, dans une liste fermée, jamais du nom choisi par
 * l'utilisateur ; servis avec un type lu dans le contenu, sans rien qui
 * s'exécute.
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

    private function fichier(string $contenu): string
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'servi');
        file_put_contents($chemin, $contenu);
        $this->fichiers[] = $chemin;

        return $chemin;
    }

    public function testPageOuSvgDeguisesSontTelechargesJamaisOuverts(): void
    {
        foreach ([
            '<!doctype html><html><body><script>alert(1)</script></body></html>',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            '<?php echo 1;',
        ] as $contenu) {
            $reponse = FichierEnvoye::reponse($this->fichier($contenu), 'photo.jpg');
            self::assertSame('application/octet-stream', $reponse->headers->get('Content-Type'), $contenu);
            self::assertStringStartsWith('attachment', (string) $reponse->headers->get('Content-Disposition'));
            self::assertSame('nosniff', $reponse->headers->get('X-Content-Type-Options'));
            self::assertSame(EntetesSecurite::POLITIQUE_FICHIER, $reponse->headers->get('Content-Security-Policy'));
        }
    }

    public function testImageEtPdfSOuvrentLeResteSeTelecharge(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $image = FichierEnvoye::reponse($this->fichier($png), 'Bâche hôtel.png');
        self::assertSame('image/png', $image->headers->get('Content-Type'));
        self::assertStringStartsWith('inline', (string) $image->headers->get('Content-Disposition'));
        self::assertStringContainsString('sandbox', (string) $image->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('private', (string) $image->headers->get('Cache-Control'));
        // Téléchargement demandé : même une image part en pièce jointe.
        self::assertStringStartsWith('attachment', (string) FichierEnvoye::reponse($this->fichier($png), 'a.png', true)->headers->get('Content-Disposition'));

        $pdf = FichierEnvoye::reponse($this->fichier("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"), 'devis.pdf');
        self::assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        self::assertSame("frame-ancestors 'self'", $pdf->headers->get('Content-Security-Policy'), 'le lecteur PDF refuse « sandbox »');

        $texte = FichierEnvoye::reponse($this->fichier('Bonjour, la bâche est prête.'), 'note.txt');
        self::assertSame('text/plain', $texte->headers->get('Content-Type'));
        self::assertStringStartsWith('attachment', (string) $texte->headers->get('Content-Disposition'));
    }

    public function testNomProposeSansCheminNiCaractereDeControle(): void
    {
        self::assertSame('Bâche 3x2 - recto - v2.pdf', FichierEnvoye::nomTelechargement('Bâche 3x2 / recto \\ v2.pdf', '/x/y.pdf'));
        self::assertSame('y.pdf', FichierEnvoye::nomTelechargement("\n\t", '/x/y.pdf'));
        self::assertSame('y.pdf', FichierEnvoye::nomTelechargement(null, '/x/y.pdf'));
        // Un nom avec « / » ne fait plus échouer la réponse.
        self::assertStringContainsString('filename=', (string) FichierEnvoye::reponse($this->fichier('a'), 'Roll-up 85/200.txt')->headers->get('Content-Disposition'));
    }
}
