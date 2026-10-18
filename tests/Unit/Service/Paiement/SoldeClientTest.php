<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\DepotSoldeClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\MouvementTresorerieService;
use App\Service\Paiement\ComptesOrangeMoney;
use App\Service\Paiement\SoldeClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Compte du client à la boutique (docs/finances.md, « Compte du client ») :
 * monnaie gardée, dépôt, paiement avec le solde, argent rendu, dépôt
 * Orange Money de l'espace client validé à la boutique. Chaque mouvement
 * écrit sa ligne de relevé ; l'argent entré ou sorti passe en trésorerie
 * « avance client » ; les frais Orange Money restent hors du solde ; le
 * solde ne descend jamais sous zéro.
 */
final class SoldeClientTest extends TestCase
{
    /** @var list<object> */
    private array $persistes = [];

    /** @var list<MouvementTresorerie> */
    private array $tresorerie = [];

    /** @var array<class-string, list<object>> réponses de findBy par classe */
    private array $trouves = [];

    private int $prochainId = 41;

    public function testMonnaieEtDisponible(): void
    {
        self::assertSame(2500, SoldeClient::monnaie(10000, 7500));
        self::assertSame(0, SoldeClient::monnaie(5000, 7500), 'le client n\'a pas remis plus');
        self::assertSame(15000, SoldeClient::disponible(20000, 5000));
        self::assertSame(0, SoldeClient::disponible(3000, 5000), 'jamais négatif');
        self::assertSame(0, SoldeClient::disponible(-10, 0));
    }

    public function testLaMonnaieNeSeGardeQuEnEspecesDansUneCaisse(): void
    {
        SoldeClient::verifierMonnaie($this->paiementEspeces(), 2500);
        self::assertTrue(true, 'espèces, client connu, caisse : accepté');

        foreach ([
            'rien à garder' => [$this->paiementEspeces(), 0],
            'Orange Money' => [$this->paiementEspeces()->setMode(Paiements::MODE_ORANGE_MONEY), 2500],
            'sans client' => [(new Paiements())->setMode(Paiements::MODE_ESPECES)->setMontant(7500)->setCommande(new Commandes()), 2500],
            'compte en banque' => [$this->paiementEspeces()->setCompteTresorerie($this->compte(CompteTresorerie::TYPE_BANQUE)), 2500],
            'un chiffre de trop (plus que la base ne garde)' => [$this->paiementEspeces(), 99999999999],
        ] as $cas => [$paiement, $monnaie]) {
            try {
                SoldeClient::verifierMonnaie($paiement, $monnaie);
                self::fail($cas.' : refus attendu');
            } catch (\DomainException) {
                self::assertTrue(true);
            }
        }
    }

    public function testMonnaieGardeeResteDansLaCaisseEtVaAuSolde(): void
    {
        $paiement = $this->paiementEspeces();
        $client = $paiement->getCommande()->getClients();

        $ligne = $this->service()->garderMonnaie($paiement, 2500, $this->caissiere());

        self::assertSame(2500, $client->getSoldeCredit());
        self::assertSame(MouvementSoldeClient::MONNAIE, $ligne->getType());
        self::assertSame(2500, $ligne->getMontant());
        self::assertSame(2500, $ligne->getSoldeApres());
        self::assertSame('R-000123', $ligne->getReference(), 'le reçu du paiement');
        self::assertSame($paiement, $ligne->getPaiement());
        self::assertSame('demo.aminata', $ligne->getParNom());
        self::assertCount(1, $this->tresorerie);
        $mouvement = $this->tresorerie[0];
        self::assertSame(MouvementTresorerie::TYPE_ENCAISSEMENT, $mouvement->getType());
        self::assertSame(MouvementTresorerie::CATEGORIE_AVANCE_CLIENT, $mouvement->getCategorie(), 'ni vente ni produit');
        self::assertSame(2500, $mouvement->getMontant());
        self::assertSame($paiement->getCompteTresorerie(), $mouvement->getCompteDestination(), 'l\'argent est dans la caisse du paiement');
        self::assertSame($mouvement, $ligne->getMouvementTresorerie());
    }

    public function testDepotOrangeMoneyLesFraisRestentHorsDuSolde(): void
    {
        $client = $this->client(0);
        $orange = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY);

        $ligne = $this->service()->deposer($client, 10000, Paiements::MODE_ORANGE_MONEY, $orange, 'MP261018.1200.A1', 200, $this->caissiere(), 'Avance');

