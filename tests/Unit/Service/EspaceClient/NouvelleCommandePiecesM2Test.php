<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\EspaceClient\OptionsCommande;
use App\Service\EspaceClient\PageNouvelleCommande;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Nouvelle commande de l'espace client (CO02) : quantité en pièces ou en
 * m² (pièces au m², demande de Yaya du 16/10/2026). En m², les pièces
 * viennent de la surface tapée et la ligne se facture en pièces au m² ; le
 * prix estimé est celui de la commande enregistrée.
 */
final class NouvelleCommandePiecesM2Test extends TestCase
{
    public function testSurfaceTapeeDonneLesPieces(): void
    {
        // Autocollants de 5 × 5 cm, espacement 1 mm : 19 × 19 = 361 au m² ; 2,5 m² = 902 pièces.
        $saisie = NouvelleCommande::quantiteSaisie($this->vinyle(), 5, 'm2', '2,5', 5, 5, 1.0);

        self::assertSame(['quantite' => 902, 'm2' => 2.5, 'erreur' => null, 'piecesM2' => true], $saisie);
        // Le nombre envoyé par la page ne compte pas.
        self::assertSame(902, NouvelleCommande::quantiteSaisie($this->vinyle(), 99999, 'm2', '2,5', 5, 5, 1.0)['quantite']);
        // L'espacement du réglage compte : 2 mm, 19 × 19 encore (1 002 / 52), 3 mm : 18 × 18.
        self::assertSame(810, NouvelleCommande::quantiteSaisie($this->vinyle(), 5, 'm2', '2,5', 5, 5, 3.0)['quantite']);
    }

    public function testEnPiecesLaQuantiteResteEtLaLigneEstEnPiecesAuM2(): void
    {
        // Réponse de Yaya du 18/10 (Q11) : en ligne, une quantité en pièces est aussi facturée en pièces au m².
        self::assertSame(['quantite' => 500, 'm2' => null, 'erreur' => null, 'piecesM2' => true], NouvelleCommande::quantiteSaisie($this->vinyle(), 500, 'piece', '2,5', 5, 5, 1.0));
        self::assertSame(['quantite' => 500, 'm2' => null, 'erreur' => null, 'piecesM2' => true], NouvelleCommande::quantiteSaisie($this->vinyle(), 500, '', '', 5, 5, 1.0));

        // Produit qui n'est pas au m² (cartes à l'unité) : « m2 » envoyé quand même, ignoré.
        $cartes = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Cartes de visite'))
            ->setModeDimension('aucune')->setModeCalcul('unite')->setPrixBase(50);
        self::assertSame(['quantite' => 500, 'm2' => null, 'erreur' => null, 'piecesM2' => false], NouvelleCommande::quantiteSaisie($cartes, 500, 'm2', '2,5', null, null, 1.0));
    }

    public function testSurfaceRefusee(): void
    {
        $vinyle = $this->vinyle();
        $erreur = static fn (string $m2, ?float $l = 5, ?float $h = 5): ?string => NouvelleCommande::quantiteSaisie($vinyle, 1, 'm2', $m2, $l, $h, 1.0)['erreur'];

        self::assertSame('Surface en m², entre 0,01 et 10 000.', $erreur(''));
        self::assertSame('Surface en m², entre 0,01 et 10 000.', $erreur('abc'));
        self::assertSame('Surface en m², entre 0,01 et 10 000.', $erreur('0'));
        self::assertSame('Pièce plus grande que 1 m : indiquez la quantité en pièces.', $erreur('3', 300, 100));
        // 30 × 30 cm : 9 au m², 0,1 m² ne fait pas une pièce.
        self::assertSame('Moins d\'une pièce sur cette surface : augmentez-la.', $erreur('0,1', 30, 30));

        $vinyle->setQuantiteMinimale(1000);
        self::assertSame('Cela fait 902 pièces ; minimum pour ce produit : 1000.', $erreur('2,5'));
    }

    public function testSansTailleLaLigneLeDit(): void
    {
        $saisie = NouvelleCommande::quantiteSaisie($this->vinyle(), 3, 'm2', '2,5', null, null, 1.0);
        self::assertSame(['quantite' => 3, 'm2' => null, 'erreur' => null, 'piecesM2' => true], $saisie);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Indiquez la largeur et la hauteur');
        NouvelleCommande::construireLigne($this->vinyle(), $saisie['quantite'], null, null, [], false);
    }

