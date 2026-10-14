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

    public function testDiagnosticSignaleLaCleMarchandVideSansMontrerDeSecret(): void
    {
        $etapes = $this->diagnostic(['merchant' => ''])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame(['etape' => 'Clés dans .env.local', 'ok' => false, 'detail' => 'vide : ORANGE_MONEY_MERCHANT_KEY'], $etapes[0]);
        self::assertSame(['Clés dans .env.local' => false, 'Adresse de l\'API' => true, 'Jeton Orange' => true], array_column($etapes, 'ok', 'etape'));
        self::assertCount(1, $this->appels, 'sans clé marchand, aucun paiement d\'essai n\'est ouvert');
        self::assertStringNotContainsString('secret-om', serialize($etapes));
    }

    public function testDiagnosticDonneLaRaisonDuRefusDOrange(): void
    {
        $etapes = $this->diagnostic(['jeton' => new MockResponse('{"error":"invalid_client","error_description":"The requested service needs credentials, but the ones provided were invalid."}', ['http_code' => 401])])
            ->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertFalse($etapes[2]['ok']);
        self::assertSame('refusé par Orange (HTTP 401, invalid_client : The requested service needs credentials, but the ones provided were invalid.)', $etapes[2]['detail']);
        self::assertCount(3, $etapes, 'sans jeton, pas de paiement d\'essai');
    }

    public function testDiagnosticRepereUnEnTeteCollePourUneCle(): void
    {
        $etapes = $this->diagnostic(['secret' => 'Basic aWQ6c2VjcmV0'])->diagnostic(false, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertContains(['etape' => 'ORANGE_MONEY_CLIENT_SECRET', 'ok' => false, 'detail' => 'contient un en-tête Authorization : mettre seulement la clé'], $etapes);
    }

    public function testDiagnosticOuvreUnPaiementDEssaiDeCentOuv(): void
    {
        $etapes = $this->diagnostic([])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame([true, true, true, true], array_column($etapes, 'ok'));
        self::assertSame('clé marchand acceptée, page de paiement de 100 OUV : '.self::LIEN, $etapes[3]['detail']);
        $corps = $this->appels[1]['corps'];
        self::assertSame(['merchant_key' => 'cle-om', 'currency' => 'OUV', 'amount' => 100], array_intersect_key($corps, array_flip(['merchant_key', 'currency', 'amount'])));
        self::assertMatchesRegularExpression('/^SI[0-9A-F]{18}$/', $corps['order_id']);
        self::assertSame([], $this->persistes, 'l\'essai n\'enregistre aucune transaction');
    }

    public function testDiagnosticNOuvreJamaisDePaiementEnProduction(): void
    {
        $etapes = $this->diagnostic(['api' => 'https://api.orange.com/orange-money-webpay/ml/v1', 'devise' => 'XOF'])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame(['etape' => 'Paiement d\'essai', 'ok' => false, 'detail' => 'seulement avec l\'API d\'essai (dev), jamais en production'], $etapes[3]);
        self::assertCount(1, $this->appels, 'seul le jeton est demandé');
    }

    public function testDiagnosticSignaleUneDeviseQuiNeVaPasAvecLAPI(): void
    {
        $etapes = $this->diagnostic(['devise' => 'XOF'])->diagnostic(false, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame(['etape' => 'Adresse de l\'API', 'ok' => false, 'detail' => 'essai (dev), devise XOF (attendue : OUV)'], $etapes[1]);
    }

    public function testDepuisLocalhostLEssaiRenvoieVersExampleCom(): void
    {
        $etapes = $this->diagnostic(['base' => 'http://localhost:8000/'])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertStringEndsWith('(retour vers example.com : Orange refuse localhost)', $etapes[3]['detail']);
        $corps = $this->appels[1]['corps'];
        self::assertSame('https://example.com/', $corps['return_url']);
        self::assertSame('https://example.com/om/notification/'.$corps['order_id'], $corps['notif_url']);
    }

    public function testPaiementDepuisLocalhostRefuseSansAppelerOrange(): void
    {
        $service = $this->service([], 'cle-marchand', 'http://localhost:8000/espace/');

        $this->expectExceptionMessage('Orange Money refuse les adresses localhost');
        try {
            $service->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable('2026-10-13 10:00'));
        } finally {
            self::assertSame([], $this->appels);
        }
    }

    public function testAdresseLocale(): void
    {
        foreach (['http://localhost/', 'https://localhost:8000/espace', 'http://127.0.0.1:8000/', 'http://[::1]/', 'http://si.localhost/'] as $url) {
            self::assertTrue(OrangeMoney::adresseLocale($url), $url);
        }
        foreach (['https://succes-imprim.ml/espace', 'https://abc.trycloudflare.com/', 'http://192.168.1.10/'] as $url) {
            self::assertFalse(OrangeMoney::adresseLocale($url), $url);
        }
    }

    /** @param array{api?: string, devise?: string, secret?: string, merchant?: string, jeton?: MockResponse, base?: string} $o */
    private function diagnostic(array $o): OrangeMoney
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $p): void {
            $this->persistes[] = $p;
        });
        $http = new MockHttpClient(function (string $methode, string $url, array $options) use ($o): MockResponse {
            $this->appels[] = ['url' => $url, 'corps' => str_contains($url, '/oauth/') ? [] : json_decode((string) $options['body'], true)];

            return str_contains($url, '/oauth/')
                ? ($o['jeton'] ?? new MockResponse('{"access_token":"jeton-api","expires_in":3600}'))
                : new MockResponse((string) json_encode($this->ouverture), ['http_code' => 201]);
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => ($o['base'] ?? 'https://si.ml/').$route.(isset($p['o']) ? '/'.$p['o'] : ''));

        return new OrangeMoney($em, $http, new ArrayAdapter(), $urls, new NullLogger(), $o['api'] ?? 'https://api.orange.com/orange-money-webpay/dev/v1', $o['devise'] ?? 'OUV', 'id-om', $o['secret'] ?? 'secret-om', $o['merchant'] ?? 'cle-om');
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
    private function service(array $transactions = [], string $cleMarchand = 'cle-marchand', string $espace = 'https://si.ml/espace/'): OrangeMoney
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
            default => $espace.$route,
        });

        return new OrangeMoney($em, $http, new ArrayAdapter(), $urls, new NullLogger(), 'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', 'id', 'secret', $cleMarchand);
    }
}
