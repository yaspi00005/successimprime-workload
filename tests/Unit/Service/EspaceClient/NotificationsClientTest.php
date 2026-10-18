<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\AbonnementPushClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\NotificationClientDifferee;
use App\Entity\NotificationClientEnvoyee;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\EspaceClient\PreferencesNotifications;
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
    /** @var list<array{numero: string, texte: string}> SMS partis */
    private array $sms = [];

    /** @var list<string> corps des notifications push parties (chiffrés) */
    private array $push = [];

    /** @var list<NotificationClientEnvoyee> */
    private array $journal = [];

    /** @var list<NotificationClientDifferee> */
    private array $differees = [];

    public function testSmsCocheUneSeuleFoisSurLeNumeroDeLaPersonneEtSansMontant(): void
    {
        $client = $this->client();
        $compte = $this->compte($client, '76000002')->setPreferencesNotifications(['factures' => [PreferencesNotifications::SMS]]);
        $service = $this->service([$compte]);
        $fil = [self::evenement('paiement', 'Paiement reçu', '25 000 F · Orange Money · commande CMD-D00766', '2026-10-13 10:00', 'CMD-D00766')];

        $envoyees = $service->envoyerFil($client, $fil, new \DateTimeImmutable('2026-10-13 10:05'));

        self::assertSame([['type' => 'paiement', 'canal' => NotificationClientEnvoyee::SMS]], $envoyees);
        self::assertSame('tel:+22376000002', $this->sms[0]['numero']);
        self::assertSame('Succès Imprim : Paiement reçu pour la commande CMD-D00766. Merci ! Détails dans votre espace client.', $this->sms[0]['texte']);
        self::assertStringNotContainsString('25 000', $this->sms[0]['texte']);

        // Le même événement ne repart pas.
        self::assertSame([], $service->envoyerFil($client, $fil, new \DateTimeImmutable('2026-10-13 10:10')));
        self::assertCount(1, $this->sms);
    }

    public function testParDefautLesFacturesNePartentPasParSms(): void
    {
        $client = $this->client();
        $service = $this->service([$this->compte($client)]);

        $envoyees = $service->envoyerFil($client, [self::evenement('facture', 'Nouvelle facture', 'F-0001', '2026-10-13 10:00', 'F-0001')], new \DateTimeImmutable('2026-10-13 10:01'));

        self::assertSame([['type' => 'facture', 'canal' => NotificationClientEnvoyee::AUCUN]], $envoyees);
        self::assertSame([], $this->sms);
    }

    public function testEvenementAncienNePartPasEtLaProductionResteDansLApplication(): void
    {
        $client = $this->client();
        $service = $this->service([$this->compte($client)]);
        $fil = [
            self::evenement('impression', 'Impression lancée', 'Commande CMD-1', '2026-10-13 09:00', 'CMD-1'),
            self::evenement('prete', 'Commande prête', 'CMD-2 · à retirer à l\'imprimerie', '2026-10-12 08:00', 'CMD-2'),
        ];

        // L'événement de la veille (plus de 12 h) est ignoré ; l'impression ne coûte pas de SMS par défaut.
        self::assertSame([['type' => 'impression', 'canal' => NotificationClientEnvoyee::AUCUN]], $service->envoyerFil($client, $fil, new \DateTimeImmutable('2026-10-13 10:00')));
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

    public function testPaiementJamaisAUnDemandeurMemeCoche(): void
    {
        $client = $this->client()->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $demandeur = $this->compte($client, '76000003')->setDroits([])->setPreferencesNotifications(['factures' => [PreferencesNotifications::APP, PreferencesNotifications::SMS]]);
        $abonnement = new AbonnementPushClient($demandeur, 'https://fcm.googleapis.com/fcm/send/xyz', 'cle', 'secret', null, new \DateTimeImmutable());
        $service = $this->service([$demandeur], [$abonnement]);

        $envoyees = $service->envoyerFil($client, [self::evenement('paiement', 'Paiement reçu', '10 000 F', '2026-10-13 10:00', 'CMD-9')], new \DateTimeImmutable('2026-10-13 10:01'));

        self::assertSame(NotificationClientEnvoyee::AUCUN, $envoyees[0]['canal']);
        self::assertSame([], $this->push);
        self::assertSame([], $this->sms);
    }

    public function testChacunSelonSesReglages(): void
    {
        $client = $this->client()->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $titulaire = $this->compte($client, '76000004');
        $valideur = $this->compte($client, '76000005')->setDroits([CompteClient::DROIT_VALIDEUR])->setAjoutePar($titulaire)
            ->setPreferencesNotifications(['bat' => [PreferencesNotifications::APP]]);
        $lecture = $this->compte($client, '76000006')->setDroits([CompteClient::DROIT_LECTURE])->setAjoutePar($titulaire)
            ->setPreferencesNotifications(['bat' => [PreferencesNotifications::SMS], 'livraison' => [PreferencesNotifications::SMS]]);
        $service = $this->service([$titulaire, $valideur, $lecture]);

        // Le fil arrive le plus récent d'abord.
        $service->envoyerFil($client, [
            self::evenement('route', 'Livreur en route', 'CMD-2', '2026-10-13 10:30', 'CMD-2'),
            self::evenement('bat', 'BAT prêt à valider', 'Vitrine', '2026-10-13 10:00', 'CMD-1'),
        ], new \DateTimeImmutable('2026-10-13 10:31'));

        // BAT : le titulaire (SMS par défaut) ; pas le valideur (application seulement), pas la lecture (catégorie interdite).
        // Livraison : le titulaire et la lecture.
        self::assertSame([
            ['tel:+22376000004', 'BAT'],
            ['tel:+22376000004', 'livreur'],
            ['tel:+22376000006', 'livreur'],
        ], array_map(static fn (array $s): array => [$s['numero'], str_contains($s['texte'], 'BAT') ? 'BAT' : 'livreur'], $this->sms));
    }

    public function testRienPourUnePersonneSuspendue(): void
    {
        $client = $this->client()->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $titulaire = $this->compte($client, '76000004');
        $suspendue = $this->compte($client, '76000006')->setDroits([CompteClient::DROIT_LECTURE])->setAjoutePar($titulaire)
            ->setPreferencesNotifications(['livraison' => [PreferencesNotifications::SMS]]);
        $suspension = (new \App\Entity\SuspensionEspace())->setClient($client)->setTelephone('76000006')->setMotif('Départ');
        $repository = $this->createStub(\App\Repository\SuspensionEspaceRepository::class);
        $repository->method('pour')->willReturnCallback(static fn (Clients $c, string $t): ?\App\Entity\SuspensionEspace => $suspension->vise($t) ? $suspension : null);
        $service = $this->service([$titulaire, $suspendue], [], new \App\Service\EspaceClient\SuspensionsEspace($this->createStub(EntityManagerInterface::class), $repository, true));

        $service->envoyerFil($client, [self::evenement('route', 'Livreur en route', 'CMD-2', '2026-10-13 10:30', 'CMD-2')], new \DateTimeImmutable('2026-10-13 10:31'));

        // Le titulaire est prévenu ; la personne suspendue, non.
        self::assertSame(['tel:+22376000004'], array_column($this->sms, 'numero'));

        // Tout le compte suspendu : plus personne.
        $this->sms = [];
        $this->journal = [];
        $suspension->setTelephone(null);
        $service = $this->service([$titulaire, $suspendue], [], new \App\Service\EspaceClient\SuspensionsEspace($this->createStub(EntityManagerInterface::class), $repository, true));
        $service->envoyerFil($client, [self::evenement('route', 'Livreur en route', 'CMD-3', '2026-10-13 11:30', 'CMD-3')], new \DateTimeImmutable('2026-10-13 11:31'));
        self::assertSame([], $this->sms);
    }

    public function testHeuresCalmesLeSmsAttendSeptHeures(): void
    {
        $client = $this->client();
        $compte = $this->compte($client, '76000007');
        $service = $this->service([$compte]);

        $envoyees = $service->envoyerFil($client, [self::evenement('bat', 'BAT prêt à valider', 'Vitrine', '2026-10-13 22:10', 'CMD-1')], new \DateTimeImmutable('2026-10-13 22:15'));

        self::assertSame([['type' => 'bat', 'canal' => NotificationClientEnvoyee::DIFFERE]], $envoyees);
        self::assertSame([], $this->sms);
        self::assertCount(1, $this->differees);
        self::assertEquals(new \DateTimeImmutable('2026-10-14 07:00'), $this->differees[0]->getPrevuLe());

        // Pendant la nuit, rien ne part ; à 7 h, le SMS part une fois.
        self::assertSame(['envoyes' => 0, 'perimes' => 0, 'echecs' => 0], $service->envoyerDifferees(new \DateTimeImmutable('2026-10-14 05:00')));
        self::assertSame(['envoyes' => 1, 'perimes' => 0, 'echecs' => 0], $service->envoyerDifferees(new \DateTimeImmutable('2026-10-14 07:00')));
        self::assertCount(1, $this->sms);
        self::assertSame(NotificationClientDifferee::ENVOYEE, $this->differees[0]->getResultat());
        self::assertSame(['envoyes' => 0, 'perimes' => 0, 'echecs' => 0], $service->envoyerDifferees(new \DateTimeImmutable('2026-10-14 07:15')));
    }

    public function testSmsDiffereNePartPlusSiLaPersonneAChangeDAvisOuSiTropVieux(): void
    {
        $client = $this->client();
        $compte = $this->compte($client, '76000008');
        $service = $this->service([$compte]);
        $service->envoyerFil($client, [self::evenement('bat', 'BAT prêt à valider', 'Vitrine', '2026-10-13 23:30', 'CMD-1')], new \DateTimeImmutable('2026-10-13 23:31'));
        $service->envoyerFil($client, [self::evenement('message', 'Réponse de l\'atelier', '…', '2026-10-13 06:00', 'M')], new \DateTimeImmutable('2026-10-13 06:01'));
        self::assertCount(2, $this->differees);

        // Elle a décoché le SMS des BAT pendant la nuit ; le message de 6 h est daté de plus de 12 h le soir.
        $compte->setPreferencesNotifications(['bat' => [PreferencesNotifications::APP]]);
        $bilan = $service->envoyerDifferees(new \DateTimeImmutable('2026-10-14 07:00'));

        self::assertSame(['envoyes' => 0, 'perimes' => 2, 'echecs' => 0], $bilan);
        self::assertSame([], $this->sms);
    }

    public function testLivraisonDuJourPartMemeLaNuit(): void
    {
        $client = $this->client();
        $service = $this->service([$this->compte($client, '76000009')]);

        $envoyees = $service->envoyerFil($client, [self::evenement('route', 'Livreur en route', 'CMD-3', '2026-10-13 21:20', 'CMD-3')], new \DateTimeImmutable('2026-10-13 21:21'));

        self::assertSame([['type' => 'route', 'canal' => NotificationClientEnvoyee::SMS]], $envoyees);
        self::assertSame([], $this->differees);
        self::assertSame('Succès Imprim : Le livreur est en route avec votre commande CMD-3. Détails dans votre espace client.', $this->sms[0]['texte']);
    }

    public function testMessages(): void
    {
        self::assertSame('Votre commande CMD-1 est prête à retirer à l\'imprimerie.', NotificationsClient::message(['type' => 'prete', 'texte' => 'CMD-1 · à retirer à l\'imprimerie', 'numero' => 'CMD-1']));
        self::assertSame('Votre commande CMD-1 est prête : la livraison arrive bientôt.', NotificationsClient::message(['type' => 'prete', 'texte' => 'CMD-1 · livraison à venir', 'numero' => 'CMD-1']));
        self::assertSame('Nous avons répondu à votre réclamation R-0002.', NotificationsClient::message(['type' => 'reclamation', 'texte' => 'R-0002 · Couleur', 'numero' => 'R-0002']));
        self::assertSame('L\'atelier a répondu à votre message.', NotificationsClient::message(['type' => 'message', 'texte' => 'Livraison jeudi, 120 000 F', 'numero' => null]));
        self::assertSame('Rappel : la facture F-0009 est à régler.', NotificationsClient::message(['type' => 'echeance', 'texte' => 'Facture F-0009 · 45 000 F', 'numero' => 'F-0009']));
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

    private function compte(Clients $client, string $telephone = '76000001'): CompteClient
    {
        return (new CompteClient())->setClient($client)->setTelephone($telephone)
            ->setDroits([CompteClient::DROIT_ADMINISTRATEUR, CompteClient::DROIT_PAYEUR, CompteClient::DROIT_VALIDEUR]);
    }

    /**
     * @param list<CompteClient>          $comptes
     * @param list<AbonnementPushClient> $abonnements
     */
    private function service(array $comptes, array $abonnements = [], ?\App\Service\EspaceClient\SuspensionsEspace $suspensions = null): NotificationsClient
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
        $repoDifferees = $this->createStub(EntityRepository::class);
        $repoDifferees->method('findBy')->willReturnCallback(fn (): array => array_values(array_filter($this->differees, static fn (NotificationClientDifferee $d): bool => null === $d->getEnvoyeLe())));
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [NotificationClientEnvoyee::class, $journal],
            [CompteClient::class, $repoComptes],
            [AbonnementPushClient::class, $repoAbonnements],
            [NotificationClientDifferee::class, $repoDifferees],
        ]);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof NotificationClientEnvoyee) {
                $this->journal[] = $o;
            } elseif ($o instanceof NotificationClientDifferee) {
                $this->differees[] = $o;
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
            $requete = $corps['outboundSMSMessageRequest'] ?? [];
            $this->sms[] = ['numero' => (string) ($requete['address'] ?? ''), 'texte' => (string) ($requete['outboundSMSTextMessage']['message'] ?? '')];

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
            $suspensions,
        );
    }
}
