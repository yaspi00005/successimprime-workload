<?php

namespace App\Tests\Unit\Service\Site;

use App\Controller\SiteDemandeController;
use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\DemandeDevis;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Service\Site\DemandesSite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DemandesSiteTest extends TestCase
{
    /** @var list<string> */
    private array $temporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaires as $f) {
            @unlink($f);
        }
    }

    public function testJamaisDeMessageVersUneFicheDeDemonstration(): void
    {
        self::assertTrue(DemandesSite::estDeDemo((new Clients())->setCode('DEMO-0122')));
        self::assertTrue(DemandesSite::estDeDemo((new Clients())->setCode('CL-DEMO0')));
        self::assertFalse(DemandesSite::estDeDemo((new Clients())->setCode('CLI-000042')));
        self::assertFalse(DemandesSite::estDeDemo(null), 'numéro inconnu : un vrai visiteur');
    }

    public function testAccuseSansMontant(): void
    {
        $devis = DemandeDevis::nouvelle('devis', 'Awa', '22376123456', new \DateTimeImmutable())->setDesignation('Bâche 300 × 100')->setQuantite(2);
        $devis->setNumero('W-DD-0007');
        $message = DemandeDevis::nouvelle('contact', 'Awa', '22376123456', new \DateTimeImmutable());
        $message->setNumero('W-DD-0008');

        self::assertSame('Succès Imprim : nous avons bien reçu votre demande de devis W-DD-0007. Nous vous répondons pendant nos heures d\'ouverture.', DemandesSite::texteAccuse($devis));
        self::assertStringContainsString('votre message W-DD-0008', DemandesSite::texteAccuse($message));
        foreach ([$devis, $message] as $d) {
            self::assertDoesNotMatchRegularExpression('/F ?CFA|\bF\b|prix|montant|Awa/iu', DemandesSite::texteAccuse($d), 'ni prix ni nom');
        }
    }

    public function testFichierControleCommeMesFichiers(): void
    {
        DemandesSite::verifierFichier($this->fichier('affiche.png', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64)));
        DemandesSite::verifierFichier($this->fichier('maquette.pdf', "%PDF-1.7\n".str_repeat('x', 64)));

        foreach ([
            ['virus.exe', 'MZ'.str_repeat("\0", 64), 'Format non accepté'],
            ['faux.png', '<?php echo 1;', 'pas un vrai PNG'],
            ['vide.pdf', '', 'vide'],
        ] as [$nom, $contenu, $message]) {
            try {
                DemandesSite::verifierFichier($this->fichier($nom, $contenu));
                self::fail($nom.' devait être refusé');
            } catch (\DomainException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $nom);
            }
        }
    }

    public function testFichierTropLourdPourLeServeur(): void
    {
        $f = new UploadedFile(__FILE__, 'gros.pdf', null, \UPLOAD_ERR_INI_SIZE, true);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/dépasse \d+ Mo : envoyez-le par WhatsApp/');
        DemandesSite::verifierFichier($f);
    }

    public function testTailleMaxNeDepassePasLeServeur(): void
    {
        self::assertLessThanOrEqual(DemandesSite::FICHIER_MAX, DemandesSite::tailleMaxFichier());
        self::assertLessThanOrEqual(UploadedFile::getMaxFilesize(), DemandesSite::tailleMaxFichier());
    }

    public function testCatalogueDuFormulaireSansDemoNiPrix(): void
    {
        $grand = (new CategorieProduit())->setNom('Grand format (démo)');
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche imprimée (démo)')->setCategorieProduit($grand))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(3500)->setPrixB2B(3000);
        (new \ReflectionProperty($bache, 'id'))->setValue($bache, 900001);

        $groupes = SiteDemandeController::groupes([$bache]);
        self::assertSame('Grand format', $groupes[0]['nom']);
        self::assertStringNotContainsString('démo', $groupes[0]['produits'][0]['nom']);

        $fiche = SiteDemandeController::fiches([$bache])[900001];
        self::assertTrue($fiche['taille']);
        self::assertSame(['taille', 'largeur', 'hauteur', 'min', 'finitions'], array_keys($fiche), 'aucun prix envoyé à la page');
    }

    private function fichier(string $nom, string $contenu): UploadedFile
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'demande');
        file_put_contents($chemin, $contenu);
        $this->temporaires[] = $chemin;

        return new UploadedFile($chemin, $nom, null, null, true);
    }
}
