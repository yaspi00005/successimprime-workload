<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Entreprise;
use App\Entity\Factures;
use PHPUnit\Framework\TestCase;

/**
 * Frais de livraison et objet des devis, commandes et factures
 * (docs/documents-livraisons.md, « Frais de livraison et objet »).
 */
final class LivraisonObjetDocumentTest extends TestCase
{
    public function testObjetSurUneLigneEtLimite(): void
    {
        $commande = (new Commandes())->setObjet("  Impressions\n grand  format ");
        self::assertSame('Impressions grand format', $commande->getObjet());

        self::assertNull($commande->setObjet('   ')->getObjet());
        self::assertSame(150, mb_strlen((string) $commande->setObjet(str_repeat('é', 200))->getObjet()));
    }

    public function testFraisJamaisNegatifs(): void
    {
        self::assertSame(0, (new Devis())->getFraisLivraison());
        self::assertSame(0, (new Devis())->setFraisLivraison(-500)->getFraisLivraison());
        self::assertSame(0, (new Devis())->setFraisLivraison(null)->getFraisLivraison());
    }

    public function testLesFraisSAjoutentAuxTotauxDeLaCommande(): void
    {
        $commande = (new Commandes())->setFraisLivraison(1500)->setRemise(1000);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(10000)->setTotalTtc(10000));
        $annulee = (new CommandesDetails())->setTotalHt(5000)->setTotalTtc(5000);
        $annulee->setStatutProduction(CommandesDetails::PRODUCTION_ANNULEE);
        $commande->addCommandesDetail($annulee);

        $commande->recalculerTotaux();

        self::assertSame(11500, $commande->getTotalHt(), 'sous-total HT : lignes et frais');
        self::assertSame(10500, $commande->getTotalTtc(), 'remise globale déduite, frais compris');
        self::assertSame(10500, $commande->getMontantApayer());
        self::assertSame(10500, $commande->getResteAPayer(), 'le reste à payer part du TTC remisé');
    }

    public function testLesFraisSAjoutentAuxTotauxDuDevis(): void
    {
        $devis = (new Devis())->setFraisLivraison(2000)->setRemise(500);
        $devis->addDevisDetail((new DevisDetails())->setTotalHt(8000)->setTotalTtc(9440));
        // Totaux envoyés par le navigateur : jamais gardés.
        $devis->setTotalTtc(1);

        $devis->recalculerTotaux();

        self::assertSame(10000, $devis->getTotalHt());
        // Remise de 500 sur 10 000 HT : la TVA des lignes (1 440) baisse de 5 %.
        self::assertSame(1368, $devis->getMontantTva());
        self::assertSame(10868, $devis->getTotalTtc());
        self::assertSame(10868, $devis->getMontantApayer());
    }

    public function testLaFactureCopieFraisEtObjetDeLaCommande(): void
    {
        $commande = (new Commandes())->setFraisLivraison(2500)->setObjet('Prestations informatiques');
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(20000)->setTotalTtc(20000));
        $commande->recalculerTotaux();

        $facture = (new Factures())->chargerDepuisCommande($commande);

        self::assertSame(2500, $facture->getFraisLivraison());
        self::assertSame('Prestations informatiques', $facture->getObjet());
        self::assertSame(22500, $facture->getTotalHt(), 'les frais sont déjà dans le total');
        self::assertSame(22500, $facture->getTotalTtc());
    }

    public function testConditionsDePaiementDeLEntreprise(): void
    {
        $entreprise = new Entreprise();
        self::assertSame(Entreprise::CONDITIONS_PAIEMENT_DEFAUT, $entreprise->getConditionsPaiement(), 'une nouvelle entreprise reprend la mention par défaut');

        $entreprise->setConditionsPaiement("  Paiement à 30 jours.\r\n\r\n\r\n\r\nPénalités   au-delà. ");
        self::assertSame("Paiement à 30 jours.\n\nPénalités au-delà.", $entreprise->getConditionsPaiement());

        self::assertNull($entreprise->setConditionsPaiement('  ')->getConditionsPaiement(), 'vide : pas de bloc NB');
        self::assertSame(Entreprise::CONDITIONS_PAIEMENT_LONGUEUR, mb_strlen((string) $entreprise->setConditionsPaiement(str_repeat('x', 900))->getConditionsPaiement()));
    }
}
