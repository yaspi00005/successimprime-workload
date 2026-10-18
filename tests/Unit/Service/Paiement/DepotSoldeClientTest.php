<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Controller\EspaceSoldeController;
use App\Entity\Clients;
use App\Entity\DepotSoldeClient;
use App\Entity\MouvementSoldeClient;
use App\Entity\Paiements;
use App\Entity\TransactionOrangeMoney;
use App\Entity\User;
use App\Security\DroitsEspace;
use App\Service\Paiement\OrangeMoney;
use App\Service\Recette\RecetteEspaceClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Dépôt Orange Money fait depuis « Mon solde » (docs/espace-client.md) :
 * Orange confirme, le dépôt passe « reçu » et rien d'autre ne se crée (ni
 * paiement, ni solde) ; seule la boutique le valide ou le refuse. Les frais
 * sont payés en plus et restent hors du solde.
 */
final class DepotSoldeClientTest extends TestCase
{
    private const MAINTENANT = '2026-10-18 10:15';

    /** @var list<TransactionOrangeMoney> */
    private array $transactions = [];

    /** @var list<object> */
    private array $persistes = [];

    public function testCycleDuDepot(): void
    {
        $depot = $this->depot();
        self::assertSame(DepotSoldeClient::INITIE, $depot->getStatut());
        self::assertFalse($depot->estPret(), 'reste en ligne tant qu\'Orange n\'a rien confirmé');
        self::assertSame(5100, $depot->getTotal(), 'le dépôt et les frais');

        $depot->recevoir('MP261018.1015.A1', new \DateTimeImmutable(self::MAINTENANT));
        self::assertTrue($depot->estEnAttente());
        self::assertTrue($depot->estPret(), 'monte au local, à valider');
        self::assertSame('MP261018.1015.A1', $depot->getTxnid());
        // Une seconde confirmation ne change rien.
        $depot->recevoir('AUTRE', new \DateTimeImmutable('2026-10-18 10:20'));
        self::assertSame('MP261018.1015.A1', $depot->getTxnid());

        $depot->valider(new User(), new \DateTimeImmutable('2026-10-18 11:00'));
        self::assertSame(DepotSoldeClient::VALIDE, $depot->getStatut());
        $this->expectException(\LogicException::class);
        $depot->rejeter('trop tard', new User(), new \DateTimeImmutable());
    }

    public function testUnDepotNonRecuNeSeValidePas(): void
    {
        $depot = $this->depot();
        $this->expectException(\LogicException::class);
        $depot->valider(new User(), new \DateTimeImmutable());
    }

    public function testMontantPositifObligatoire(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DepotSoldeClient::nouveau(new Clients(), 0, 0, 0, 'SI261018101500ABCDEF', null, new \DateTimeImmutable());
    }

    public function testLaTransactionPorteLeDepotEtSesFrais(): void
    {
        $depot = $this->depot();
        $t = TransactionOrangeMoney::pourDepot($depot, null, new \DateTimeImmutable(self::MAINTENANT));

        self::assertSame(TransactionOrangeMoney::OBJET_DEPOT, $t->getObjet());
        self::assertSame($depot, $t->getDepot());
        self::assertSame($depot->getClient(), $t->getClient());
        self::assertNull($t->getCommande());
        self::assertSame($depot->getReference(), $t->getOrderId());
        self::assertSame([5000, 50, 50, 5100], [$t->getMontant(), $t->getFondsSoutien(), $t->getFraisRetrait(), $t->getTotal()]);
    }

