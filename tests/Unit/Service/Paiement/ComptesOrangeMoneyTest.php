<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Controller\MouvementTresorerieController;
use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\ParametresPaiement;
use App\Entity\User;
use App\Service\JournalComptable;
use App\Service\Paiement\ComptesOrangeMoney;
use App\Service\Paiement\FraisOrangeMoney;
use PHPUnit\Framework\TestCase;

/**
 * Comptes Orange Money (explications de Yaya du 15/10/2026) : les frais
 * payés par le client traversent le compte Orange Money et Orange les
 * débite quand l'argent sort. Exemple de docs/finances.md : commande de
 * 25 000 F, le compte reçoit 25 500 F ; virement de 25 000 F à la
 * banque, Orange débite 500 F ; le compte revient à 0.
 */
final class ComptesOrangeMoneyTest extends TestCase
{
    public function testLaCategorieDesFraisDependDuCompte(): void
    {
        self::assertSame(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, ComptesOrangeMoney::categorieFrais($this->compte(CompteTresorerie::TYPE_ORANGE_MONEY)));
        self::assertSame(MouvementTresorerie::CATEGORIE_AUTRE_PRODUIT, ComptesOrangeMoney::categorieFrais($this->compte(CompteTresorerie::TYPE_WAVE)), 'Wave ne change pas');
        self::assertSame(MouvementTresorerie::CATEGORIE_AUTRE_PRODUIT, ComptesOrangeMoney::categorieFrais(null));
    }

    public function testLesFraisOrangeNeSontNiProduitNiCharge(): void
    {
        foreach ([MouvementTresorerie::TYPE_ENCAISSEMENT, MouvementTresorerie::TYPE_DECAISSEMENT] as $type) {
            $mouvement = (new MouvementTresorerie())->setType($type)->setCategorie(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE);
            self::assertFalse($mouvement->isImpactResultat(), $type);
            self::assertFalse($mouvement->estProduit(), $type);
            self::assertFalse($mouvement->estCharge(), $type);
            self::assertTrue($mouvement->estHorsActivite(), $type);
            // Même forcé, l'impact sur le résultat reste nul.
            self::assertFalse($mouvement->setImpactResultat(true)->isImpactResultat(), $type);
        }
        self::assertSame('Frais Orange Money à prélever', MouvementTresorerie::CATEGORIES_LABELS[MouvementTresorerie::CATEGORIE_FRAIS_ORANGE]);
        self::assertNotContains(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, MouvementTresorerie::getCategoriesPourFormulaire(), 'posée par l\'application, jamais choisie à la main');
        self::assertNotContains(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, MouvementTresorerie::getCategoriesProduits());
        self::assertNotContains(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, MouvementTresorerie::getCategoriesCharges());
    }

    public function testAuJournalLeCompteDePassageRevientAZero(): void
    {
        self::assertSame('4712', JournalComptable::COMPTE_CATEGORIE[MouvementTresorerie::CATEGORIE_FRAIS_ORANGE]);
        self::assertArrayHasKey('4712', JournalComptable::COMPTES);

        $om = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY);
        [$journal, $recus] = JournalComptable::ecrituresMouvement($this->mouvement(MouvementTresorerie::TYPE_ENCAISSEMENT, MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, 500, destination: $om));
        [, $preleves] = JournalComptable::ecrituresMouvement($this->mouvement(MouvementTresorerie::TYPE_DECAISSEMENT, MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, 500, source: $om));

        self::assertSame('mobile', $journal);
        self::assertSame(['5213', '4712'], array_column($recus, 'compte'));
        self::assertSame([0, 500], array_column($recus, 'credit'), 'reçus : 4712 au crédit, jamais 758');
        self::assertSame(['4712', '5213'], array_column($preleves, 'compte'));
        self::assertSame([500, 0], array_column($preleves, 'debit'), 'prélevés : 4712 au débit');

