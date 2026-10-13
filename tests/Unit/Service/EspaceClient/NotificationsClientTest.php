<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\AbonnementPushClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\NotificationClientEnvoyee;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\OrangeSmsService;
use App\Service\Push\WebPush;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NotificationsClientTest extends TestCase
{
    /** @var list<string> SMS partis */
    private array $sms = [];

    /** @var list<string> corps des notifications push parties (chiffrés) */
    private array $push = [];

    /** @var list<NotificationClientEnvoyee> */
    private array $journal = [];

    public function testSansApplicationLeSmsPartUneSeuleFoisEtSansMontant(): void
    {
        $client = $this->client();
        $service = $this->service([$this->compte($client)]);
        $fil = [self::evenement('paiement', 'Paiement reçu', '25 000 F · Orange Money · commande CMD-D00766', '2026-10-13 10:00', 'CMD-D00766')];

        $envoyees = $service->envoyerFil($client, $fil, new \DateTimeImmutable('2026-10-13 10:05'));

        self::assertSame([['type' => 'paiement', 'canal' => NotificationClientEnvoyee::SMS]], $envoyees);
        self::assertSame(['Succès Imprim : Paiement reçu pour la commande CMD-D00766. Merci ! Détails dans votre espace client.'], $this->sms);
        self::assertStringNotContainsString('25 000', $this->sms[0]);

        // Le même événement ne repart pas.
        self::assertSame([], $service->envoyerFil($client, $fil, new \DateTimeImmutable('2026-10-13 10:10')));
        self::assertCount(1, $this->sms);
    }

    public function testEvenementAncienOuImpressionNePartentPas(): void
    {
        $client = $this->client();
        $service = $this->service([$this->compte($client)]);
        $fil = [
            self::evenement('impression', 'Impression lancée', 'Commande CMD-1', '2026-10-13 09:00', 'CMD-1'),
            self::evenement('prete', 'Commande prête', 'CMD-2 · à retirer en boutique', '2026-10-12 08:00', 'CMD-2'),
        ];

        self::assertSame([], $service->envoyerFil($client, $fil, new \DateTimeImmutable('2026-10-13 10:00')));
        self::assertSame([], $this->sms);
    }

    public function testFicheDeDemonstrationJamaisDeSms(): void
    {
        $client = $this->client()->setCode('DEMO-0020');
        $service = $this->service([$this->compte($client)]);

        $envoyees = $service->envoyerFil($client, [self::evenement('bat', 'BAT prêt à valider', 'Vitrine', '2026-10-13 10:00', 'CMD-D00766')], new \DateTimeImmutable('2026-10-13 10:01'));

        self::assertSame([['type' => 'bat', 'canal' => NotificationClientEnvoyee::AUCUN]], $envoyees);
        self::assertSame([], $this->sms);
    }

    public function testApplicationInstalleeAvantLeSms(): void
    {
        $client = $this->client();
        $compte = $this->compte($client);
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => \OPENSSL_KEYTYPE_EC]);
        $cle = false !== $paire ? openssl_pkey_get_details($paire) : throw new \RuntimeException('OpenSSL sans courbe P-256');
        $publique = "\x04".str_pad($cle['ec']['x'], 32, "\0", \STR_PAD_LEFT).str_pad($cle['ec']['y'], 32, "\0", \STR_PAD_LEFT);
        $abonnement = new AbonnementPushClient($compte, 'https://fcm.googleapis.com/fcm/send/abc', WebPush::b64e($publique), WebPush::b64e(random_bytes(16)), 'Android', new \DateTimeImmutable());
        $service = $this->service([$compte], [$abonnement]);

        $envoyees = $service->envoyerFil($client, [self::evenement('prete', 'Commande prête', 'CMD-D00766 · livraison à venir', '2026-10-13 10:00', 'CMD-D00766')], new \DateTimeImmutable('2026-10-13 10:01'));

        self::assertSame([['type' => 'prete', 'canal' => NotificationClientEnvoyee::PUSH]], $envoyees);
        self::assertCount(1, $this->push);
        self::assertSame([], $this->sms);
    }

    public function testPaiementPasEnvoyeAuDemandeurSurSonTelephone(): void
    {
        $client = $this->client();
        $demandeur = $this->compte($client)->setDroits([]);
        $client->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $abonnement = new AbonnementPushClient($demandeur, 'https://fcm.googleapis.com/fcm/send/xyz', 'cle', 'secret', null, new \DateTimeImmutable());
        $service = $this->service([$demandeur], [$abonnement]);

        // Pas de payeur avec l'application : le SMS part sur le numéro de l'entreprise.
        $envoyees = $service->envoyerFil($client, [self::evenement('paiement', 'Paiement reçu', '10 000 F', '2026-10-13 10:00', 'CMD-9')], new \DateTimeImmutable('2026-10-13 10:01'));

        self::assertSame(NotificationClientEnvoyee::SMS, $envoyees[0]['canal']);
        self::assertSame([], $this->push);
    }

    public function testMessages(): void
    {
        self::assertSame('Votre commande CMD-1 est prête à retirer en boutique.', NotificationsClient::message(['type' => 'prete', 'texte' => 'CMD-1 · à retirer en boutique', 'numero' => 'CMD-1']));
        self::assertSame('Votre commande CMD-1 est prête : la livraison arrive bientôt.', NotificationsClient::message(['type' => 'prete', 'texte' => 'CMD-1 · livraison à venir', 'numero' => 'CMD-1']));
        self::assertSame('Nous avons répondu à votre réclamation R-0002.', NotificationsClient::message(['type' => 'reclamation', 'texte' => 'R-0002 · Couleur', 'numero' => 'R-0002']));
        // Une nouvelle version du BAT est un nouvel événement.
        $v1 = self::evenement('bat', 'BAT prêt à valider', 'x', '2026-10-13 10:00', 'CMD-1');
        $v2 = self::evenement('bat', 'BAT prêt à valider', 'x', '2026-10-13 11:00', 'CMD-1');
        self::assertNotSame(NotificationsClient::cle($v1), NotificationsClient::cle($v2));
    }

    /** @return array{type: string, titre: string, texte: string, date: \DateTimeImmutable, lien: array{0: string, 1: array<string, mixed>}, numero: string} */
    private static function evenement(string $type, string $titre, string $texte, string $date, string $numero): array
    {
        return ['type' => $type, 'titre' => $titre, 'texte' => $texte, 'date' => new \DateTimeImmutable($date), 'lien' => ['app_espace_commande', ['id' => 'abc']], 'numero' => $numero];
    }

    private function client(): Clients
    {
        $client = (new Clients())->setTelephone('76000001')->setRecevoirSms(true)->setCode('C-0001');
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($client, 2);

        return $client;
    }

    private function compte(Clients $client): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits([CompteClient::DROIT_ADMINISTRATEUR, CompteClient::DROIT_PAYEUR, CompteClient::DROIT_VALIDEUR]);
    }

    /**
     * @param list<CompteClient>          $comptes
     * @param list<AbonnementPushClient> $abonnements
     */
    private function service(array $comptes, array $abonnements = []): NotificationsClient
    {
        $journal = $this->createStub(EntityRepository::class);
        $journal->method('findBy')->willReturnCallback(fn (): array => $this->journal);
        $repoComptes = $this->createStub(EntityRepository::class);
        $repoComptes->method('findBy')->willReturn($comptes);
        $repoAbonnements = $this->createStub(EntityRepository::class);
        $repoAbonnements->method('findBy')->willReturnCallback(static fn (array $critere): array => array_values(array_filter(
            $abonnements,
            static fn (AbonnementPushClient $a): bool => \in_array($a->getCompte(), $critere['compte'], true),
        )));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [NotificationClientEnvoyee::class, $journal],
            [CompteClient::class, $repoComptes],
            [AbonnementPushClient::class, $repoAbonnements],
        ]);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof NotificationClientEnvoyee) {
                $this->journal[] = $o;
            }
        });

        $http = new MockHttpClient(function (string $methode, string $url, array $options): MockResponse {
            if (str_contains($url, '/oauth/')) {
                return new MockResponse((string) json_encode(['access_token' => 'jeton', 'expires_in' => 3600]));
            }
            if (str_contains($url, 'fcm.googleapis.com')) {
                $this->push[] = (string) ($options['body'] ?? '');

                return new MockResponse('', ['http_code' => 201]);
            }
            $corps = json_decode((string) ($options['body'] ?? '{}'), true);
            $this->sms[] = (string) ($corps['outboundSMSMessageRequest']['outboundSMSTextMessage']['message'] ?? '');

            return new MockResponse('{}', ['http_code' => 201]);
        });
        ['publique' => $publique, 'prive' => $privee] = WebPush::nouvellesCles();
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/espace/commande/abc');

        return new NotificationsClient(
            $em,
            static fn () => throw new \LogicException('fil fourni par le test'),
            new WebPush($http, $publique, $privee, 'mailto:atelier@exemple.ml'),
            new OrangeSmsService($http, new ArrayAdapter(), new NullLogger(), 'id', 'secret', 'tel:+22300000000', 'SuccesImprim'),
            $this->createStub(MailerInterface::class),
            $urls,
            new NullLogger(),
            true,
            'null://null',
            '',
        );
    }
}
