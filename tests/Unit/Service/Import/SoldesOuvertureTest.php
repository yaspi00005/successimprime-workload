<?php

namespace App\Tests\Unit\Service\Import;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\Paiements;
use App\Entity\SoldeOuverture;
use App\Service\JournalComptable;
use PHPUnit\Framework\TestCase;

/**
 * Règles des soldes d'ouverture (docs/import-donnees.md) : un montant dû
 * sans lignes ni facture, des à-nouveaux équilibrés contre le 471.
 */
final class SoldesOuvertureTest extends TestCase
{
    private static function jour(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-01 15:30');
    }

    private static function avecId(object $objet, int $id): object
    {
        $p = new \ReflectionProperty($objet, 'id');
        $p->setValue($objet, $id);

        return $objet;
    }

    public function testCreanceClientSansLignesNiTravail(): void
    {
        $client = new Clients();
        $commande = Commandes::soldeOuverture($client, 150000, self::jour(), 'SO-CLI-000123', 'Factures 2025', new \DateTimeImmutable('2026-11-30'));

        self::assertTrue($commande->estSoldeOuverture());
        self::assertSame('Solde d\'ouverture', $commande->getLibelle());
        self::assertSame('SO-CLI-000123', $commande->getNumero());
        self::assertSame($client, $commande->getClients());
        self::assertSame(150000, $commande->getTotalTtc());
        self::assertSame(150000, $commande->getResteAPayer());
        self::assertSame(0, $commande->getTva());
        self::assertCount(0, $commande->getCommandesDetails());
        // Pas de travail pour l'atelier ; l'échéance sert aux créances.
        self::assertFalse($commande->isEtat());
        self::assertSame('2026-11-30', $commande->getDateLivraison()?->format('Y-m-d'));
    }

    public function testEcheanceParDefautLeJourDOuverture(): void
    {
        $commande = Commandes::soldeOuverture(new Clients(), 1000, self::jour(), 'SO-X');

        self::assertSame(self::jour()->format('Y-m-d'), $commande->getDateLivraison()?->format('Y-m-d'));
    }

    public function testCreanceNulleOuNegativeRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Commandes::soldeOuverture(new Clients(), 0, self::jour(), 'SO-X');
    }

    public function testCommandeOrdinaireNEstPasUnSolde(): void
    {
        $commande = (new Commandes())->setNumero('CMD-0001');

        self::assertFalse($commande->estSoldeOuverture());
        self::assertSame('CMD-0001', $commande->getLibelle());
    }

    public function testMontantsDeLaTrace(): void
    {
        $compte = new SoldeOuverture(SoldeOuverture::COMPTE, 0, self::jour(), 'Caisse', null, self::jour());
        self::assertSame(0, $compte->getMontant());
        self::assertSame('2026-10-01 00:00', $compte->getDateOuverture()->format('Y-m-d H:i'));

        $this->expectException(\InvalidArgumentException::class);
        new SoldeOuverture(SoldeOuverture::CLIENT, 0, self::jour(), 'Client', null, self::jour());
    }

    public function testMontantNegatifRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SoldeOuverture(SoldeOuverture::FOURNISSEUR, -5, self::jour(), 'Fournisseur', null, self::jour());
    }

    public function testTypeInconnuRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SoldeOuverture('vente', 5, self::jour(), 'X', null, self::jour());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function ecritures(): iterable
    {
        yield 'créance client' => [SoldeOuverture::CLIENT, '411', '471'];
        yield 'avance client' => [SoldeOuverture::AVANCE_CLIENT, '471', '411'];
        yield 'dette fournisseur' => [SoldeOuverture::FOURNISSEUR, '471', '401'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ecritures')]
    public function testANouveauxEquilibres(string $type, string $debit, string $credit): void
    {
        $solde = self::avecId(new SoldeOuverture($type, 850000, self::jour(), 'Sahel Supplies', null, self::jour()), 7);

        $lignes = JournalComptable::ecrituresSoldeOuverture($solde);

        self::assertCount(2, $lignes);
        self::assertSame([$debit, $credit], array_column($lignes, 'compte'));
        self::assertSame([850000, 0], array_column($lignes, 'debit'));
        self::assertSame([0, 850000], array_column($lignes, 'credit'));
        self::assertSame(['AN-000007', 'AN-000007'], array_column($lignes, 'piece'));
        self::assertSame('2026-10-01', $lignes[0]['date']->format('Y-m-d'));
        self::assertSame('Solde d\'ouverture · Sahel Supplies', $lignes[0]['libelle']);
    }

    public function testANouveauxDesComptesDeTresorerie(): void
    {
        foreach ([CompteTresorerie::TYPE_CAISSE => '571', CompteTresorerie::TYPE_BANQUE => '521', CompteTresorerie::TYPE_ORANGE_MONEY => '5213', CompteTresorerie::TYPE_WAVE => '5214'] as $type => $numero) {
            $solde = (new SoldeOuverture(SoldeOuverture::COMPTE, 185000, self::jour(), 'Compte', null, self::jour()))
                ->setCompte((new CompteTresorerie())->setType($type));

            self::assertSame([$numero, '471'], array_column(JournalComptable::ecrituresSoldeOuverture($solde), 'compte'), $type);
        }
    }

    public function testRienPourUnSoldeNulOuAnnule(): void
    {
        $zero = new SoldeOuverture(SoldeOuverture::COMPTE, 0, self::jour(), 'Caisse', null, self::jour());
        self::assertSame([], JournalComptable::ecrituresSoldeOuverture($zero));

        $annule = new SoldeOuverture(SoldeOuverture::CLIENT, 1000, self::jour(), 'Client', null, self::jour());
        $annule->annuler(self::jour());
        self::assertTrue($annule->estAnnule());
        self::assertSame([], JournalComptable::ecrituresSoldeOuverture($annule));
    }

    public function testCreanceSoldeeLettree(): void
    {
        $commande = self::avecId(Commandes::soldeOuverture(new Clients(), 1000, self::jour(), 'SO-X'), 35);
        $paiement = (new Paiements())->setMontant(1000);
        (new \ReflectionProperty($paiement, 'statut'))->setValue($paiement, Paiements::STATUT_VALIDE);
        $commande->addPaiement($paiement);
        $solde = self::avecId((new SoldeOuverture(SoldeOuverture::CLIENT, 1000, self::jour(), 'Client', null, self::jour()))->setCommande($commande), 1);

        $lignes = JournalComptable::ecrituresSoldeOuverture($solde);

        self::assertSame('Z', $lignes[0]['lettrage']);
        self::assertNull($lignes[1]['lettrage']);
    }

    public function testDetteFournisseurMarquee(): void
    {
        $depense = new Depense(null, self::jour());
        self::assertFalse($depense->isSoldeOuverture());

        $depense->marquerSoldeOuverture();
        self::assertTrue($depense->isSoldeOuverture());
    }
}
