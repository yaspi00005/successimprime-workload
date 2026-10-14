<?php

namespace App\Tests\Unit\Service\Conception;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\MouvementCreditIa;
use App\Entity\Paiements;
use App\Service\Conception\CreditsIa;
use PHPUnit\Framework\TestCase;

/**
 * Relevé du crédit IA : achat, image débitée, remboursement, paiement
 * rejeté par la caisse, garde contre deux générations simultanées et
 * plafond d'images du jour. Les requêtes SQL du solde sont rejouées sur
 * un petit tableau en mémoire, avec la même condition que MariaDB.
 */
final class CreditsIaTest extends TestCase
{
    use BaseEnMemoire;

    private Clients $client;

    protected function setUp(): void
    {
        $this->client = (new Clients())->setNom('Hôtel Niger');
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($this->client, 42);
    }

    public function testAchatOrangeMoneyUtilisableToutDeSuiteUneSeuleFois(): void
    {
        $credits = $this->credits();
        [$commande, $paiement] = $this->commandePayee(3000);

        $credits->acheterParOrangeMoney($this->client, 'Ibrahim', 3, 3000, $commande, $paiement, 'SI261014', new \DateTimeImmutable());
        // Notification rejouée : pas de deuxième crédit.
        $credits->acheterParOrangeMoney($this->client, 'Ibrahim', 3, 3000, $commande, $paiement, 'SI261014', new \DateTimeImmutable());

        self::assertSame(3000, $credits->solde($this->client));
        self::assertCount(1, $this->mouvements);
        self::assertSame(MouvementCreditIa::ACHAT, $this->mouvements[0]->getType());
        self::assertSame(Paiements::STATUT_EN_ATTENTE, $paiement->getStatut()); // jamais validé tout seul
    }

    public function testDebitPuisRemboursementUnique(): void
    {
        $credits = $this->credits();
        $this->soldes[42] = 2000;

        $debit = $credits->debiter($this->client, 1000, 'Image 1 · DC-2026-001', 'Ibrahim', new \DateTimeImmutable());
        self::assertSame(1000, $credits->solde($this->client));
        self::assertSame(-1000, $debit->getMontant());
        self::assertSame(-1, $debit->getImages());

        $credits->rembourser($debit, new \DateTimeImmutable());
        $credits->rembourser($debit, new \DateTimeImmutable());
        self::assertSame(2000, $credits->solde($this->client));
        self::assertCount(2, $this->mouvements);
        self::assertSame(MouvementCreditIa::REMBOURSEMENT, $this->mouvements[1]->getType());
    }

    public function testDeuxGenerationsEnsembleNeDepassentPasLeCredit(): void
    {
        $credits = $this->credits();
        $this->soldes[42] = 1000;

        $credits->debiter($this->client, 1000, 'Image 1', null, new \DateTimeImmutable());
        try {
            $credits->debiter($this->client, 1000, 'Image 2', null, new \DateTimeImmutable());
            self::fail('Le second débit doit être refusé.');
        } catch (\DomainException $e) {
            self::assertStringStartsWith('Crédit IA insuffisant', $e->getMessage());
        }
        self::assertSame(0, $credits->solde($this->client));
        self::assertCount(1, $this->mouvements);
    }

    public function testPaiementRejeteRetireLeCreditQuitteAPasserSousZero(): void
    {
        $credits = $this->credits();
        [$commande, $paiement] = $this->commandePayee(3000);
        $credits->acheterParOrangeMoney($this->client, null, 3, 3000, $commande, $paiement, 'SI261014B', new \DateTimeImmutable());
        $credits->debiter($this->client, 1000, 'Image 1', null, new \DateTimeImmutable());
        $credits->debiter($this->client, 1000, 'Image 2', null, new \DateTimeImmutable());

        // La caisse ne trouve pas l'argent.
        (new \ReflectionProperty(Paiements::class, 'statut'))->setValue($paiement, Paiements::STATUT_REJETE);
        $credits->rapprocher($this->client, new \DateTimeImmutable());
        $credits->rapprocher($this->client, new \DateTimeImmutable());

        self::assertSame(-2000, $credits->solde($this->client));
        $rejets = array_values(array_filter($this->mouvements, static fn (MouvementCreditIa $m): bool => MouvementCreditIa::REJET === $m->getType()));
        self::assertCount(1, $rejets);
        self::assertSame(-3000, $rejets[0]->getMontant());

        // Génération bloquée tant que le crédit est négatif.
        $this->expectException(\DomainException::class);
        $credits->debiter($this->client, 1000, 'Image 3', null, new \DateTimeImmutable());
    }

    public function testPackAuNumeroMarchandCompteUneFoisValideParLaCaisse(): void
    {
        $credits = $this->credits();
        $commande = (new Commandes())->setClients($this->client)->setTotalTtc(5000);
        $achat = $credits->acheterEnAttente($this->client, 'Ibrahim', 5, 5000, $commande, new \DateTimeImmutable());

        $credits->rapprocher($this->client, new \DateTimeImmutable());
        self::assertSame(0, $credits->solde($this->client));
        self::assertSame(MouvementCreditIa::EN_ATTENTE, $achat->getEtat());
        self::assertSame([$achat], $credits->enAttente($this->client));

        $paiement = (new Paiements())->setMontant(5000)->setMode(Paiements::MODE_ORANGE_MONEY);
        (new \ReflectionProperty(Paiements::class, 'statut'))->setValue($paiement, Paiements::STATUT_VALIDE);
        $commande->addPaiement($paiement);
        $credits->rapprocher($this->client, new \DateTimeImmutable());
        $credits->rapprocher($this->client, new \DateTimeImmutable());

        self::assertSame(5000, $credits->solde($this->client));
        self::assertSame(MouvementCreditIa::ACQUIS, $achat->getEtat());
        self::assertSame([], $credits->enAttente($this->client));
    }

    public function testPackDUneCommandeSupprimeeAnnule(): void
    {
        $credits = $this->credits();
        $commande = (new Commandes())->setClients($this->client)->setTotalTtc(1000);
        $achat = $credits->acheterEnAttente($this->client, null, 1, 1000, $commande, new \DateTimeImmutable());
        (new \ReflectionProperty(Commandes::class, 'deleted'))->setValue($commande, true);

        $credits->rapprocher($this->client, new \DateTimeImmutable());

        self::assertSame(MouvementCreditIa::ANNULE, $achat->getEtat());
        self::assertSame(0, $credits->solde($this->client));
    }

    public function testPlafondDuJourPourToutLeSite(): void
    {
        $credits = $this->credits();
        $jour = new \DateTimeImmutable('2026-10-14');

        self::assertTrue($credits->reserverJour($jour, 2));
        self::assertTrue($credits->reserverJour($jour, 2));
        self::assertFalse($credits->reserverJour($jour, 2));
        self::assertSame(2, $credits->imagesDuJour($jour));

        // Une génération échouée libère sa place.
        $credits->libererJour($jour);
        self::assertTrue($credits->reserverJour($jour, 2));
        self::assertFalse($credits->reserverJour($jour, 0));
        self::assertTrue($credits->reserverJour(new \DateTimeImmutable('2026-10-15'), 2));
    }

    /** @return array{Commandes, Paiements} */
    private function commandePayee(int $montant): array
    {
        $commande = (new Commandes())->setClients($this->client)->setTotalTtc($montant);
        $paiement = (new Paiements())->setMontant($montant)->setMode(Paiements::MODE_ORANGE_MONEY);
        $commande->addPaiement($paiement);

        return [$commande, $paiement];
    }

    private function credits(): CreditsIa
    {
        return new CreditsIa($this->em());
    }
}
