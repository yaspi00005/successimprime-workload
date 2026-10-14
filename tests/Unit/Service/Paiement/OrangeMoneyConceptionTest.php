<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\MouvementCreditIa;
use App\Entity\Paiements;
use App\Entity\SupportConception;
use App\Entity\TransactionOrangeMoney;
use App\Service\Conception\CommandesConception;
use App\Service\Conception\CreditsIa;
use App\Service\Paiement\OrangeMoney;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Orange Money pour la demande de conception : acompte du graphiste et
 * pack de crédit IA, avec les mêmes protections que le paiement d'une
 * commande (jeton de notification, lien Orange vérifié, paiement « à
 * valider »). La commande naît quand l'argent est reçu.
 */
final class OrangeMoneyConceptionTest extends TestCase
{
    private const LIEN = 'https://mpayment.orange-money.com/ml/mpayment/abstract/v1dc';

    /** @var list<array{url: string, corps: array<string, mixed>}> */
    private array $appels = [];

    /** @var list<TransactionOrangeMoney> */
    private array $transactions = [];

    /** @var list<object> */
    private array $persistes = [];

    /** @var array<string, string> adresses générées, par route */
    private array $routes = [];

    private Clients $client;

    protected function setUp(): void
    {
        $this->client = (new Clients())->setNom('Hôtel Niger')->setTypeClient('B2C');
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($this->client, 7);
    }

    public function testAcompteOuvertChezOrangeSansCommande(): void
    {
        $demande = $this->demande();
        $transaction = $this->service()->demarrerAcompte($demande, $this->compte(), 5000, new \DateTimeImmutable('2026-10-14 14:00'));

        self::assertSame(TransactionOrangeMoney::OBJET_ACOMPTE_CONCEPTION, $transaction->getObjet());
        self::assertNull($transaction->getCommande());
        self::assertSame($demande, $transaction->getDemandeConception());
        self::assertSame($this->client, $transaction->getClient());
        self::assertSame(5000, $this->appels[0]['corps']['amount']);
        self::assertSame(self::LIEN, $transaction->getLienPaiement());
        self::assertSame(hash('sha256', 'jeton-dc'), (new \ReflectionProperty(TransactionOrangeMoney::class, 'notifEmpreinte'))->getValue($transaction));
        self::assertStringContainsString('app_espace_conception_retour', $this->appels[0]['corps']['return_url']);
        self::assertStringContainsString('app_espace_conception_informations', $this->appels[0]['corps']['cancel_url']);

        // Même demande dans les 10 minutes : le même lien.
        self::assertSame($transaction, $this->service()->demarrerAcompte($demande, $this->compte(), 5000, new \DateTimeImmutable('2026-10-14 14:06')));
        self::assertCount(1, $this->appels);
    }

    public function testAcompteRecuDevientCommandeConfirmeeEtPaiementAValider(): void
    {
        $demande = $this->demande();
        $service = $this->service();
        $transaction = $service->demarrerAcompte($demande, $this->compte(), 5000, new \DateTimeImmutable('2026-10-14 14:00'));
        $notification = ['status' => 'SUCCESS', 'notif_token' => 'jeton-dc', 'txnid' => 'MP261014.1402.B1'];

        self::assertTrue($service->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable('2026-10-14 14:02')));
        self::assertTrue($service->notification($transaction->getOrderId(), $notification, new \DateTimeImmutable('2026-10-14 14:03')));

        $commande = $transaction->getCommande();
        self::assertInstanceOf(Commandes::class, $commande);
        self::assertTrue($commande->isStatut()); // prix fixé par les réglages : confirmée
        self::assertSame(Commandes::CANAL_EN_LIGNE, $commande->getCanal());
        $ligne = $commande->getCommandesDetails()->first();
        self::assertInstanceOf(CommandesDetails::class, $ligne);
        self::assertSame('Conception · Kakémono 85 × 200 cm', $ligne->getDesignation());
        self::assertSame(10000, $commande->getTotalTtc()); // le forfait entier ; le solde se paie au BAT
        self::assertTrue($ligne->isPrePresseNecessaire());

        self::assertCount(1, $commande->getPaiements());
        $paiement = $commande->getPaiements()->first();
        self::assertInstanceOf(Paiements::class, $paiement);
        self::assertSame(5000, $paiement->getMontant());
        self::assertSame(Paiements::STATUT_EN_ATTENTE, $paiement->getStatut());