        self::assertSame(10000, $client->getSoldeCredit(), 'les frais ne vont pas au solde');
        self::assertSame(MouvementSoldeClient::DEPOT, $ligne->getType());
        self::assertMatchesRegularExpression('/^D-\d{6}$/', (string) $ligne->getReference());
        self::assertSame('Avance', $ligne->getMotif());
        self::assertSame('Dépôt Orange Money', $ligne->getLibelle());
        self::assertSame([MouvementTresorerie::CATEGORIE_AVANCE_CLIENT, MouvementTresorerie::CATEGORIE_FRAIS_ORANGE], array_map(static fn (MouvementTresorerie $m): string => $m->getCategorie(), $this->tresorerie));
        self::assertSame([10000, 200], array_map(static fn (MouvementTresorerie $m): int => (int) $m->getMontant(), $this->tresorerie));
    }

    public function testDepotEnEspecesSansFrais(): void
    {
        $client = $this->client(500);

        $ligne = $this->service()->deposer($client, 20000, Paiements::MODE_ESPECES, $this->compte(CompteTresorerie::TYPE_CAISSE), null, 300, $this->caissiere());

        self::assertSame(20500, $client->getSoldeCredit());
        self::assertSame(20500, $ligne->getSoldeApres());
        self::assertSame('Dépôt en espèces', $ligne->getLibelle());
        self::assertCount(1, $this->tresorerie, 'pas de frais en espèces');
    }

    public function testDepotRefuse(): void
    {
        $client = $this->client(0);
        $cas = [
            'montant nul' => fn () => $this->service()->deposer($client, 0, Paiements::MODE_ESPECES, $this->compte(CompteTresorerie::TYPE_CAISSE), null, 0, $this->caissiere()),
            'espèces sur Orange Money' => fn () => $this->service()->deposer($client, 1000, Paiements::MODE_ESPECES, $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY), null, 0, $this->caissiere()),
            'Orange Money sans référence' => fn () => $this->service()->deposer($client, 1000, Paiements::MODE_ORANGE_MONEY, $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY), ' ', 0, $this->caissiere()),
            'compte des paiements en ligne' => fn () => $this->service()->deposer($client, 1000, Paiements::MODE_ORANGE_MONEY, $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY)->setOrangeMoneyEnLigne(true), 'MP1', 0, $this->caissiere()),
            'chèque' => fn () => $this->service()->deposer($client, 1000, Paiements::MODE_CHEQUE, $this->compte(CompteTresorerie::TYPE_BANQUE), 'CH1', 0, $this->caissiere()),
            'un chiffre de trop' => fn () => $this->service()->deposer($client, 99999999999, Paiements::MODE_ESPECES, $this->compte(CompteTresorerie::TYPE_CAISSE), null, 0, $this->caissiere()),
        ];
        foreach ($cas as $libelle => $appel) {
            try {
                $appel();
                self::fail($libelle.' : refus attendu');
            } catch (\DomainException) {
                self::assertSame(0, $client->getSoldeCredit(), $libelle);
            }
        }
        self::assertSame([], $this->tresorerie);
    }

    public function testArgentRenduJamaisPlusQueLeSolde(): void
    {
        $client = $this->client(5000);
        $caisse = $this->compte(CompteTresorerie::TYPE_CAISSE);

        try {
            $this->service()->rembourser($client, 6000, $caisse, 'Le client reprend son argent', $this->direction());
            self::fail('plus que le solde : refus attendu');
        } catch (\DomainException) {
            self::assertSame(5000, $client->getSoldeCredit());
        }
        try {
            $this->service()->rembourser($client, 1000, $caisse, '  ', $this->direction());
            self::fail('sans motif : refus attendu');
        } catch (\DomainException) {
            self::assertSame(5000, $client->getSoldeCredit());
        }
        try {
            // Le mouvement d'une banque attend la validation : le solde ne doit pas baisser avant.
            $this->service()->rembourser($client, 1000, $this->compte(CompteTresorerie::TYPE_BANQUE), 'Virement au client', $this->direction());
            self::fail('depuis la banque : refus attendu');
        } catch (\DomainException) {
            self::assertSame(5000, $client->getSoldeCredit());
            self::assertSame([], $this->tresorerie);
        }

        $ligne = $this->service()->rembourser($client, 5000, $caisse, 'Le client reprend son argent', $this->direction());

        self::assertSame(0, $client->getSoldeCredit());
        self::assertSame(-5000, $ligne->getMontant());
        self::assertSame(MouvementSoldeClient::REMBOURSEMENT, $ligne->getType());
        self::assertMatchesRegularExpression('/^RB-\d{6}$/', (string) $ligne->getReference());
        self::assertCount(1, $this->tresorerie);
        self::assertSame(MouvementTresorerie::TYPE_DECAISSEMENT, $this->tresorerie[0]->getType());
        self::assertSame(MouvementTresorerie::CATEGORIE_AVANCE_CLIENT, $this->tresorerie[0]->getCategorie(), 'pas une dépense');
        self::assertSame($caisse, $this->tresorerie[0]->getCompteSource());
    }

    public function testPayerAvecLeSoldeToutOuPartie(): void
    {
        $client = $this->client(20000);
        $commande = (new Commandes())->setNumero('CMD-000050-10-2026')->setTotalTtc(15000)->setClients($client);

        foreach ([[16000, 'plus que le reste'], [0, 'montant nul']] as [$montant, $cas]) {
            try {
                $this->service()->utiliser($commande, $montant, $this->caissiere());
                self::fail($cas.' : refus attendu');
            } catch (\DomainException) {
                self::assertSame(20000, $client->getSoldeCredit(), $cas);
            }
        }

        $partiel = $this->service()->utiliser($commande, 5000, $this->caissiere());
        self::assertSame(Paiements::MODE_SOLDE_CLIENT, $partiel->getMode());
        self::assertTrue($partiel->estValide(), 'aucun compte de trésorerie : validé tout de suite');
        self::assertNull($partiel->getCompteTresorerie());
        self::assertSame(10000, $commande->getResteAPayer());
        self::assertSame('partielle', $commande->getStatutPaiement());

        $this->service()->utiliser($commande, 10000, $this->caissiere());
        self::assertSame(0, $commande->getResteAPayer());
        self::assertSame(5000, $client->getSoldeCredit());
        self::assertSame([], $this->tresorerie, 'l\'argent est déjà à la boutique');
        $lignes = $this->lignes();
        self::assertSame([-5000, -10000], array_map(static fn (MouvementSoldeClient $l): int => $l->getMontant(), $lignes));
        self::assertSame('Paiement de la commande CMD-000050-10-2026', $lignes[0]->getLibelle());
    }

    public function testPayerAvecLeSoldeRefusePlusQueLeDisponible(): void
    {
        $client = $this->client(3000);
        $commande = (new Commandes())->setNumero('CMD-000051-10-2026')->setTotalTtc(15000)->setClients($client);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('n\'a que 3 000');
        $this->service()->utiliser($commande, 5000, $this->caissiere());
    }

    public function testPaiementParLeSoldeAnnuleRevientAuCompte(): void
    {
        $client = $this->client(8000);
        $commande = (new Commandes())->setNumero('CMD-000052-10-2026')->setTotalTtc(8000)->setClients($client);
        $paiement = $this->service()->utiliser($commande, 8000, $this->caissiere());
        self::assertSame(0, $client->getSoldeCredit());

        $this->service()->annulerUtilisation($paiement, 'Erreur de commande', $this->direction());

        self::assertSame(8000, $client->getSoldeCredit());
        self::assertSame(Paiements::STATUT_ANNULE, $paiement->getStatut());
        self::assertSame(8000, $commande->getResteAPayer());
        $retour = $this->lignes()[1];
        self::assertSame(MouvementSoldeClient::RETOUR, $retour->getType());
        self::assertSame(8000, $retour->getMontant());
        self::assertSame('Erreur de commande', $retour->getMotif());
    }

    public function testDepotEnLigneValideALaBoutique(): void
    {
        $client = $this->client(1000);
        $enLigne = $this->compte(CompteTresorerie::TYPE_ORANGE_MONEY)->setOrangeMoneyEnLigne(true);
        $this->trouves[CompteTresorerie::class] = [$enLigne];
        $depot = DepotSoldeClient::nouveau($client, 5000, 50, 50, 'SI261018ABCD', 'Amadou Sow', new \DateTimeImmutable('-1 hour'));
        $depot->recevoir('MP261018.0900.B2', new \DateTimeImmutable('-55 minutes'));

        $ligne = $this->service()->validerDepot($depot, $this->caissiere());

        self::assertSame(DepotSoldeClient::VALIDE, $depot->getStatut());
        self::assertSame(6000, $client->getSoldeCredit(), 'le montant, sans les frais');
        self::assertSame('OM MP261018.0900.B2', $ligne->getReference());
        self::assertSame($depot, $ligne->getDepot());
        self::assertSame([5000, 100], array_map(static fn (MouvementTresorerie $m): int => (int) $m->getMontant(), $this->tresorerie));
        self::assertSame($enLigne, $this->tresorerie[0]->getCompteDestination(), 'sur le compte Orange Money en ligne');
        self::assertSame(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, $this->tresorerie[1]->getCategorie());

        // Déjà validé : une seconde validation est refusée, le solde ne bouge plus.
        try {
            $this->service()->validerDepot($depot, $this->caissiere());
            self::fail('double validation');
        } catch (\LogicException) {
            self::assertSame(6000, $client->getSoldeCredit());
        }
    }

    public function testDepotEnLigneRefuseSansMotif(): void
    {
        $depot = DepotSoldeClient::nouveau($this->client(0), 5000, 0, 0, 'SI261018EFGH', null, new \DateTimeImmutable());
        $depot->recevoir(null, new \DateTimeImmutable());

        try {
            $this->service()->rejeterDepot($depot, ' ', $this->caissiere());
            self::fail('motif obligatoire');
        } catch (\InvalidArgumentException) {
            self::assertTrue($depot->estEnAttente());
        }
        $this->service()->rejeterDepot($depot, 'Argent pas reçu', $this->caissiere());
        self::assertSame(DepotSoldeClient::REJETE, $depot->getStatut());
        self::assertSame(0, $depot->getClient()->getSoldeCredit());
    }

    /**
     * « À valider » traite plusieurs opérations d'affilée : un refus annule
     * sa transaction sans fermer l'EntityManager (wrapInTransaction le
     * ferme), sinon l'opération suivante tombe sur « The EntityManager is
     * closed ».
     */
    public function testUnRefusNeFermePasLEntityManager(): void
    {
        $connexion = $this->createMock(Connection::class);
        $connexion->expects(self::once())->method('beginTransaction');
        $connexion->expects(self::never())->method('commit');
        $connexion->expects(self::once())->method('rollBack');
        $connexion->method('isTransactionActive')->willReturn(true);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connexion);
        $em->expects(self::never())->method('wrapInTransaction');
        $em->expects(self::never())->method('close');
        $em->method('getRepository')->willReturn($this->createStub(EntityRepository::class));
        $client = $this->client(5000);

        try {
            (new SoldeClient($em, $this->createStub(MouvementTresorerieService::class)))
                ->rembourser($client, 6000, $this->compte(CompteTresorerie::TYPE_CAISSE), 'Le client reprend son argent', $this->direction());
            self::fail('plus que le solde : refus attendu');
        } catch (\DomainException) {
            self::assertSame(5000, $client->getSoldeCredit());
        }
    }

    /* ---------- Outils ---------- */

    private function service(): SoldeClient
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f($em));
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            foreach ($this->persistes as $o) {
                if (property_exists($o, 'id') && null === (new \ReflectionProperty($o, 'id'))->getValue($o)) {
                    (new \ReflectionProperty($o, 'id'))->setValue($o, ++$this->prochainId);
                }
            }
        });
        $connexion = $this->createStub(Connection::class);
        $connexion->method('isTransactionActive')->willReturn(false);
        $em->method('getConnection')->willReturn($connexion);
        $em->method('getRepository')->willReturnCallback(function (string $classe): EntityRepository {
            $depot = $this->createStub(EntityRepository::class);
            $depot->method('findBy')->willReturn($this->trouves[$classe] ?? []);
            $depot->method('findOneBy')->willReturn(null);

            return $depot;
        });

        $tresorerie = $this->createStub(MouvementTresorerieService::class);
        $tresorerie->method('enregistrer')->willReturnCallback(function (MouvementTresorerie $m): MouvementTresorerie {
            $this->tresorerie[] = $m;

            return $m;
        });

        return new SoldeClient($em, $tresorerie, new ComptesOrangeMoney($em));
    }

    /** @return list<MouvementSoldeClient> */
    private function lignes(): array
    {
        return array_values(array_filter($this->persistes, static fn (object $o): bool => $o instanceof MouvementSoldeClient));
    }

    private function client(int $solde): Clients
    {
        $client = (new Clients())->setNom('Bagayoko')->setSoldeCredit($solde);
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($client, 900122);

        return $client;
    }

    private function compte(string $type): CompteTresorerie
    {
        return (new CompteTresorerie())->setType($type)->setNom('Compte '.$type);
    }

    private function paiementEspeces(): Paiements
    {
        $commande = (new Commandes())->setNumero('CMD-000049-10-2026')->setTotalTtc(7500)->setClients($this->client(0));
        $paiement = (new Paiements())
            ->setCommande($commande)
            ->setMontant(7500)
            ->setMode(Paiements::MODE_ESPECES)
            ->setCompteTresorerie($this->compte(CompteTresorerie::TYPE_CAISSE));
        (new \ReflectionProperty(Paiements::class, 'id'))->setValue($paiement, 123);

        return $paiement;
    }

    private function caissiere(): User
    {
        return (new User())->setUsername('demo.aminata');
    }

    private function direction(): User
    {
        return (new User())->setUsername('demo.kadiatou');
    }
}
