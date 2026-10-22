<?php

namespace App\Tests\Unit\Service;

use App\Command\DeplacerFichiersCommand;
use App\Service\FichiersPrives;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Fichiers privés rangés hors de public/ (audit D3, docs/securite.md) :
 * où ils se lisent, et le rangement des anciens (app:fichiers:deplacer).
 */
final class FichiersPrivesTest extends TestCase
{
    private string $projet;

    protected function setUp(): void
    {
        $this->projet = sys_get_temp_dir().'/si-fichiers-prives-'.bin2hex(random_bytes(4));
        mkdir($this->projet.'/public/uploads', 0777, true);
        mkdir($this->projet.'/var', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->projet));
    }

    private function poser(string $relatif, string $contenu): void
    {
        $chemin = $this->projet.'/'.$relatif;
        if (!is_dir(\dirname($chemin))) {
            mkdir(\dirname($chemin), 0777, true);
        }
        file_put_contents($chemin, $contenu);
    }

    public function testCheminsAcceptesSeulementDansLesDossiersPrives(): void
    {
        self::assertSame('uploads/prepresse/commande_3/detail_4/a.pdf', FichiersPrives::normaliser('/uploads/prepresse/commande_3/detail_4/a.pdf'));
        self::assertSame('uploads/chat/x.jpg', FichiersPrives::normaliser('uploads\\chat\\x.jpg'));
        foreach ([
            'uploads/site/photo.webp', 'uploads/tv/affiche.png', 'uploads/inconnu/a', 'uploads/chat/', 'uploads/chat/../../.env.local',
            'uploads/chat/./x', 'images/logo.png', '../var/uploads/chat/x', "uploads/chat/x\0.jpg", 'uploads/chat//x', '',
        ] as $refuse) {
            self::assertNull(FichiersPrives::normaliser($refuse), $refuse);
        }
    }

    public function testLuSousVarPuisALAncienEndroit(): void
    {
        $fichiers = new FichiersPrives($this->projet);
        $this->poser('public/uploads/etiquettes/LOT-1/ETQ-000001.png', 'ancien');
        self::assertSame(realpath($this->projet.'/public/uploads/etiquettes/LOT-1/ETQ-000001.png'), $fichiers->chemin('uploads/etiquettes/LOT-1/ETQ-000001.png'), 'machine pas encore passée par app:fichiers:deplacer');

        $this->poser('var/uploads/etiquettes/LOT-1/ETQ-000001.png', 'nouveau');
        self::assertSame(realpath($this->projet.'/var/uploads/etiquettes/LOT-1/ETQ-000001.png'), $fichiers->chemin('uploads/etiquettes/LOT-1/ETQ-000001.png'));
        self::assertSame($this->projet.'/var/uploads/etiquettes/LOT-2/ETQ.png', $fichiers->cible('uploads/etiquettes/LOT-2/ETQ.png'));

        // Jamais en dehors du dossier du type, même par un lien.
        $this->poser('var/uploads/chat/a.txt', 'a');
        symlink($this->projet.'/public/uploads/etiquettes/LOT-1/ETQ-000001.png', $this->projet.'/var/uploads/chat/lien.png');
        self::assertNull($fichiers->cheminDans('chat', 'lien.png'));
        self::assertNull($fichiers->cheminDans('chat', '../etiquettes/LOT-1/ETQ-000001.png'));
        self::assertNull($fichiers->cheminDans('chat', ''));
        self::assertNull($fichiers->chemin(null));
        self::assertNotNull($fichiers->cheminDans('chat', 'a.txt'));
        self::assertNull($fichiers->chemin('uploads/site/a.txt'));

        $this->expectException(\InvalidArgumentException::class);
        $fichiers->dossier('site');
    }

    public function testEssaiPuisRangementIdempotent(): void
    {
        $fichiers = new FichiersPrives($this->projet);
        $this->poser('public/uploads/chat/chat_1.jpg', 'photo');
        $this->poser('public/uploads/prepresse/commande_3/detail_4/bache-v2.pdf', '%PDF-1.4 bâche');
        $this->poser('public/uploads/etiquettes/LOT-1/ETQ-000001.png', 'qr');
        $this->poser('public/uploads/etiquettes/LOT-1/LOT-1.zip', 'zip');
        $this->poser('public/uploads/reclamations/justificatif_1.pdf', 'reçu');
        // Déjà copié à l'identique : seulement retiré de public/.
        $this->poser('public/uploads/chat/chat_2.png', 'meme');
        $this->poser('var/uploads/chat/chat_2.png', 'meme');
        // Publics par nature ou inconnus : jamais touchés.
        $this->poser('public/uploads/site/accueil.webp', 'site');
        $this->poser('public/uploads/tv/01_affiche.png', 'tv');
        $this->poser('public/uploads/divers/a.txt', 'a');
        $this->poser('public/uploads/.htaccess', 'Require all denied');

        self::assertSame(6, $fichiers->restantsSousPublic());
        $essai = $fichiers->deplacer(false);
        self::assertSame(['fichiers' => 2, 'deplaces' => 1, 'identiques' => 1, 'conflits' => 0, 'erreurs' => 0, 'octets' => 5], $essai['dossiers']['chat']);
        self::assertSame(2, $essai['dossiers']['etiquettes']['deplaces']);
        self::assertSame(['site' => 1, 'tv' => 1], $essai['publics']);
        self::assertSame(['divers' => 1], $essai['inconnus']);
        self::assertFileExists($this->projet.'/public/uploads/chat/chat_1.jpg', 'l\'essai ne déplace rien');
        self::assertFileDoesNotExist($this->projet.'/var/uploads/chat/chat_1.jpg');

        $fait = $fichiers->deplacer(true);
        self::assertSame(1, $fait['dossiers']['prepresse']['deplaces']);
        self::assertSame('%PDF-1.4 bâche', file_get_contents($this->projet.'/var/uploads/prepresse/commande_3/detail_4/bache-v2.pdf'));
        self::assertSame('photo', file_get_contents($this->projet.'/var/uploads/chat/chat_1.jpg'));
        self::assertFileDoesNotExist($this->projet.'/public/uploads/chat/chat_2.png');
        self::assertDirectoryDoesNotExist($this->projet.'/public/uploads/prepresse', 'dossiers vidés retirés');
        self::assertDirectoryDoesNotExist($this->projet.'/public/uploads/chat');
        self::assertFileExists($this->projet.'/public/uploads/site/accueil.webp');
        self::assertFileExists($this->projet.'/public/uploads/tv/01_affiche.png');
        self::assertFileExists($this->projet.'/public/uploads/divers/a.txt');
        self::assertFileExists($this->projet.'/public/uploads/.htaccess');
        self::assertSame(0, $fichiers->restantsSousPublic());
        self::assertNotNull($fichiers->chemin('uploads/etiquettes/LOT-1/LOT-1.zip'));

        // Relancer ne change rien.
        $encore = $fichiers->deplacer(true);
        foreach ($encore['dossiers'] as $dossier => $n) {
            self::assertSame(0, $n['fichiers'], $dossier);
        }
    }

    public function testConflitLaisseLesDeuxFichiers(): void
    {
        $fichiers = new FichiersPrives($this->projet);
        $this->poser('public/uploads/employes/photo.jpg', 'ancienne');
        $this->poser('var/uploads/employes/photo.jpg', 'autre');

        $bilan = $fichiers->deplacer(true);
        self::assertSame(1, $bilan['dossiers']['employes']['conflits']);
        self::assertSame('ancienne', file_get_contents($this->projet.'/public/uploads/employes/photo.jpg'));
        self::assertSame('autre', file_get_contents($this->projet.'/var/uploads/employes/photo.jpg'));
    }

    public function testLaCommandeAfficheDesNombresJamaisLesNoms(): void
    {
        $this->poser('public/uploads/chat/devis-hotel-niger-confidentiel.pdf', 'contenu secret du client');
        $commande = new CommandTester(new DeplacerFichiersCommand(new FichiersPrives($this->projet)));

        self::assertSame(Command::SUCCESS, $commande->execute([]));
        $sortie = $commande->getDisplay();
        self::assertStringContainsString('app:fichiers:deplacer --executer', $sortie);
        self::assertStringNotContainsString('hotel-niger', $sortie);
        self::assertStringNotContainsString('secret', $sortie);
        self::assertFileExists($this->projet.'/public/uploads/chat/devis-hotel-niger-confidentiel.pdf');

        self::assertSame(Command::SUCCESS, $commande->execute(['--executer' => true]));
        self::assertStringNotContainsString('hotel-niger', $commande->getDisplay());
        self::assertFileExists($this->projet.'/var/uploads/chat/devis-hotel-niger-confidentiel.pdf');

        self::assertSame(Command::SUCCESS, $commande->execute(['--executer' => true]));
        self::assertStringContainsString('tout est déjà rangé', $commande->getDisplay());
    }
}