        self::assertSame(DemandeConception::RECUE, $demande->getStatut());
        self::assertSame($commande, $demande->getCommande());
        self::assertSame($paiement, $demande->getPaiementAcompte());
    }

    public function testPackPayeCreditUtilisableEtPaiementAValider(): void
    {
        $service = $this->service();
        $transaction = $service->demarrerCredit($this->client, $this->compte(), 3, 3000, new \DateTimeImmutable('2026-10-14 15:00'), 'abc');
        self::assertSame(TransactionOrangeMoney::OBJET_CREDIT_IA, $transaction->getObjet());
        self::assertSame(3, $transaction->getPackImages());
        self::assertStringContainsString('demande=abc', $this->appels[0]['corps']['return_url']);

        // Lien encore valable pour le même pack : repris ; un autre pack ouvre un autre paiement.
        self::assertSame($transaction, $service->demarrerCredit($this->client, $this->compte(), 3, 3000, new \DateTimeImmutable('2026-10-14 15:05')));
        self::assertNotSame($transaction, $service->demarrerCredit($this->client, $this->compte(), 5, 5000, new \DateTimeImmutable('2026-10-14 15:05')));

        $service->notification($transaction->getOrderId(), ['status' => 'SUCCESS', 'notif_token' => 'jeton-dc', 'txnid' => 'MP3'], new \DateTimeImmutable('2026-10-14 15:02'));

        $commande = $transaction->getCommande();
        self::assertInstanceOf(Commandes::class, $commande);
        self::assertSame('Crédit IA · 3 images', $commande->getCommandesDetails()->first()->getDesignation());
        self::assertFalse($commande->getCommandesDetails()->first()->isPrePresseNecessaire());
        $achats = array_values(array_filter($this->persistes, static fn (object $o): bool => $o instanceof MouvementCreditIa));
        self::assertCount(1, $achats);
        self::assertSame(3000, $achats[0]->getMontant());
        self::assertTrue($achats[0]->estAcquis());
        self::assertSame(Paiements::STATUT_EN_ATTENTE, $transaction->getPaiement()?->getStatut());
    }

    public function testEchecNeCreeNiCommandeNiCredit(): void
    {
        $service = $this->service();
        $transaction = $service->demarrerCredit($this->client, $this->compte(), 1, 1000, new \DateTimeImmutable());
        $service->notification($transaction->getOrderId(), ['status' => 'FAILED', 'notif_token' => 'jeton-dc'], new \DateTimeImmutable());
        // Faux jeton : ignoré.
        self::assertFalse($service->notification($transaction->getOrderId(), ['status' => 'SUCCESS', 'notif_token' => 'devine'], new \DateTimeImmutable()));

        self::assertSame(TransactionOrangeMoney::ECHOUEE, $transaction->getStatut());
        self::assertNull($transaction->getCommande());
        self::assertSame([], array_filter($this->persistes, static fn (object $o): bool => $o instanceof MouvementCreditIa || $o instanceof Commandes));
    }

    public function testDemandeDejaPartieOuSansMontantRefusee(): void
    {
        $demande = $this->demande();
        $demande->envoyer(DemandeConception::RECUE, new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $this->service()->demarrerAcompte($demande, $this->compte(), 5000, new \DateTimeImmutable());
    }

    private function demande(): DemandeConception
    {
        $support = (new SupportConception())->setCode('kakemono')->setNom('Kakémono / roll-up')->setCourt('Kakémono')->setFormat('85 × 200 cm')->setLargeurCm(85)->setHauteurCm(200)->setForfait(10000);
        $demande = DemandeConception::nouvelle($this->client, null, $support, new \DateTimeImmutable('2026-10-14 13:50'));
        $demande->setNumero('DC-2026-014');
        $demande->ecrireContenu('Soirée jazz', null, null, null, null);

        return $demande;
    }

    private function compte(): CompteClient
    {
        return (new CompteClient())->setClient($this->client)->setNom('Ibrahim');
    }

    private function service(): OrangeMoney
    {
        $transactions = $this->createStub(EntityRepository::class);
        $transactions->method('findBy')->willReturnCallback(fn (array $critere): array => array_values(array_filter(
            $this->transactions,
            static function (TransactionOrangeMoney $t) use ($critere): bool {
                foreach ($critere as $champ => $valeur) {
                    $lu = match ($champ) {
                        'demandeConception' => $t->getDemandeConception(),
                        'client' => $t->getClient(),
                        'objet' => $t->getObjet(),
                        'packImages' => $t->getPackImages(),
                        'statut' => $t->getStatut(),
                        default => throw new \LogicException($champ),
                    };
                    if ($lu !== $valeur) {
                        return false;
                    }
                }

                return true;
            },
        )));
        $transactions->method('findOneBy')->willReturnCallback(function (array $critere): ?object {
            foreach ($this->transactions as $t) {
                if ($t->getOrderId() === ($critere['orderId'] ?? null)) {
                    return $t;
                }
            }

            return null; // mouvement de crédit : aucun encore
        });

        $connexion = $this->createStub(Connection::class);
        $connexion->method('executeStatement')->willReturn(1);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($transactions);
        $em->method('getConnection')->willReturn($connexion);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
            if ($o instanceof TransactionOrangeMoney) {
                $this->transactions[] = $o;
            }
        });

        $http = new MockHttpClient(function (string $methode, string $url, array $options): MockResponse {
            if (str_contains($url, '/oauth/')) {
                return new MockResponse((string) json_encode(['access_token' => 'jeton-api', 'expires_in' => 3600]));
            }
            $this->appels[] = ['url' => $url, 'corps' => json_decode((string) $options['body'], true)];

            return new MockResponse((string) json_encode(['status' => 201, 'pay_token' => 'pt-dc', 'payment_url' => self::LIEN, 'notif_token' => 'jeton-dc']), ['http_code' => 201]);
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p = []): string => 'https://si.ml/'.$route.([] !== $p ? '?'.http_build_query($p) : ''));

        return new OrangeMoney($em, $http, new ArrayAdapter(), $urls, new NullLogger(), 'https://api.orange.com/orange-money-webpay/dev/v1', 'OUV', 'id', 'secret', 'cle-marchand', new CommandesConception($em, new CreditsIa($em)));
    }
}
