<?php

namespace App\Tests\Unit\Service\Commandes;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\Commandes\DuplicationCommande;
use PHPUnit\Framework\TestCase;

final class DuplicationCommandeTest extends TestCase
{
    public function testLaLigneCopieeGardeLesPrixEtOublieLaFabrication(): void
    {
        $ligne = (new CommandesDetails())->setDesignation('Bâche')->setModeCalcul('unite')->setQuantite(130)->setPrixUnitaire('3500')->setRemise(200)->setTva(0);
        $ligne->addFinition((new CommandeDetailFinition())->setNomFinition('Oeillets')->setMontant(6500)->setQuantite(130));
        $ligne->calculerTotaux();
        $ligne->setStatutProduction(CommandesDetails::PRODUCTION_LIVREE)->setBatValide(true);
        $ligne->addFichier((new CommandeDetailFichier())->setNomOriginal('bache.pdf'));

        $copie = $ligne->copiePourNouvelleCommande();
        $copie->calculerTotaux();

        self::assertSame($ligne->getTotalTtc(), $copie->getTotalTtc());
        self::assertSame('3500', (string) (int) $copie->getPrixUnitaire());
        self::assertSame(200, $copie->getRemise());
        self::assertCount(1, $copie->getFinitions());
        self::assertNotSame($ligne->getFinitions()->first(), $copie->getFinitions()->first());
        self::assertSame($copie, $copie->getFinitions()->first()->getCommandeDetail());
        self::assertCount(0, $copie->getFichiers());
        self::assertSame(CommandesDetails::PRODUCTION_A_PRODUIRE, $copie->getStatutProduction());
        self::assertFalse($copie->isBatValide());
        self::assertNull($copie->getId());
        self::assertNotEquals((string) $ligne->getPublicId(), (string) $copie->getPublicId());
    }

    public function testEnLigneNiExpressNiLigneAnnulee(): void
    {
        $commande = new Commandes();
        $produit = (new CommandesDetails())->setDesignation('Flyers');
        $express = (new CommandesDetails())->setTypeLigne(CommandesDetails::TYPE_LIBRE)->setDesignation('Express')->setPrePresseNecessaire(false)->setProductionNecessaire(false);
        $annulee = (new CommandesDetails())->setDesignation('Annulée')->setStatutProduction(CommandesDetails::PRODUCTION_ANNULEE);
        foreach ([$produit, $express, $annulee] as $l) {
            $commande->addCommandesDetail($l);
        }

        self::assertSame([$produit], DuplicationCommande::lignes($commande, true));
        self::assertSame([$produit, $express], DuplicationCommande::lignes($commande, false));
    }

    public function testLeFichierCopiePartageLesOctets(): void
    {
        $source = (new CommandeDetailFichier())->setNomOriginal('logo.pdf')->setNomStockage('abc.pdf');
        $ligne = new CommandesDetails();
        $copie = $source->copiePourLigne($ligne, null);

        self::assertSame('abc.pdf', $copie->getNomStockage());
        self::assertSame($source, $copie->getFichierSource());
        self::assertSame($ligne, $copie->getCommandeDetail());
        self::assertNotSame($source->getJetonUpload(), $copie->getJetonUpload());
    }
}
