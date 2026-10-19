<?php

namespace App\Tests\Unit\Service\Commandes;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Devis;
use App\Service\Commandes\HorsImprimerieListener;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/**
 * Commande « hors imprimerie » (docs/hors-imprimerie.md) : ses lignes
 * encore à produire sautent la prépresse et l'atelier ; décochée, les
 * lignes de produit reviennent à produire.
 */
final class HorsImprimerieListenerTest extends TestCase
{
    private static function ligne(string $type = CommandesDetails::TYPE_PRODUIT, string $statut = CommandesDetails::PRODUCTION_A_PRODUIRE): CommandesDetails
    {
        return (new CommandesDetails())->setTypeLigne($type)->setStatutProduction($statut)->setPrePresseNecessaire(true);
    }

    public function testParDefautUneVenteEstDeLImprimerie(): void
    {
        self::assertFalse((new Commandes())->isHorsImprimerie());
        self::assertFalse((new Devis())->isHorsImprimerie());
        self::assertTrue((new Commandes())->setHorsImprimerie(true)->isHorsImprimerie());
        self::assertFalse((new Devis())->setHorsImprimerie(null)->isHorsImprimerie());
    }

    public function testCocheeLaLigneAProduireNEstPlusRequise(): void
    {
        $ligne = self::ligne();

        self::assertTrue(HorsImprimerieListener::appliquer($ligne, true));
        self::assertSame(CommandesDetails::PRODUCTION_NON_REQUISE, $ligne->getStatutProduction());
        self::assertFalse($ligne->isPrePresseNecessaire());
        self::assertFalse($ligne->isProductionNecessaire());
        // Déjà réglée : rien ne change une seconde fois.
        self::assertFalse(HorsImprimerieListener::appliquer($ligne, true));
    }

    public function testCocheeUneLigneCommenceeLivreeOuAnnuleeNeBougePas(): void
    {
        foreach ([CommandesDetails::PRODUCTION_EN_COURS, CommandesDetails::PRODUCTION_TERMINEE, CommandesDetails::PRODUCTION_PRETE_LIVRAISON, CommandesDetails::PRODUCTION_LIVREE, CommandesDetails::PRODUCTION_ANNULEE] as $statut) {
            $ligne = self::ligne(statut: $statut);
            self::assertFalse(HorsImprimerieListener::appliquer($ligne, true), $statut);
            self::assertSame($statut, $ligne->getStatutProduction());
            self::assertTrue($ligne->isPrePresseNecessaire(), $statut);
        }

        // Encore « à produire » mais déjà passée en prépresse, ou démarrée à l'atelier.
        $enPrePresse = self::ligne()->addControlePrePresse(new ControlePrePresse());
        self::assertFalse(HorsImprimerieListener::appliquer($enPrePresse, true));
        self::assertSame(CommandesDetails::PRODUCTION_A_PRODUIRE, $enPrePresse->getStatutProduction());

        $demarree = self::ligne();
        (new \ReflectionProperty(CommandesDetails::class, 'productionDebuteLe'))->setValue($demarree, new \DateTimeImmutable());
        self::assertFalse(HorsImprimerieListener::appliquer($demarree, true));
        self::assertFalse(HorsImprimerieListener::pasCommencee($demarree));
    }

    public function testDecocheeLaLigneDeProduitRevientAProduire(): void
    {
        $ligne = self::ligne();
        HorsImprimerieListener::appliquer($ligne, true);

        self::assertTrue(HorsImprimerieListener::appliquer($ligne, false));
        self::assertSame(CommandesDetails::PRODUCTION_A_PRODUIRE, $ligne->getStatutProduction());
        self::assertTrue($ligne->isPrePresseNecessaire());
        self::assertTrue($ligne->isProductionNecessaire());
    }

    public function testDecocheeArticlesLignesLibresEtLignesAvanceesRestent(): void
    {
        foreach ([CommandesDetails::TYPE_ARTICLE, CommandesDetails::TYPE_LIBRE] as $type) {
            $ligne = self::ligne($type)->setPrePresseNecessaire(false)->setProductionNecessaire(false);
            self::assertSame(CommandesDetails::PRODUCTION_NON_REQUISE, $ligne->getStatutProduction());
            self::assertFalse(HorsImprimerieListener::appliquer($ligne, false), $type);
            self::assertSame(CommandesDetails::PRODUCTION_NON_REQUISE, $ligne->getStatutProduction());
        }

        $livree = self::ligne(statut: CommandesDetails::PRODUCTION_LIVREE);
        self::assertFalse(HorsImprimerieListener::appliquer($livree, false));
        self::assertSame(CommandesDetails::PRODUCTION_LIVREE, $livree->getStatutProduction());
    }

    public function testLaCaseSuitLaSynchronisation(): void
    {
        $types = CatalogueSynchro::types();
        self::assertContains('horsImprimerie', $types['commande']['champs']);
        self::assertContains('horsImprimerie', $types['devis']['champs']);
        // Une commande passée en ligne est de l'imprimerie : seule l'imprimerie la classe hors imprimerie.
        self::assertFalse($types['commande']['imposeAuLocal']['horsImprimerie']);
        self::assertArrayNotHasKey('horsImprimerie', $types['commande']['remontees']);
    }
}