    public function testOrangeConfirmeLeDepotPasseRecuSansRienCreer(): void
    {
        $depot = $this->depot();
        $transaction = $this->ouverte($depot);
        $notification = ['status' => 'SUCCESS', 'notif_token' => 'jeton-secret-orange', 'txnid' => 'MP261018.1015.A1'];

        self::assertTrue($this->orangeMoney()->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable(self::MAINTENANT)));
        self::assertTrue($this->orangeMoney()->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable('2026-10-18 10:16')), 'rejouée');

        self::assertSame(TransactionOrangeMoney::REUSSIE, $transaction->getStatut());
        self::assertNull($transaction->getPaiement(), 'aucun paiement');
        self::assertSame(DepotSoldeClient::RECU, $depot->getStatut());
        self::assertSame(0, $depot->getClient()->getSoldeCredit(), 'le solde ne monte qu\'à la validation de la boutique');
        self::assertSame([], array_filter($this->persistes, static fn (object $o): bool => $o instanceof Paiements || $o instanceof MouvementSoldeClient));
    }

    public function testEchecChezOrange(): void
    {
        $depot = $this->depot();
        $transaction = $this->ouverte($depot);

        $this->orangeMoney()->notification($transaction->getOrderId(), ['status' => 'FAILED', 'notif_token' => 'jeton-secret-orange'], new \DateTimeImmutable(self::MAINTENANT));

        self::assertSame(TransactionOrangeMoney::ECHOUEE, $transaction->getStatut());
        self::assertSame(DepotSoldeClient::ECHOUE, $depot->getStatut());
        self::assertFalse($depot->estPret(), 'ne monte jamais au local');
    }

    public function testNotificationSansLeBonJetonRefusee(): void
    {
        $depot = $this->depot();
        $transaction = $this->ouverte($depot);

        self::assertFalse($this->orangeMoney()->notification($transaction->getOrderId(), ['status' => 'SUCCESS', 'notif_token' => 'faux'], new \DateTimeImmutable(self::MAINTENANT)));
        self::assertSame(DepotSoldeClient::INITIE, $depot->getStatut());
    }

    public function testBornesEtDroitsDeMonSolde(): void
    {
        self::assertSame('Indiquez le montant à déposer.', EspaceSoldeController::erreurMontant(null));
        self::assertNotNull(EspaceSoldeController::erreurMontant(OrangeMoney::DEPOT_MINIMUM - 1));
        self::assertNull(EspaceSoldeController::erreurMontant(OrangeMoney::DEPOT_MINIMUM));
        self::assertNull(EspaceSoldeController::erreurMontant(OrangeMoney::DEPOT_MAXIMUM));
        self::assertNotNull(EspaceSoldeController::erreurMontant(OrangeMoney::DEPOT_MAXIMUM + 1));
        // Réservé à qui paie, fermé au compte Lecture · suivi, vérifié par la recette des accès.
        foreach (['app_espace_solde', 'app_espace_solde_retour'] as $route) {
            self::assertContains($route, DroitsEspace::PAGES_ECRITURE, $route);
            self::assertContains($route, RecetteEspaceClient::PAGES_PAYEUR, $route);
        }
        self::assertNotContains('app_espace_solde_deposer', DroitsEspace::ENVOIS_LECTURE);
    }

    private function depot(): DepotSoldeClient
    {
        $client = (new Clients())->setNom('Sow');

        return DepotSoldeClient::nouveau($client, 5000, 50, 50, 'SI261018101000ABCDEF', 'Amadou Sow', new \DateTimeImmutable('2026-10-18 10:10'));
    }

    private function ouverte(DepotSoldeClient $depot): TransactionOrangeMoney
    {
        $t = TransactionOrangeMoney::pourDepot($depot, null, new \DateTimeImmutable('2026-10-18 10:10'));
        $t->ouvrir('pt-123', 'jeton-secret-orange', 'https://mpayment.orange-money.com/ml/mpayment/abstract/v1abc');
        $this->transactions[] = $t;

        return $t;
    }

    private function orangeMoney(): OrangeMoney
    {
        $transactions = $this->createStub(EntityRepository::class);
        $transactions->method('findOneBy')->willReturnCallback(function (array $critere): ?TransactionOrangeMoney {
            foreach ($this->transactions as $t) {
                if ($t->getOrderId() === ($critere['orderId'] ?? null)) {
                    return $t;
                }
            }

            return null;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($transactions);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
        });

        return new OrangeMoney($em, new MockHttpClient(), new ArrayAdapter(), $this->createStub(UrlGeneratorInterface::class), new NullLogger(),
            'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', 'id', 'secret', 'cle-marchand');
    }
}
