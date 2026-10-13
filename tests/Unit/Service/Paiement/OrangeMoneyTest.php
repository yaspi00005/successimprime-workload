<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Paiements;
use App\Entity\TransactionOrangeMoney;
use App\Service\Paiement\OrangeMoney;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class OrangeMoneyTest extends TestCase
{
    private const LIEN = 'https://mpayment.orange-money.com/ml/mpayment/abstract/v1abc';

    /** @var list<array{url: string, corps: array<string, mixed>}> appels à l'API de paiement */
    private array $appels = [];

    /** @var list<object> */
    private array $persistes = [];

    /** @var array<string, mixed> réponse d'Orange à l'ouverture du paiement */
    private array $ouverture = ['status' => 201, 'message' => 'OK', 'pay_token' => 'pt-123', 'payment_url' => self::LIEN, 'notif_token' => 'jeton-secret-orange'];

    /** @var array<string, mixed> réponse d'Orange à la lecture de l'état */
    private array $etat = ['status' => 'PENDING'];

    public function testOuvreLePaiementEtNeGardeQueLEmpreinteDuJeton(): void
    {
        $commande = $this->commande();
        $transaction = $this->service()->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:00'));

        self::assertCount(1, $this->appels);
        $corps = $this->appels[0]['corps'];
        self::assertStringEndsWith('/webpayment', $this->appels[0]['url']);
        self::assertSame(['merchant_key' => 'cle-marchand', 'currency' => 'OUV', 'amount' => 25000, 'lang' => 'fr'], array_intersect_key($corps, array_flip(['merchant_key', 'currency', 'amount', 'lang'])));
        self::assertMatchesRegularExpression('/^SI[0-9A-F]{18}$/', $corps['order_id']); // forme attendue par la route de notification
        self::assertSame('https://si.ml/om/notification/'.$corps['order_id'], $corps['notif_url']);
        self::assertSame(self::LIEN, $transaction->getLienPaiement());
        self::assertSame([$transaction], $this->persistes);

        // Le jeton de notification n'est jamais gardé en clair.
        self::assertSame(hash('sha256', 'jeton-secret-orange'), (new \ReflectionProperty(TransactionOrangeMoney::class, 'notifEmpreinte'))->getValue($transaction));
        self::assertStringNotContainsString('jeton-secret-orange', serialize($transaction));
        self::assertTrue($transaction->notifTokenValide('jeton-secret-orange'));
    }

    public function testLienDePaiementEncoreValableRepris(): void
    {
        $commande = $this->commande();
        $ouverte = new TransactionOrangeMoney($commande, null, 25000, 'SI261013100000ABCDEF', new \DateTimeImmutable('2026-10-13 10:00'));
        $ouverte->ouvrir('pt', 'jeton', self::LIEN);

        self::assertSame($ouverte, $this->service([$ouverte])->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:05')));
        self::assertSame([], $this->appels);

        // Passé 10 minutes, un nouveau paiement est ouvert.
        self::assertNotSame($ouverte, $this->service([$ouverte])->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:11')));
    }

    public function testLienHorsOrangeMoneyRefuse(): void
    {
        $this->ouverture['payment_url'] = 'https://paiement.exemple.com/om';

        try {
            $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable());
            self::fail('Un lien hors orange-money.com ne doit pas être suivi.');
        } catch (\DomainException) {
        }
        self::assertSame([], $this->persistes);
        self::assertFalse(OrangeMoney::lienOrange('http://mpayment.orange-money.com/x'));
        self::assertFalse(OrangeMoney::lienOrange('https://orange-money.com.exemple.net/x'));
        self::assertTrue(OrangeMoney::lienOrange('https://orange-money.com/x'));
    }

    public function testRienAPayerOuCommandeNonConfirmee(): void
    {
        $this->expectException(\DomainException::class);
        $this->service()->demarrer($this->commande()->setStatut(false), new CompteClient(), new \DateTimeImmutable());
    }

    public function testSansClesLePaiementEnLigneResteFerme(): void
    {
        $service = $this->service([], '');
        self::assertFalse($service->estConfigure());
        $this->expectException(\DomainException::class);
        $service->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable());
    }

    public function testNotificationSansLeBonJetonIgnoree(): void
    {
        [$commande, $transaction] = $this->transactionOuverte();
        $service = $this->service([$transaction]);

        self::assertFalse($service->notification($transaction->getOrderId(), ['status' => 'SUCCESS', 'notif_token' => 'devine', 'txnid' => 'MP1'], new \DateTimeImmutable()));
        self::assertFalse($service->notification($transaction->getOrderId(), ['status' => 'SUCCESS', 'txnid' => 'MP1'], new \DateTimeImmutable()));
        self::assertSame(TransactionOrangeMoney::INITIEE, $transaction->getStatut());
        self::assertCount(0, $commande->getPaiements());
    }

    public function testArgentRecuDevientUnSeulPaiementAValider(): void
    {
        [$commande, $transaction] = $this->transactionOuverte();
        $service = $this->service([$transaction]);
        $notification = ['status' => 'SUCCESS', 'notif_token' => 'jeton-secret-orange', 'txnid' => 'MP261013.1000.A12345'];

        self::assertTrue($service->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable('2026-10-13 10:03')));
        // Rejouée, la notification ne crée rien de plus.
        self::assertTrue($service->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable('2026-10-13 10:04')));

        self::assertCount(1, $commande->getPaiements());
        $paiement = $commande->getPaiements()->first();
        self::assertInstanceOf(Paiements::class, $paiement);
        self::assertSame(Paiements::STATUT_EN_ATTENTE, $paiement->getStatut()); // jamais validé tout seul
        self::assertSame(Paiements::MODE_ORANGE_MONEY, $paiement->getMode());
        self::assertSame('OM MP261013.1000.A12345', $paiement->getReference());
        self::assertSame(TransactionOrangeMoney::REUSSIE, $transaction->getStatut());
        self::assertSame($paiement, $transaction->getPaiement());

        // La caisse n'a rien validé : le reste dû ne bouge pas, mais il n'est plus redemandé en ligne.
        self::assertSame(25000, $commande->getResteAPayer());
        self::assertSame(25000, OrangeMoney::enValidation($commande));
        self::assertSame(0, OrangeMoney::aPayer($commande));
    }

    public function testEchecEtExpirationNeCreentRien(): void
    {
        [$commande, $transaction] = $this->transactionOuverte();
        $this->service([$transaction])->notification($transaction->getOrderId(), ['status' => 'FAILED', 'notif_token' => 'jeton-secret-orange'], new \DateTimeImmutable());
        self::assertSame(TransactionOrangeMoney::ECHOUEE, $transaction->getStatut());

        [$commande2, $transaction2] = $this->transactionOuverte();
        $this->etat = ['status' => 'EXPIRED', 'order_id' => $transaction2->getOrderId()];
        self::assertSame(TransactionOrangeMoney::EXPIREE, $this->service([$transaction2])->verifier($transaction2, new \DateTimeImmutable()));

        self::assertCount(0, $commande->getPaiements());
        self::assertCount(0, $commande2->getPaiements());
    }

    public function testRetourDuClientLitLEtatChezOrange(): void
    {
        [$commande, $transaction] = $this->transactionOuverte();
        $this->etat = ['status' => 'SUCCESS', 'txnid' => 'MP9'];

        self::assertSame(TransactionOrangeMoney::REUSSIE, $this->service([$transaction])->verifier($transaction, new \DateTimeImmutable()));
        self::assertSame(['order_id' => $transaction->getOrderId(), 'amount' => 25000, 'pay_token' => 'pt-123'], $this->appels[0]['corps']);
        self::assertCount(1, $commande->getPaiements());
    }

    private function commande(): Commandes
    {
        return (new Commandes())->setNumero('CMD-D00766')->setTotalTtc(25000)->setStatut(true);
    }

    /** @return array{0: Commandes, 1: TransactionOrangeMoney} */
    private function transactionOuverte(): array
    {
        $commande = $this->commande();
        $transaction = new TransactionOrangeMoney($commande, null, 25000, 'SI261013100000'.strtoupper(bin2hex(random_bytes(3))), new \DateTimeImmutable('2026-10-13 10:00'));
        $transaction->ouvrir('pt-123', 'jeton-secret-orange', self::LIEN);

        return [$commande, $transaction];
    }

    /** @param list<TransactionOrangeMoney> $transactions */
    private function service(array $transactions = [], string $cleMarchand = 'cle-marchand'): OrangeMoney
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(static fn (array $critere): array => array_values(array_filter(
            $transactions,
            static fn (TransactionOrangeMoney $t): bool => $t->getCommande() === $critere['commande'] && $t->getStatut() === $critere['statut'],
        )));
        $repository->method('findOneBy')->willReturnCallback(static function (array $critere) use ($transactions): ?TransactionOrangeMoney {
            foreach ($transactions as $t) {
                if ($t->getOrderId() === $critere['orderId']) {
                    return $t;
                }
            }

            return null;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof TransactionOrangeMoney) {
                $this->persistes[] = $o;
            }
        });

        $http = new MockHttpClient(function (string $methode, string $url, array $options): MockResponse {
            if (str_contains($url, '/oauth/')) {
                return new MockResponse((string) json_encode(['access_token' => 'jeton-api', 'expires_in' => 3600]));
            }
            $this->appels[] = ['url' => $url, 'corps' => json_decode((string) $options['body'], true)];

            return new MockResponse((string) json_encode(str_ends_with($url, '/webpayment') ? $this->ouverture : $this->etat), ['http_code' => str_ends_with($url, '/webpayment') ? 201 : 200]);
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => match ($route) {
            'app_orange_money_notification' => 'https://si.ml/om/notification/'.$p['o'],
            default => 'https://si.ml/espace/'.$route,
        });

        return new OrangeMoney($em, $http, new ArrayAdapter(), $urls, new NullLogger(), 'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', 'id', 'secret', $cleMarchand);
    }
}
