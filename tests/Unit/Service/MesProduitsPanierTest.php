<?php

namespace App\Tests\Unit\Service;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\ProduitClient;
use App\Service\CommandeProduitClient;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\Panier;
use App\Service\ProduitsClient\ControleFichierLie;
use PHPUnit\Framework\TestCase;

/**
 * « Mes produits » et panier (maquettes MP01 à MP06, docs/mes-produits.md) :
 * dimensions saisies, paliers de prix, tailles validées, suspension du lien,
 * contrôle du fichier lié et aiguillage d'une ligne.
 */
final class MesProduitsPanierTest extends TestCase
{
    private static function lien(float $l, float $h, string $nom = 'fichier.pdf'): array
    {
        return ['l' => $l, 'h' => $h, 'nom' => $nom, 'empreinte' => str_repeat('b', 64), 'octets' => 100, 'le' => '2026-10-18 10:00'];
    }

    private static function etiquette(): ProduitClient
    {
        return (new ProduitClient())->setNom('Étiquette logo')->setReference('PC-SOW-01')->setType('Étiquette tissée')
            ->setFormat(0.05, 0.03)->setUniteVente(ProduitClient::UNITE_PIECE)->setPrixUnitaire(45);
    }

    /** @return iterable<string, array{0: string, 1: ?array}> */
    public static function dimensions(): iterable
    {
        yield 'mm' => ['50 × 30 mm', [50.0, 30.0, false]];
        yield 'x sans unité' => ['50x30', [50.0, 30.0, false]];
        yield 'rond' => ['Ø 60 mm', [60.0, 60.0, true]];
        yield 'A6' => ['A6', [105.0, 148.0, false]];
        yield 'cm' => ['8,5 × 5,5 cm', [85.0, 55.0, false]];
        yield 'm' => ['3 × 1 m', [3000.0, 1000.0, false]];
        yield 'vide' => ['', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dimensions')]
    public function testDimensionsSaisiesParLeClient(string $texte, ?array $attendu): void
    {
        self::assertSame($attendu, MesProduits::lireDimensions($texte));
    }

    public function testFamilleSelonLeType(): void
    {
        self::assertSame('Étiquettes', self::etiquette()->getFamille());
        self::assertSame('Cartes', self::etiquette()->setType('Carte de visite')->getFamille());
    }

    public function testPaliersDePrix(): void
    {
        $p = self::etiquette()->setPaliers([['q' => 1000, 'prix' => 32], ['q' => 500, 'prix' => 40], ['q' => 0, 'prix' => 1]]);

        self::assertSame([['q' => 500, 'prix' => 40], ['q' => 1000, 'prix' => 32]], $p->getPaliers());
        self::assertSame(45, $p->prixPour(250));
        self::assertSame(40, $p->prixPour(500));
        self::assertSame(32, $p->prixPour(2000));
    }

    public function testLeLienNeVautQuePourLesTaillesValidees(): void
    {
        $p = self::etiquette()->lierFichier(self::lien(50, 30));

        self::assertTrue($p->estPret());
        self::assertNotNull($p->fichierPourTaille(50.2, 30));
        self::assertNull($p->fichierPourTaille(60, 100));
        self::assertTrue($p->estTailleValidee(50, 30));
        self::assertSame('Ø 60 mm', ProduitClient::libelleTaille(60, 60, true));
        self::assertSame('50 × 30 mm', ProduitClient::libelleTaille(50, 30));
    }

    public function testNouvelleImageSuspendLeLien(): void
    {
        $p = self::etiquette()->lierFichier(self::lien(50, 30));
        $p->remplacerImage(new CommandeDetailFichier(), new \DateTimeImmutable('2026-10-18 12:00'));

        self::assertSame(ProduitClient::LIEN_SUSPENDU, $p->getEtatLien());
        self::assertFalse($p->estPret());
        self::assertNull($p->fichierPourTaille(50, 30));

        // Le prépresse relie le nouveau fichier : seul le nouveau lien compte.
        $p->lierFichier(self::lien(50, 30, 'v2.pdf'));
        self::assertSame(ProduitClient::LIEN_LIE, $p->getEtatLien());
        self::assertSame('v2.pdf', $p->fichierPourTaille(50, 30)['nom']);
        self::assertCount(1, $p->getFichiersLies());
    }

    public function testLeSiteNeRecoitQueLaReferenceDuFichier(): void
    {
        $public = ProduitClient::fichiersLiesPublics([self::lien(50, 30) + ['par' => 'Seydou', 'controles' => [['libelle' => 'x']]]]);

        self::assertSame(['l', 'h', 'nom', 'empreinte', 'octets', 'le'], array_keys($public[0]));
    }

    public function testAiguillageALaCommande(): void
    {
        $p = self::etiquette()->lierFichier(self::lien(50, 30));

        $directe = (new CommandesDetails())->setLargeur(0.05)->setLongueur(0.03);
        CommandeProduitClient::aiguillerALaCommande($directe, $p);
        self::assertFalse($directe->isPrePresseNecessaire());
        self::assertSame('fichier.pdf', $directe->getFichierLie()['nom']);

        $nouvelleTaille = (new CommandesDetails())->setLargeur(0.06)->setLongueur(0.1);
        CommandeProduitClient::aiguillerALaCommande($nouvelleTaille, $p);
        self::assertTrue($nouvelleTaille->isPrePresseNecessaire());
        self::assertNull($nouvelleTaille->getFichierLie());
    }

    public function testTailleDuPanier(): void
    {
        self::assertSame(['l' => 60.0, 'h' => 100.0], Panier::tailleLue(['l' => '60', 'h' => 100]));
        $this->expectException(\InvalidArgumentException::class);
        Panier::tailleLue(['l' => 0, 'h' => 10]);
    }

    public function testDecoupeEtCouleursDetecteesHonnetement(): void
    {
        self::assertSame('CutContour', ControleFichierLie::decoupe('/CS0 [/Separation /CutContour /DeviceCMYK'));
        self::assertSame('Perforation', ControleFichierLie::decoupe('<< /Type /OCG /Name (Perforation) >>'));
        self::assertNull(ControleFichierLie::decoupe('/Separation /PANTONE#20185#20C'));
        self::assertSame(ControleFichierLie::ERREUR, ControleFichierLie::etatGlobal([['etat' => 'ok'], ['etat' => 'erreur']]));
        self::assertSame(ControleFichierLie::A_VERIFIER, ControleFichierLie::etatGlobal([['etat' => 'ok'], ['etat' => 'a_verifier']]));
    }

    public function testControleDUnPdfAuBonFormat(): void
    {
        $k = 72 / 25.4;
        $pdf = \sprintf("%%PDF-1.4\n1 0 obj << /Type /Page /MediaBox [0 0 %.2f %.2f] /TrimBox [%.2f %.2f %.2f %.2f] /Resources << /ColorSpace << /CS0 [/Separation /CutContour /DeviceCMYK] >> >> >> endobj\n/DeviceCMYK cs\n%%%%EOF", 54 * $k, 34 * $k, 2 * $k, 2 * $k, 52 * $k, 32 * $k);
        $chemin = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($chemin, $pdf);
        try {
            $controles = (new ControleFichierLie())->controler($chemin, 50, 30);
        } finally {
            unlink($chemin);
        }

        self::assertSame(ControleFichierLie::OK, ControleFichierLie::etatGlobal($controles), json_encode($controles, \JSON_UNESCAPED_UNICODE));
    }
}
