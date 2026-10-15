<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Paiements;
use App\Entity\ParametresPaiement;
use App\Entity\TransactionOrangeMoney;
use App\Repository\ParametresPaiementRepository;
use App\Service\Paiement\FraisOrangeMoney;
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

    private int $codeOuverture = 201;

    /** @var list<string> jetons rendus par Orange, dans l'ordre (sinon « jeton-api ») */
    private array $jetons = [];

    private int $demandesJeton = 0;

    /** Jeton qu'Orange refuse à l'ouverture (403 insufficient scope). */
    private ?string $jetonRefuse = null;

    private ?ArrayAdapter $cache = null;

    private string $clientId = 'id';

    private ?string $fichierRefus = null;

    protected function tearDown(): void
    {
        if (null !== $this->fichierRefus && is_file($this->fichierRefus)) {
            unlink($this->fichierRefus);
            rmdir(\dirname($this->fichierRefus));
        }
    }

    public function testOuvreLePaiementEtNeGardeQueLEmpreinteDuJeton(): void
    {
        $commande = $this->commande();
        $transaction = $this->service()->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:00'));

        self::assertCount(1, $this->appels);
        $corps = $this->appels[0]['corps'];
        self::assertStringEndsWith('/webpayment', $this->appels[0]['url']);
        // Orange reçoit le total : 25 000 F + fonds de soutien 1 % + frais de retrait 1 % (taux par défaut).
        self::assertSame(['merchant_key' => 'cle-marchand', 'currency' => 'OUV', 'amount' => 25500, 'lang' => 'fr'], array_intersect_key($corps, array_flip(['merchant_key', 'currency', 'amount', 'lang'])));
        self::assertSame([25000, 250, 250, 25500], [$transaction->getMontant(), $transaction->getFondsSoutien(), $transaction->getFraisRetrait(), $transaction->getTotal()]);
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
        $ouverte = (new TransactionOrangeMoney($commande, null, 25000, 'SI261013100000ABCDEF', new \DateTimeImmutable('2026-10-13 10:00')))->avecFrais(250, 250);
        $ouverte->ouvrir('pt', 'jeton', self::LIEN);

        self::assertSame($ouverte, $this->service([$ouverte])->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:05')));
        self::assertSame([], $this->appels);

        // Taux changés entre-temps : le total n'est plus le même, un autre paiement est ouvert.
        $nouvelle = $this->service([$ouverte], taux: (new ParametresPaiement())->setTauxFraisRetrait(1.5))->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:05'));
        self::assertNotSame($ouverte, $nouvelle);
        self::assertSame(25625, $nouvelle->getTotal());
        self::assertSame(25625, $this->appels[0]['corps']['amount']);

        // Passé 10 minutes, un nouveau paiement est ouvert.
        self::assertNotSame($ouverte, $this->service([$ouverte])->demarrer($commande, new CompteClient(), new \DateTimeImmutable('2026-10-13 10:11')));
    }

    public function testTauxAZeroSansFrais(): void
    {
        $transaction = $this->service(taux: (new ParametresPaiement())->setTauxFondsSoutien(0)->setTauxFraisRetrait(0))
            ->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable('2026-10-13 10:00'));

        self::assertSame(25000, $this->appels[0]['corps']['amount']);
        self::assertSame(25000, $transaction->getTotal());
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

    public function testRefusDOrangeNotePourLeTesteurSansSecret(): void
    {
        $this->fichierRefus = sys_get_temp_dir().'/om-refus-'.bin2hex(random_bytes(4)).'/refus.json';
        $this->codeOuverture = 403;
        $this->ouverture = ['code' => 53, 'message' => 'Insufficient scope', 'description' => 'Authorization features insufficient scope (Your scope is not allowed)'];

        try {
            $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable());
            self::fail('Un refus d\'Orange doit être signalé au client.');
        } catch (\DomainException $e) {
            self::assertSame('Orange Money a refusé d\'ouvrir le paiement. Réessayez ou payez en boutique.', $e->getMessage());
        }

        $refus = $this->service()->derniersRefus();
        self::assertCount(1, $refus);
        self::assertMatchesRegularExpression('/^SI[0-9A-F]{18}$/', $refus[0]['reference']);
        // Clés de production avec l'adresse d'essai : le cas vécu le 15/10.
        self::assertSame('refusé par Orange (HTTP 403, Insufficient scope : Authorization features insufficient scope (Your scope is not allowed)) : ces clés n\'ont pas l\'API d\'essai ; avec des clés de production au Mali, mettre ORANGE_MONEY_API=https://api.orange.com/orange-money-webpay/ml/v1 et ORANGE_MONEY_DEVISE=XOF', $refus[0]['raison']);
        self::assertStringEndsWith('avec la clé marchand de la même application', OrangeMoney::raisonRefus(403, ['message' => 'Insufficient scope'], '', 'https://api.orange.com/orange-money-webpay/ml/v1'));
        $contenu = (string) file_get_contents($this->fichierRefus);
        foreach (['cle-marchand', 'secret', 'jeton-api', '25500'] as $interdit) {
            self::assertStringNotContainsString($interdit, $contenu, 'ni clé, ni jeton, ni montant dans le fichier des refus');
        }
        self::assertSame([], $this->persistes);
    }

    public function testJetonGardeDAvantUnChangementChezOrangeRenouveleUneFois(): void
    {
        // Jeton pris avant que l'API soit ajoutée à l'application : Orange répond 403, un jeton neuf passe.
        $this->cache = new ArrayAdapter();
        $this->jetons = ['jeton-ancien', 'jeton-neuf'];
        $this->jetonRefuse = 'jeton-ancien';

        $transaction = $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable('2026-10-13 10:00'));

        self::assertSame(self::LIEN, $transaction->getLienPaiement());
        self::assertSame(2, $this->demandesJeton);
        self::assertCount(2, $this->appels);

        // Le jeton neuf est gardé pour les paiements suivants.
        $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable('2026-10-13 10:05'));
        self::assertSame(2, $this->demandesJeton);
    }

    public function testRefusQuiDureUnSeulNouvelEssai(): void
    {
        $this->codeOuverture = 403;
        $this->ouverture = ['code' => 53, 'message' => 'Insufficient scope'];

        try {
            $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable());
            self::fail('Un refus d\'Orange doit être signalé au client.');
        } catch (\DomainException) {
        }

        self::assertCount(2, $this->appels);
        self::assertSame(2, $this->demandesJeton);
    }

    public function testChangerLesClesNeReprendPasLAncienJeton(): void
    {
        $this->cache = new ArrayAdapter();
        $this->jetons = ['jeton-a', 'jeton-b'];
        $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable('2026-10-13 10:00'));

        $this->clientId = 'autre-application';
        $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable('2026-10-13 10:01'));
        self::assertSame(2, $this->demandesJeton);
    }

    public function testSeulsLesCinqDerniersRefusSontGardes(): void
    {
        $this->fichierRefus = sys_get_temp_dir().'/om-refus-'.bin2hex(random_bytes(4)).'/refus.json';
        $this->codeOuverture = 400;
        $references = [];
        for ($i = 0; $i < 6; ++$i) {
            $this->ouverture = ['message' => 'Essai '.$i];
            try {
                $this->service()->demarrer($this->commande(), new CompteClient(), new \DateTimeImmutable());
            } catch (\DomainException) {
            }
        }

        $refus = $this->service()->derniersRefus();
        self::assertCount(5, $refus);
        self::assertSame('refusé par Orange (HTTP 400, Essai 5)', $refus[0]['raison'], 'le plus récent d\'abord');
        self::assertSame('refusé par Orange (HTTP 400, Essai 1)', $refus[4]['raison']);
    }

    public function testRaisonDUneReponseIncomplete(): void
    {
        self::assertSame(
            'réponse d\'Orange incomplète (HTTP 201) : notif_token absent, payment_url hors d\'Orange Money (https://paiement.exemple.com)',
            OrangeMoney::raisonRefus(201, ['pay_token' => 'pt'], 'https://paiement.exemple.com/om?jeton=x'),
        );
        self::assertSame('réponse d\'Orange incomplète (HTTP 200) : pay_token absent, notif_token absent, payment_url absente', OrangeMoney::raisonRefus(200, [], ''));
        self::assertSame('refusé par Orange (HTTP 401, invalid_client)', OrangeMoney::raisonRefus(401, ['error' => 'invalid_client'], ''));
        self::assertSame([], $this->service()->derniersRefus(), 'sans fichier, aucun refus');
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
        // Les frais vont sur le paiement, jamais dans la part de la commande.
        self::assertSame(25000, $paiement->getMontant());
        self::assertSame([true, 250, true, 250], [$paiement->isFondsSoutienInclus(), $paiement->getMontantFondsSoutien(), $paiement->isFraisRetraitInclus(), $paiement->getMontantFraisRetrait()]);
        self::assertSame(25500, $paiement->getMontantTotalEncaisse());
        self::assertSame(25000, $commande->getTotalTtc(), 'le prix de la commande ne change pas');
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
        // L'état se lit avec le montant envoyé à Orange : frais compris.
        self::assertSame(['order_id' => $transaction->getOrderId(), 'amount' => 25500, 'pay_token' => 'pt-123'], $this->appels[0]['corps']);
        self::assertCount(1, $commande->getPaiements());
    }

    public function testDiagnosticSignaleLaCleMarchandVideSansMontrerDeSecret(): void
    {
        $etapes = $this->diagnostic(['merchant' => ''])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame(['etape' => 'Clés dans .env.local', 'ok' => false, 'detail' => 'vide : ORANGE_MONEY_MERCHANT_KEY'], $etapes[0]);
        self::assertSame(['Clés dans .env.local' => false, 'Adresse de l\'API' => true, 'Frais Orange Money' => true, 'Jeton Orange' => true], array_column($etapes, 'ok', 'etape'));
        self::assertCount(1, $this->appels, 'sans clé marchand, aucun paiement d\'essai n\'est ouvert');
        self::assertStringNotContainsString('secret-om', serialize($etapes));
    }

    public function testDiagnosticDonneLaRaisonDuRefusDOrange(): void
    {
        $etapes = $this->diagnostic(['jeton' => new MockResponse('{"error":"invalid_client","error_description":"The requested service needs credentials, but the ones provided were invalid."}', ['http_code' => 401])])
            ->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertFalse($etapes[3]['ok']);
        self::assertSame('refusé par Orange (HTTP 401, invalid_client : The requested service needs credentials, but the ones provided were invalid.)', $etapes[3]['detail']);
        self::assertCount(4, $etapes, 'sans jeton, pas de paiement d\'essai');
    }

    public function testDiagnosticRepereUnEnTeteCollePourUneCle(): void
    {
        $etapes = $this->diagnostic(['secret' => 'Basic aWQ6c2VjcmV0'])->diagnostic(false, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertContains(['etape' => 'ORANGE_MONEY_CLIENT_SECRET', 'ok' => false, 'detail' => 'contient un en-tête Authorization : mettre seulement la clé'], $etapes);
    }

    public function testDiagnosticOuvreUnPaiementDEssaiDeCentOuv(): void
    {
        $etapes = $this->diagnostic([])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame([true, true, true, true, true], array_column($etapes, 'ok'));
        // Les frais d'un paiement de 100, puis le paiement d'essai ouvert frais compris, comme pour un client.
        self::assertSame(['etape' => 'Frais Orange Money', 'ok' => true, 'detail' => '100 OUV + fonds de soutien 1 % (1) + frais de retrait 1 % (1) = 102 OUV payés par le client'], $etapes[2]);
        self::assertSame('clé marchand acceptée, page de paiement de 102 OUV (frais compris) : '.self::LIEN, $etapes[4]['detail']);
        $corps = $this->appels[1]['corps'];
        self::assertSame(['merchant_key' => 'cle-om', 'currency' => 'OUV', 'amount' => 102], array_intersect_key($corps, array_flip(['merchant_key', 'currency', 'amount'])));
        self::assertMatchesRegularExpression('/^SI[0-9A-F]{18}$/', $corps['order_id']);
        self::assertSame([], $this->persistes, 'l\'essai n\'enregistre aucune transaction');
    }

    public function testDiagnosticNOuvreJamaisDePaiementEnProduction(): void
    {
        $etapes = $this->diagnostic(['api' => 'https://api.orange.com/orange-money-webpay/ml/v1', 'devise' => 'XOF'])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame(['etape' => 'Paiement d\'essai', 'ok' => false, 'detail' => 'seulement avec l\'API d\'essai (dev), jamais en production'], $etapes[4]);
        self::assertCount(1, $this->appels, 'seul le jeton est demandé');
    }

    public function testDiagnosticMontreLesFraisSansCleNiSecret(): void
    {
        $etapes = $this->diagnostic(['secret' => '', 'merchant' => ''])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        // Sans clé : rien n'est demandé à Orange, mais le calcul des frais est montré.
        self::assertSame([], $this->appels);
        self::assertSame('Frais Orange Money', $etapes[2]['etape']);
        self::assertStringContainsString('= 102 OUV payés par le client', $etapes[2]['detail']);
        self::assertSame('100 OUV + frais de retrait 2 % (2) = 102 OUV payés par le client', OrangeMoney::detailFrais(FraisOrangeMoney::calculer(100, (new ParametresPaiement())->setTauxFondsSoutien(0)->setTauxFraisRetrait(2)), 'OUV'), 'un taux à 0 n\'a pas de ligne');
    }

    public function testDiagnosticSignaleUneDeviseQuiNeVaPasAvecLAPI(): void
    {
        $etapes = $this->diagnostic(['devise' => 'XOF'])->diagnostic(false, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertSame(['etape' => 'Adresse de l\'API', 'ok' => false, 'detail' => 'essai (dev), devise XOF (attendue : OUV)'], $etapes[1]);
    }

    public function testDepuisLocalhostLEssaiRenvoieVersExampleCom(): void
    {
        $etapes = $this->diagnostic(['base' => 'http://localhost:8000/'])->diagnostic(true, new \DateTimeImmutable('2026-10-14 16:00'));

        self::assertStringEndsWith('(retour vers example.com : Orange refuse localhost)', $etapes[4]['detail']);
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
        $transaction = (new TransactionOrangeMoney($commande, null, 25000, 'SI261013100000'.strtoupper(bin2hex(random_bytes(3))), new \DateTimeImmutable('2026-10-13 10:00')))->avecFrais(250, 250);
        $transaction->ouvrir('pt-123', 'jeton-secret-orange', self::LIEN);

        return [$commande, $transaction];
    }

    /** @param list<TransactionOrangeMoney> $transactions */
    private function service(array $transactions = [], string $cleMarchand = 'cle-marchand', string $espace = 'https://si.ml/espace/', ?ParametresPaiement $taux = null): OrangeMoney
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
                ++$this->demandesJeton;

                return new MockResponse((string) json_encode(['access_token' => array_shift($this->jetons) ?? 'jeton-api', 'expires_in' => 3600]));
            }
            $this->appels[] = ['url' => $url, 'corps' => json_decode((string) $options['body'], true)];
            if (null !== $this->jetonRefuse && \in_array('Authorization: Bearer '.$this->jetonRefuse, $options['headers'], true)) {
                return new MockResponse((string) json_encode(['code' => 53, 'message' => 'Insufficient scope']), ['http_code' => 403]);
            }

            return new MockResponse((string) json_encode(str_ends_with($url, '/webpayment') ? $this->ouverture : $this->etat), ['http_code' => str_ends_with($url, '/webpayment') ? $this->codeOuverture : 200]);
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p): string => match ($route) {
            'app_orange_money_notification' => 'https://si.ml/om/notification/'.$p['o'],
            default => $espace.$route,
        });

        $parametres = null;
        if (null !== $taux) {
            $parametres = $this->createStub(ParametresPaiementRepository::class);
            $parametres->method('recuperer')->willReturn($taux);
        }

        return new OrangeMoney($em, $http, $this->cache ?? new ArrayAdapter(), $urls, new NullLogger(), 'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', $this->clientId, 'secret', $cleMarchand, null, $parametres, $this->fichierRefus);
    }
}
