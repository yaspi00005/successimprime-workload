<?php

namespace App\Tests\Unit\Service;

use App\Entity\Commandes;
use App\Entity\ProduitClient;
use App\Service\CommandeProduitClient;
use PHPUnit\Framework\TestCase;

final class CommandeProduitClientTest extends TestCase
{
    private CommandeProduitClient $service;

    protected function setUp(): void
    {
        $this->service = new CommandeProduitClient();
    }

    private static function bache(): ProduitClient
    {
        return (new ProduitClient())
            ->setNom('Bâche façade')
            ->setReference('PC-AWA-01')
            ->setFormat(4.0, 1.5)
            ->setUniteVente(ProduitClient::UNITE_PIECE_OU_M2)
            ->setPrixUnitaire(29_000);
    }

    public function testVingtMetresCarresDonnentQuatreBaches(): void
    {
        $proposition = $this->service->proposer(self::bache(), 20, CommandeProduitClient::SAISIE_M2);

        self::assertSame(4, $proposition['pieces']);
        self::assertSame(116_000, $proposition['montant']);
        self::assertSame(['pieces' => 3, 'surface' => 18.0, 'montant' => 87_000], $proposition['alternative']);
        self::assertSame('4 × 400 × 150 cm = 24 m²', $proposition['production']);
    }

    public function testLeClientPeutPrendreLArrondiInferieur(): void
    {
        self::assertSame(3, $this->service->proposer(self::bache(), 20, CommandeProduitClient::SAISIE_M2, true)['pieces']);
        self::assertSame(4, $this->service->proposer(self::bache()->setArrondiSuperieurImpose(true), 20, CommandeProduitClient::SAISIE_M2, true)['pieces']);
    }

    public function testVinyleAuM2SurRouleau(): void
    {
        $vinyle = (new ProduitClient())->setNom('Vinyle vitrine')->setReference('PC-AWA-02')
            ->setFormat(1.37, null)->setUniteVente(ProduitClient::UNITE_M2)->setPrixUnitaire(7_500);

        $proposition = $this->service->proposer($vinyle, 6, CommandeProduitClient::SAISIE_M2);

        self::assertSame(4.4, $proposition['longueur']);
        self::assertSame(45_000, $proposition['montant']);
    }

    public function testEtiquettesParLot(): void
    {
        $etiquettes = (new ProduitClient())->setNom('Étiquettes')->setReference('PC-AWA-05')
            ->setUniteVente(ProduitClient::UNITE_LOT)->setTailleLot(100)->setPosesParFeuille(20)->setPrixUnitaire(4_500);

        $proposition = $this->service->proposer($etiquettes, 250, CommandeProduitClient::SAISIE_LOTS);

        self::assertSame(300, $proposition['pieces']);
        self::assertSame(13_500, $proposition['montant']);
    }

    public function testMinimumRespecte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->proposer(self::bache()->setQuantiteMinimum(5), 2, CommandeProduitClient::SAISIE_PIECES);
    }

    public function testRollUpRefuseLesM2(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->proposer(self::bache()->setUniteVente(ProduitClient::UNITE_PIECE), 20, CommandeProduitClient::SAISIE_M2);
    }

    public function testLigneCreeeSansPaoAvecLesDeuxQuantites(): void
    {
        $commande = (new Commandes())->setTva(18);
        $ligne = $this->service->creerLigne($commande, self::bache(), 20, CommandeProduitClient::SAISIE_M2);

        self::assertSame(4, $ligne->getQuantite());
        self::assertSame(20.0, $ligne->getQuantiteDemandee());
        self::assertSame('m2', $ligne->getUniteDemandee());
        self::assertFalse($ligne->isPrePresseNecessaire());
        self::assertEqualsWithDelta(116_000, $ligne->getTotalTtc(), 1);
        self::assertContains($ligne, $commande->getCommandesDetails());
    }
}