    public function testTailleDuFormatQuandElleNEstPasTapee(): void
    {
        // Étiquettes au format imposé de 10 × 5 cm : 9 × 19 = 171 au m² (1 001 / 101 et 1 001 / 51).
        $etiquettes = $this->vinyle()->setModeDimension('format')->setLargeurDefaut('0.10')->setLongueurDefaut('0.05');

        self::assertSame(['quantite' => 171, 'm2' => 1.0, 'erreur' => null, 'piecesM2' => true], NouvelleCommande::quantiteSaisie($etiquettes, 1, 'm2', '1', null, null, 1.0));
    }

    public function testLigneFactureeEnPiecesAuM2(): void
    {
        $vinyle = $this->vinyle();
        $ajuster = static function (CommandesDetails $l): void {
            NouvelleCommande::enPiecesAuM2($l, 2.5, 1.0, false);
        };

        // En pièces : 902 × 0,0025 m² = 2,255 m² × 6 500 F.
        self::assertSame(14658, NouvelleCommande::estimer($vinyle, 902, 5, 5, [], false)['total']);
        // En m² : 902 pièces ÷ 361 = 2,50 m² facturés (espacement et chutes compris).
        self::assertSame(16250, NouvelleCommande::estimer($vinyle, 902, 5, 5, [], false, null, 24, $ajuster)['total']);

        $ligne = NouvelleCommande::enPiecesAuM2(NouvelleCommande::construireLigne($vinyle, 902, 5, 5, [], false), 2.5, 1.0, false);
        self::assertTrue($ligne->isEnPiecesAuM2());
        self::assertSame(1.0, $ligne->getEspacementPiecesM2(), 'l\'espacement retenu reste sur la ligne');
        self::assertSame(902, $ligne->getQuantite(), 'la quantité reste le nombre de pièces à produire');
        self::assertSame([2.5, 'm2'], [$ligne->getQuantiteDemandee(), $ligne->getUniteDemandee()]);
        self::assertSame(2.5, $ligne->getSurfaceTotale());
    }

    public function testPrixEstimeEgalAuPrixDeLaCommandeEnregistree(): void
    {
        $vinyle = $this->vinyle();
        $laminage = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Laminage'))
            ->setPayante(true)->setPrix(1000)->setModeCalcul('metre_carre')->setActive(true);
        (new \ReflectionProperty($laminage, 'id'))->setValue($laminage, 7);
        $vinyle->addConfigurationFinition($laminage);
        $options = new OptionsCommande(Commandes::DELAI_EXPRESS, 30, true, 2000, 'Hamdallaye ACI 2000', 'mardi 20/10 · matin', new \DateTimeImmutable('2026-10-20'));
        $saisie = NouvelleCommande::quantiteSaisie($vinyle, 1, 'm2', '2,5', 5, 5, 1.0);
        $m2 = (float) $saisie['m2'];
        $ajuster = static function (CommandesDetails $l) use ($m2): void {
            NouvelleCommande::enPiecesAuM2($l, $m2, 1.0, false);
        };

        $estimation = NouvelleCommande::estimer($vinyle, $saisie['quantite'], 5, 5, [7], false, $options, 24, $ajuster);
        $commande = $this->service()->commander(new Clients(), $vinyle, $saisie['quantite'], 5, 5, [7], [], null, new \DateTimeImmutable('2026-10-18 10:00'), $ajuster, null, $options, 24);

        self::assertSame($estimation['total'], $commande->getTotalTtc());
        self::assertFalse($commande->isStatut(), 'toujours « à confirmer »');
        $ligne = $commande->getCommandesDetails()->first();
        self::assertInstanceOf(CommandesDetails::class, $ligne);
        self::assertTrue($ligne->isEnPiecesAuM2());
        self::assertSame(902, $ligne->getQuantite());
        // 2,50 m² × 6 500 + laminage 902 × 0,0025 m² × 1 000 ; express +30 % ; livraison 2 000.
        self::assertSame(16250 + 2255, $ligne->getTotalTtc());
        self::assertSame(18505 + 5552 + 2000, $commande->getTotalTtc());
    }

