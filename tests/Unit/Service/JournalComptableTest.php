<?php

namespace App\Tests\Unit\Service;

use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Service\JournalComptable;
use PHPUnit\Framework\TestCase;

final class JournalComptableTest extends TestCase
{
    public function testFactureEquilibreeAvecTvaEtMajoration(): void
    {
        $facture = (new Factures())
            ->setNumero('F-0101')
            ->setDateFacture(new \DateTimeImmutable('2026-10-10'))
            ->setTotalHt(45763)
            ->setMontantTva(8237)
            ->setMontantSurfacturation(2000);

        $lignes = JournalComptable::ecrituresFacture($facture);

        self::assertSame(['411', '702', '702', '4431'], array_column($lignes, 'compte'));
        self::assertSame(56000, $lignes[0]['debit']);
        self::assertSame(array_sum(array_column($lignes, 'debit')), array_sum(array_column($lignes, 'credit')));
        self::assertStringStartsWith('Majoration', $lignes[2]['libelle']);
    }

    public function testDepenseEnCaisse(): void
    {
        $caisse = (new CompteTresorerie())->setType(CompteTresorerie::TYPE_CAISSE);
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCategorie(MouvementTresorerie::CATEGORIE_CARBURANT)
            ->setCompteSource($caisse)
            ->setMontant(12000)
            ->setLibelle('Carburant livraison')
            ->setDateOperation(new \DateTimeImmutable('2026-10-09'));

        [$journal, $lignes] = JournalComptable::ecrituresMouvement($mouvement);

        self::assertSame('caisse', $journal);
        self::assertSame(['6042', '571'], array_column($lignes, 'compte'));
        self::assertSame([12000, 0], array_column($lignes, 'debit'));
        self::assertSame([0, 12000], array_column($lignes, 'credit'));
    }

    public function testBalance(): void
    {
        $l = static fn (string $c, int $d, int $cr): array => ['compte' => $c, 'debit' => $d, 'credit' => $cr];
        $balance = JournalComptable::balance(['ventes' => [$l('411', 100, 0), $l('702', 0, 100)], 'caisse' => [$l('571', 60, 0), $l('411', 0, 60)]]);

        self::assertSame(['411', '571', '702'], array_column($balance, 'compte'));
        self::assertSame(40, $balance[0]['solde']);
        self::assertSame(-100, $balance[2]['solde']);
    }

    public function testComptesDeTresorerie(): void
    {
        self::assertSame('5213', JournalComptable::compteTresorerie((new CompteTresorerie())->setType(CompteTresorerie::TYPE_ORANGE_MONEY)));
        self::assertSame('mobile', JournalComptable::journalDe((new CompteTresorerie())->setType(CompteTresorerie::TYPE_WAVE)));
        self::assertSame('471', JournalComptable::compteTresorerie(null));
    }

    /**
     * Compte du client (docs/finances.md) : la monnaie gardée et les dépôts
     * entrent en caisse contre l'avance du client (4191), l'argent rendu en
     * sort ; payer avec le solde solde l'avance contre la créance (411).
     */
    public function testAvanceDuClientEnCaisse(): void
    {
        $caisse = (new CompteTresorerie())->setType(CompteTresorerie::TYPE_CAISSE)->setNom('Caisse boutique');
        $entree = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)
            ->setCategorie(MouvementTresorerie::CATEGORIE_AVANCE_CLIENT)
            ->setCompteDestination($caisse)
            ->setMontant(2500)
            ->setLibelle('Monnaie gardée — Coumba Bagayoko')
            ->setDateOperation(new \DateTimeImmutable('2026-10-18'));

        [$journal, $lignes] = JournalComptable::ecrituresMouvement($entree, 'Coumba Bagayoko');

        self::assertSame('caisse', $journal);
        self::assertSame(['571', '4191'], array_column($lignes, 'compte'));
        self::assertSame([2500, 0], array_column($lignes, 'debit'));
        self::assertSame('Coumba Bagayoko', $lignes[1]['tiers'], 'l\'avance porte le nom du client');

        $sortie = (clone $entree)->setType(MouvementTresorerie::TYPE_DECAISSEMENT)->setCompteDestination(null)->setCompteSource($caisse);
        [, $lignes] = JournalComptable::ecrituresMouvement($sortie, 'Coumba Bagayoko');
        self::assertSame(['4191', '571'], array_column($lignes, 'compte'));
        self::assertSame([2500, 0], array_column($lignes, 'debit'));

        // Une autre catégorie ne prend pas de tiers, même s'il est donné.
        $carburant = (clone $sortie)->setCategorie(MouvementTresorerie::CATEGORIE_CARBURANT);
        [, $lignes] = JournalComptable::ecrituresMouvement($carburant, 'Coumba Bagayoko');
        self::assertNull($lignes[0]['tiers']);
    }

    public function testPayeAvecLeSoldeEtAnnule(): void
    {
        $client = (new \App\Entity\Clients())->setNom('Bagayoko')->setPrenom('Coumba');
        $commande = (new \App\Entity\Commandes())->setNumero('CMD-000050-10-2026')->setTotalTtc(15000)->setClients($client);
        $utilisation = \App\Entity\MouvementSoldeClient::nouveau($client, \App\Entity\MouvementSoldeClient::UTILISATION, -10000, 2500, null, new \DateTimeImmutable('2026-10-18'))->setCommande($commande);

        $lignes = JournalComptable::ecrituresSoldeClient($utilisation);
        self::assertSame(['4191', '411'], array_column($lignes, 'compte'));
        self::assertSame([10000, 0], array_column($lignes, 'debit'));
        self::assertSame([0, 10000], array_column($lignes, 'credit'));
        self::assertStringStartsWith('Payé avec le solde · CMD-000050-10-2026', $lignes[0]['libelle']);

        $retour = \App\Entity\MouvementSoldeClient::nouveau($client, \App\Entity\MouvementSoldeClient::RETOUR, 10000, 12500, null, new \DateTimeImmutable('2026-10-18'))->setCommande($commande);
        self::assertSame(['411', '4191'], array_column(JournalComptable::ecrituresSoldeClient($retour), 'compte'));

        // Entrées et sorties d'argent : déjà en trésorerie, rien ici.
        foreach ([\App\Entity\MouvementSoldeClient::DEPOT, \App\Entity\MouvementSoldeClient::MONNAIE, \App\Entity\MouvementSoldeClient::REMBOURSEMENT, \App\Entity\MouvementSoldeClient::REPRISE] as $type) {
            self::assertSame([], JournalComptable::ecrituresSoldeClient(\App\Entity\MouvementSoldeClient::nouveau($client, $type, 1000, 1000, null, new \DateTimeImmutable())), $type);
        }
    }
}
