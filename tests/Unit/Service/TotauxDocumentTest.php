<?php

namespace App\Tests\Unit\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Service\TotauxDocument;
use PHPUnit\Framework\TestCase;

/**
 * Remise globale (docs/factures-entreprises.md, « Remise globale ») :
 * visible, déduite du HT, TVA = 0 sans TVA, TTC = HT + TVA, reste à payer =
 * TTC − déjà payé, de la commande à la facture.
 */
final class TotauxDocumentTest extends TestCase
{
    public function testRemiseGlobaleSansTva(): void
    {
        self::assertSame(
            ['sousTotalHt' => 140600, 'remise' => 4200, 'totalHt' => 136400, 'tva' => 0, 'totalTtc' => 136400],
            TotauxDocument::calculer(140600, 0, 4200),
        );
    }

    public function testRemiseGlobaleAvecTvaAuProrata(): void
    {
        // 100 000 HT à 18 %, remise 10 000 : la TVA porte sur 90 000.
        $t = TotauxDocument::calculer(100000, 18000, 10000);
        self::assertSame(90000, $t['totalHt']);
        self::assertSame(16200, $t['tva']);
        self::assertSame(106200, $t['totalTtc']);
        self::assertSame($t['totalHt'] + $t['tva'], $t['totalTtc']);
    }

    public function testSansRemiseRienNeChange(): void
    {
        self::assertSame(['sousTotalHt' => 50000, 'remise' => 0, 'totalHt' => 50000, 'tva' => 9000, 'totalTtc' => 59000], TotauxDocument::calculer(50000, 9000, 0));
    }

    public function testRemisePlafonneeAuSousTotal(): void
    {
        $t = TotauxDocument::calculer(30000, 0, 50000);
        self::assertSame(30000, $t['remise']);
        self::assertSame(0, $t['totalTtc']);
        self::assertSame(0, TotauxDocument::calculer(0, 0, 0)['totalTtc']);
        self::assertSame(0, TotauxDocument::calculer(10000, 0, -500)['remise'], 'jamais de remise négative');
    }

    public function testCommandeAvecRemiseSansTvaResteAPayerEtStatut(): void
    {
        $commande = (new Commandes())->setRemise(4200);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(140600)->setTotalTtc(140600));
        $commande->recalculerTotaux();

        self::assertSame(140600, $commande->getTotalHt(), 'sous-total HT avant remise');
        self::assertSame(4200, $commande->getRemiseGlobale());
        self::assertSame(136400, $commande->getTotalHtNet());
        self::assertSame(0, $commande->getMontantTva(), 'pas de TVA sur les lignes : TVA = 0');
        self::assertSame(136400, $commande->getTotalTtc(), 'TTC = HT après remise + TVA');
        self::assertSame(136400, $commande->getMontantApayer());
        self::assertSame(136400, $commande->getResteAPayer());