        $solde = 0;
        foreach ([...$recus, ...$preleves] as $ligne) {
            if ('4712' === $ligne['compte']) {
                $solde += $ligne['debit'] - $ligne['credit'];
            }
        }
        self::assertSame(0, $solde);
    }

    public function testFraisAPreleverEtTransferable(): void
    {
        // Compte 7 : 500 F reçus, rien prélevé ; compte 8 : tout prélevé ; compte 9 : plus prélevé que reçu.
        self::assertSame([7 => 500, 8 => 0, 9 => 0], ComptesOrangeMoney::cumuler([7 => 500, 8 => 300, 9 => 100], [8 => 300, 9 => 250]));

        self::assertSame(['solde' => 25500, 'a_prelever' => 500, 'transferable' => 25000], ComptesOrangeMoney::etatDe(25500, 500));
        self::assertSame(['solde' => 0, 'a_prelever' => 0, 'transferable' => 0], ComptesOrangeMoney::etatDe(0, 0));
        // Solde plus bas que les frais (sortie saisie sans ses frais) : rien de transférable, jamais négatif.
        self::assertSame(['solde' => 300, 'a_prelever' => 300, 'transferable' => 0], ComptesOrangeMoney::etatDe(300, 500));
        self::assertSame(['solde' => -200, 'a_prelever' => 0, 'transferable' => 0], ComptesOrangeMoney::etatDe(-200, 500));
    }

    public function testCeQuiPeutSortirQuandOrangeDebiteLesFrais(): void
    {
        $parametres = new ParametresPaiement(); // 1 % + 1 %

        // L'exemple : 25 500 F sur le compte, 25 000 F peuvent sortir (Orange débite 500 F).
        self::assertSame(25000, ComptesOrangeMoney::sortieMax(25500, $parametres));
        self::assertSame(500, ComptesOrangeMoney::aPreleverAuRetrait(25500, 500, $parametres));
        foreach ([1, 99, 101, 25499, 408086, 1000003] as $solde) {
            $sortie = ComptesOrangeMoney::sortieMax($solde, $parametres);
            self::assertLessThanOrEqual($solde, FraisOrangeMoney::calculer($sortie, $parametres)->total(), (string) $solde);
            self::assertGreaterThan($solde, FraisOrangeMoney::calculer($sortie + 1, $parametres)->total(), (string) $solde);
        }
        self::assertSame(0, ComptesOrangeMoney::sortieMax(0, $parametres));
        self::assertSame(0, ComptesOrangeMoney::sortieMax(-500, $parametres));

        // Solde entré sans frais suivis (avant le 15/10, dépôt) : Orange débite quand même ses frais.
        $aPrelever = ComptesOrangeMoney::aPreleverAuRetrait(408086, 210, $parametres);
        $etat = ComptesOrangeMoney::etatDe(408086, $aPrelever);
        self::assertSame(408086, FraisOrangeMoney::calculer($etat['transferable'], $parametres)->total(), 'montant + frais = solde : le compte revient à 0');
        self::assertSame(400084, $etat['transferable']);
        self::assertSame(8002, $etat['a_prelever']);

        // Frais suivis plus hauts (sortie saisie sans ses frais) : ils restent à prélever.
        self::assertSame(500, ComptesOrangeMoney::aPreleverAuRetrait(500, 500, $parametres));

        // Sans taux (frais décochés dans les réglages) : tout peut sortir.
        $sansFrais = (new ParametresPaiement())->setTauxFondsSoutien(0)->setTauxFraisRetrait(0);
        self::assertSame(408086, ComptesOrangeMoney::sortieMax(408086, $sansFrais));
        self::assertSame(210, ComptesOrangeMoney::aPreleverAuRetrait(408086, 210, $sansFrais));
    }

    public function testLeVirementCreeDeuxMouvementsEtLeCompteRevientAZero(): void
    {
        $om = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY)->setSoldeActuel(25500);
        $banque = $this->compte(CompteTresorerie::TYPE_BANQUE);
        $virement = $this->mouvement(MouvementTresorerie::TYPE_TRANSFERT, MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE, 25000, source: $om, destination: $banque)
            ->setReference('MVT-20261015120000-0042')
            ->setReferenceExterne('CHQ 778899')
            ->setLibelle('Chèque Orange vers BDM');
        $agent = new User();

        $frais = MouvementTresorerieController::fraisOrangeSaisis($virement, '500');
        $prelevements = ComptesOrangeMoney::mouvementsPrelevement($virement, $frais, 500, $agent);

        self::assertSame(500, $frais);
        self::assertCount(1, $prelevements, 'le virement et un mouvement de frais');
        $p = $prelevements[0];
        self::assertSame(MouvementTresorerie::TYPE_DECAISSEMENT, $p->getType());
        self::assertSame(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, $p->getCategorie());
        self::assertSame($om, $p->getCompteSource());
        self::assertNull($p->getCompteDestination());
        self::assertSame(500, $p->getMontant());
        self::assertSame('MVT-20261015120000-0042-FO', $p->getReference(), 'retrouvé à l\'annulation du virement');
        self::assertSame('CHQ 778899', $p->getReferenceExterne());
        self::assertSame($agent, $p->getAgent());
        self::assertStringStartsWith('Fonds de soutien et frais de retrait prélevés par Orange', $p->getLibelle());

        // Comme le contrôleur : le virement, puis les frais, sur les soldes.
        $om->debiter(25000);
        $banque->crediter(25000);
        $om->debiter($p->getMontant());
        self::assertSame(0, $om->getSoldeActuel());
        self::assertSame(25000, $banque->getSoldeActuel());
        self::assertSame([7 => 0], ComptesOrangeMoney::cumuler([7 => 500], [7 => $p->getMontant()]));
    }

    public function testFraisAuDelaDeCeQueLesClientsOntPayeEnCharge(): void
    {
        $sortie = $this->mouvement(MouvementTresorerie::TYPE_DECAISSEMENT, MouvementTresorerie::CATEGORIE_AUTRE_CHARGE, 30000, source: $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY))
            ->setReference('MVT-1')
            ->setLibelle('Retrait');

        $prelevements = ComptesOrangeMoney::mouvementsPrelevement($sortie, 600, 500, null);

        self::assertSame([500, 100], array_map(static fn (MouvementTresorerie $m): int => $m->getMontant(), $prelevements));
        self::assertSame([MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, MouvementTresorerie::CATEGORIE_FRAIS_BANCAIRE], array_map(static fn (MouvementTresorerie $m): string => $m->getCategorie(), $prelevements));
        self::assertSame(['MVT-1-FO', 'MVT-1-FC'], array_map(static fn (MouvementTresorerie $m): string => $m->getReference(), $prelevements));
        self::assertTrue($prelevements[1]->estCharge(), 'payé par l\'imprimerie : une vraie charge');
        self::assertSame([], ComptesOrangeMoney::mouvementsPrelevement($sortie, 0, 500, null));
    }

    public function testFraisSeulementQuandLArgentSortDOrangeMoney(): void
    {
        $caisse = $this->compte(CompteTresorerie::TYPE_CAISSE);
        $om = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY);
        $banque = $this->compte(CompteTresorerie::TYPE_BANQUE);

        self::assertSame(0, MouvementTresorerieController::fraisOrangeSaisis($this->mouvement(MouvementTresorerie::TYPE_TRANSFERT, MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE, 1000, source: $caisse, destination: $banque), '500'));
        self::assertSame(0, MouvementTresorerieController::fraisOrangeSaisis($this->mouvement(MouvementTresorerie::TYPE_ENCAISSEMENT, MouvementTresorerie::CATEGORIE_VENTE, 1000, destination: $om), '500'));
        self::assertSame(1250, MouvementTresorerieController::fraisOrangeSaisis($this->mouvement(MouvementTresorerie::TYPE_DECAISSEMENT, MouvementTresorerie::CATEGORIE_ACHAT, 1000, source: $om), '1 250'));
        self::assertSame(0, MouvementTresorerieController::fraisOrangeSaisis($this->mouvement(MouvementTresorerie::TYPE_TRANSFERT, MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE, 1000, source: $om, destination: $banque), ''));
        self::assertSame([], ComptesOrangeMoney::mouvementsPrelevement($this->mouvement(MouvementTresorerie::TYPE_TRANSFERT, MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE, 1000, source: $caisse, destination: $banque), 500, 500, null));
    }

    public function testPaiementOrangeMoneyEnLigne(): void
    {
        $enLigne = (new Paiements())->setMode(Paiements::MODE_ORANGE_MONEY)->setReference('OM MP261015.1500.A1');
        self::assertTrue($enLigne->estOrangeMoneyEnLigne(), 'API de l\'espace client');
        self::assertFalse((clone $enLigne)->setEncaissePar(new User())->estOrangeMoneyEnLigne(), 'saisi à la caisse');
        self::assertFalse((new Paiements())->setMode(Paiements::MODE_ORANGE_MONEY)->setReference('MP261015.1500.A1')->estOrangeMoneyEnLigne(), 'relevé, numéro de l\'imprimerie');
        self::assertFalse((new Paiements())->setMode(Paiements::MODE_WAVE)->setReference('OM 1')->estOrangeMoneyEnLigne());
    }

    public function testLeCompteEnLigneNEstQueOrangeMoney(): void
    {
        $compte = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY)->setOrangeMoneyEnLigne(true);
        self::assertTrue($compte->isOrangeMoneyEnLigne());
        self::assertTrue($compte->estOrangeMoney());
        self::assertFalse($this->compte(CompteTresorerie::TYPE_WAVE)->estOrangeMoney());
    }

    private function compte(string $type): CompteTresorerie
    {
        return (new CompteTresorerie())->setType($type)->setNom(ucfirst($type));
    }

    private function mouvement(string $type, string $categorie, int $montant, ?CompteTresorerie $source = null, ?CompteTresorerie $destination = null): MouvementTresorerie
    {
        return (new MouvementTresorerie())
            ->setType($type)
            ->setCategorie($categorie)
            ->setMontant($montant)
            ->setCompteSource($source)
            ->setCompteDestination($destination)
            ->setLibelle('Essai')
            ->setDateOperation(new \DateTimeImmutable('2026-10-15 12:00'));
    }
}