    public function testRecapitulatifAvecLesPiecesAuM2SansMontantPourUnDemandeur(): void
    {
        $vinyle = $this->vinyle();
        $ligne = NouvelleCommande::enPiecesAuM2(NouvelleCommande::construireLigne($vinyle, 902, 5, 5, [], false), 2.5, 1.0, false);
        $commande = NouvelleCommande::assembler($ligne, new OptionsCommande(Commandes::DELAI_STANDARD, 30, false, 0, null, null));

        $payeur = PageNouvelleCommande::recapitulatif($commande, $ligne, $vinyle, true, 48);
        self::assertSame(['libelle' => 'Surface', 'valeur' => '2,5 m² × 6 500 F'], $payeur['lignes'][0]);
        self::assertSame(['libelle' => 'Pièces au m²', 'valeur' => '361 (19 × 19)'], $payeur['lignes'][1]);
        self::assertSame('2,5 m²', $payeur['quantite']);
        self::assertSame(16250, $payeur['total']);

        $demandeur = PageNouvelleCommande::recapitulatif($commande, $ligne, $vinyle, false, 48);
        self::assertSame(['Surface' => '2,5 m²', 'Pièces au m²' => '361 (19 × 19)', 'Délai' => 'standard 48 h', 'Retrait à l\'imprimerie' => 'gratuit'], array_column($demandeur['lignes'], 'valeur', 'libelle'));
        self::assertArrayNotHasKey('total', $demandeur);
        self::assertDoesNotMatchRegularExpression('/\d\s?F\b/u', json_encode($demandeur, \JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testLeRecapitulatifGardeLaTailleAuMm(): void
    {
        // Étiquettes de 5,5 × 3,5 cm : 17 × 27 = 459 au m² ; « 0,06 × 0,04 m » annonçait une autre taille.
        $vinyle = $this->vinyle();
        $ligne = NouvelleCommande::enPiecesAuM2(NouvelleCommande::construireLigne($vinyle, 1147, 5.5, 3.5, [], false), 2.5, 1.0, false);
        $recap = PageNouvelleCommande::recapitulatif(NouvelleCommande::assembler($ligne), $ligne, $vinyle, false, 48);

        self::assertStringEndsWith(' · 0,055 × 0,035 m', $recap['titre']);
        self::assertSame(['libelle' => 'Pièces au m²', 'valeur' => '459 (17 × 27)'], $recap['lignes'][1]);
    }

    public function testLaPageSaitQuelleMatiereSeCompteEnM2(): void
    {
        $vinyle = $this->vinyle();
        $grand = (new ProduitConfiguration())->setProduit($vinyle->getProduit())->setModeDimension('mesure')->setModeCalcul('metre')->setPrixBase(2000);
        (new \ReflectionProperty(ProduitConfiguration::class, 'id'))->setValue($grand, 22);

        self::assertTrue(NouvelleCommande::fiche($vinyle, false)['m2']);
        self::assertSame([true, false], array_column(PageNouvelleCommande::matieres([$vinyle, $grand], false, false), 'm2'));
    }

    private function vinyle(): ProduitConfiguration
    {
        $produit = (new Produits())->setNom('Vinyle adhésif');
        (new \ReflectionProperty(Produits::class, 'id'))->setValue($produit, 2);
        $vinyle = (new ProduitConfiguration())->setProduit($produit)->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(6500);
        (new \ReflectionProperty(ProduitConfiguration::class, 'id'))->setValue($vinyle, 21);

        return $vinyle;
    }

    private function service(): NouvelleCommande
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('count')->willReturn(0);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f($em));

        return new NouvelleCommande($em);
    }

    public function testQuantiteEnPiecesFactureeEnPiecesAuM2(): void
    {
        // 500 pièces de 5 × 5 cm, 361 au m² (espacement 1 mm) : 500 ÷ 361 = 1,39 m² facturés, pas 1,25 m².
        $ligne = NouvelleCommande::enPiecesAuM2(NouvelleCommande::construireLigne($this->vinyle(), 500, 5, 5, [], false), null, 1.0, false);

        self::assertTrue($ligne->isEnPiecesAuM2());
        self::assertSame(1.39, $ligne->getPiecesAuM2()['surface']);
        self::assertNull($ligne->getUniteDemandee());
    }
}