        // Le client paie le montant remisé : la commande est soldée.
        $commande->addPaiement(self::paiementValide(136400));
        $commande->recalculerTotaux();
        self::assertSame(0, $commande->getResteAPayer());
        self::assertSame(Commandes::PAIEMENT_PAYE, $commande->getStatutPaiement());
    }

    public function testCommandeAvecRemiseEtTva(): void
    {
        $commande = (new Commandes())->setRemise(10000);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(100000)->setTotalTtc(118000));
        $commande->recalculerTotaux();

        self::assertSame(90000, $commande->getTotalHtNet());
        self::assertSame(16200, $commande->getMontantTva());
        self::assertSame(106200, $commande->getTotalTtc());

        $commande->addPaiement(self::paiementValide(50000));
        self::assertSame(56200, $commande->getResteAPayer(), 'reste à payer = TTC − déjà payé');
        self::assertSame(Commandes::PAIEMENT_PARTIEL, $commande->getStatutPaiement());
    }

    public function testCommandeAvecCodePromoEtRemiseGlobale(): void
    {
        // Le code promo est déjà déduit de la ligne (60 000 − 6 000) ; la remise globale vient ensuite.
        $ligne = (new CommandesDetails())->setTotalHt(54000)->setTotalTtc(54000)->setRemiseCodePromo(6000);
        $commande = (new Commandes())->setRemise(4000);
        $commande->addCommandesDetail($ligne);
        $commande->recalculerTotaux();

        self::assertSame(6000, $commande->getRemiseCodePromo());
        self::assertSame(54000, $commande->getTotalHt());
        self::assertSame(50000, $commande->getTotalTtc(), 'le code promo puis la remise, chacun une seule fois');
    }

    public function testCommandeAvecFraisDeLivraisonEtRemise(): void
    {
        $commande = (new Commandes())->setFraisLivraison(2000)->setRemise(3000);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(48000)->setTotalTtc(48000));
        $commande->recalculerTotaux();

        self::assertSame(50000, $commande->getTotalHt(), 'lignes + livraison');
        self::assertSame(47000, $commande->getTotalTtc());
        self::assertSame(0, $commande->getMontantTva());
    }

    public function testLaFactureRetombeSurLaCommandeSansFausseTva(): void
    {
        $commande = (new Commandes())->setRemise(4200)->setFraisLivraison(1500);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(139100)->setTotalTtc(139100));
        $commande->addPaiement(self::paiementValide(68200));
        $commande->recalculerTotaux();

        $facture = (new Factures())->setTypeDocument(Factures::TYPE_FACTURE)->setComptabilisee(true)->chargerDepuisCommande($commande);
        $facture->synchroniserPaiementsDepuisCommande();

        self::assertSame(140600, $facture->getTotalHt());
        self::assertSame(4200, $facture->getRemise());
        self::assertSame(136400, $facture->getTotalHtNet());
        self::assertSame(0, $facture->getMontantTva(), 'la remise n\'est plus comptée en TVA');
        self::assertSame(136400, $facture->getTotalTtc());
        self::assertSame($commande->getTotalTtc(), $facture->getTotalTtc());
        self::assertSame(68200, $facture->getResteAPayer());
    }

    public function testLaFactureDAcompteEstUnePartDeLaCommande(): void
    {
        $commande = (new Commandes())->setRemise(4200)->setFraisLivraison(1500);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(139100)->setTotalTtc(139100));
        $commande->addPaiement(self::paiementValide(68200));
        $commande->recalculerTotaux();

        $acompte = (new Factures())->setTypeDocument(Factures::TYPE_ACOMPTE)->chargerAcompte($commande, 30);
        $acompte->synchroniserPaiementsDepuisCommande();

        self::assertTrue($acompte->estAcompte());
        self::assertFalse($acompte->isComptabilisee(), 'l\'acompte n\'est pas une vente : les paiements le sont déjà');
        self::assertSame(30, $acompte->getPourcentageAcompte());
        self::assertSame(40920, $acompte->getTotalTtc(), '30 % de 136 400 F');
        self::assertSame(40920, $acompte->getMontantPaye(), 'payé dès que les paiements couvrent l\'acompte');
        self::assertSame(0, $acompte->getResteAPayer());
    }

    public function testLaFactureAvecTva(): void
    {
        $commande = (new Commandes())->setRemise(10000)->setTva(18);
        $commande->addCommandesDetail((new CommandesDetails())->setTotalHt(100000)->setTotalTtc(118000));
        $commande->recalculerTotaux();

        $facture = (new Factures())->chargerDepuisCommande($commande);

        self::assertSame(90000, $facture->getTotalHtNet());
        self::assertSame(16200, $facture->getMontantTva());
        self::assertSame(106200, $facture->getTotalTtc());
        self::assertSame($facture->getTotalHtNet() + $facture->getMontantTva(), $facture->getTotalTtc());
    }

    public function testDevisAvecRemiseGlobale(): void
    {
        $devis = (new Devis())->setRemise(2000)->setFraisLivraison(1000);
        $devis->addDevisDetail((new DevisDetails())->setTotalHt(20000)->setTotalTtc(20000));
        $devis->setTotalTtc(21000); // envoyé par le navigateur : jamais gardé
        $devis->recalculerTotaux();

        self::assertSame(21000, $devis->getTotalHt());
        self::assertSame(19000, $devis->getTotalHtNet());
        self::assertSame(0, $devis->getMontantTva());
        self::assertSame(19000, $devis->getTotalTtc());
        self::assertSame(19000, $devis->getMontantApayer());
    }

    private static function paiementValide(int $montant): Paiements
    {
        $paiement = (new Paiements())->setMontant($montant);
        (new \ReflectionProperty(Paiements::class, 'statut'))->setValue($paiement, Paiements::STATUT_VALIDE);

        return $paiement;
    }
}
