<?php

namespace App\Tests\Unit\Service\Messagerie;

use App\Controller\CreditsMessagesController;
use App\Entity\EnvoiMessage;
use App\EventSubscriber\JournalEmailsSubscriber;
use App\Service\Messagerie\JournalEnvois;
use App\Service\OrangeSmsService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Chaque SMS et chaque e-mail tenté laisse une ligne dans l'historique
 * (sans son texte), qu'il parte, échoue ou ne parte pas faute de réglage.
 * Jamais d'envoi réel : client HTTP et transport simulés.
 */
final class HistoriqueDesEnvoisTest extends TestCase
{
    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $lignes;

    private JournalEnvois $journal;

    protected function setUp(): void
    {
        $this->lignes = $lignes = new \ArrayObject();
        $connexion = $this->createStub(Connection::class);
        $connexion->method('transactional')->willReturnCallback(static fn (\Closure $f): mixed => $f($connexion));
        $connexion->method('insert')->willReturnCallback(static function (string $table, array $ligne) use ($lignes): int {
            if ('envoi_message' === $table) {
                $lignes[] = $ligne;
            }

            return 1;
        });
        $this->journal = new JournalEnvois($connexion, new NullLogger(), false);
    }

    public function testSmsEnvoyeAvecSesUnitesEtSaSorte(): void
    {
        $client = new MockHttpClient(static fn (string $methode, string $url): MockResponse => str_ends_with($url, '/oauth/v3/token')
            ? new MockResponse('{"access_token":"jeton","expires_in":3600}')
            : new MockResponse('{}', ['http_code' => 201]));
        $sms = new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), 'id', 'secret', 'tel:+2230000', 'SUCCES', $this->journal);

        $sms->envoyerSms('76 00 00 99', str_repeat('a', 200), null, 'relance_retrait');

        self::assertCount(1, $this->lignes);
        self::assertSame('sms', $this->lignes[0]['canal']);
        self::assertSame('relance_retrait', $this->lignes[0]['type']);
        self::assertSame('envoye', $this->lignes[0]['statut']);
        self::assertSame(2, $this->lignes[0]['unites'], '200 caractères : deux SMS');
        self::assertSame('22376000099', $this->lignes[0]['destinataire']);
        self::assertNull($this->lignes[0]['sujet']);
    }

    public function testSmsSansClesNonEnvoyeEtEchecSansUnite(): void
    {
        $client = new MockHttpClient(static function (string $methode, string $url): MockResponse {
            if (str_ends_with($url, '/oauth/v3/token')) {
                return new MockResponse('{"access_token":"jeton","expires_in":3600}');
            }

            return new MockResponse('{"requestError":{"serviceException":{"messageId":"SVC0004","text":"No valid addresses provided in message part %1","variables":["address"]}}}', ['http_code' => 400]);
        });

        (new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), '', '', '', '', $this->journal))->envoyerSms('76000099', 'Votre code : 123456');
        (new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), 'id', 'secret', 'tel:+2230000', '', $this->journal))->envoyerSms('76000099', 'Bonjour', null, 'campagne');

        self::assertSame(['non_envoye', 'echec'], array_column($this->lignes->getArrayCopy(), 'statut'));
        self::assertSame(['autre', 'campagne'], array_column($this->lignes->getArrayCopy(), 'type'));
        self::assertSame([0, 0], array_column($this->lignes->getArrayCopy(), 'unites'));
        foreach ($this->lignes as $ligne) {
            self::assertStringNotContainsString('123456', json_encode($ligne, \JSON_UNESCAPED_UNICODE) ?: '', 'le texte (et le code) ne sont jamais gardés');
        }
    }

    public function testEmailSansMessagerieNonEnvoyeEtEnteteRetire(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new JournalEmailsSubscriber($this->journal));
        $transport = new NullTransport($dispatcher);

        $email = JournalEnvois::typer((new Email())->from('boutique@exemple.ml')->to('Awa@Exemple.ml', 'moussa@exemple.ml')->subject('Votre code de connexion')->text('Code : 123456'), 'code_connexion');
        $parti = $transport->send($email);

        self::assertInstanceOf(SentMessage::class, $parti);
        self::assertStringNotContainsString(JournalEnvois::ENTETE_TYPE, $parti->toString(), 'l\'en-tête interne ne part pas');
        self::assertCount(2, $this->lignes, 'une ligne par destinataire');
        self::assertSame(['awa@exemple.ml', 'moussa@exemple.ml'], array_column($this->lignes->getArrayCopy(), 'destinataire'));
        self::assertSame('non_envoye', $this->lignes[0]['statut']);
        self::assertSame('code_connexion', $this->lignes[0]['type']);
        self::assertSame('Votre code de connexion', $this->lignes[0]['sujet']);
        self::assertSame(0, $this->lignes[0]['unites']);
    }

    public function testEmailEnvoyeOuEchoue(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new JournalEmailsSubscriber($this->journal));
        $panne = false;
        $transport = new class($dispatcher, $panne) extends AbstractTransport {
            public function __construct(EventDispatcher $dispatcher, private bool &$panne)
            {
                parent::__construct($dispatcher);
            }

            protected function doSend(SentMessage $message): void
            {
                if ($this->panne) {
                    throw new TransportException('Boîte awa@exemple.ml pleine');
                }
            }

            public function __toString(): string
            {
                return 'smtp://exemple';
            }
        };

        $transport->send(JournalEnvois::typer((new Email())->from('boutique@exemple.ml')->to('awa@exemple.ml')->subject('Rappel : 25 000 F à régler')->text('…'), 'rappel_paiement'));
        $panne = true;
        try {
            $transport->send((new Email())->from('boutique@exemple.ml')->to('awa@exemple.ml')->subject('Sans sorte')->text('…'), new Envelope(new Address('boutique@exemple.ml'), [new Address('awa@exemple.ml')]));
            self::fail('l\'échec remonte à l\'appelant');
        } catch (TransportException) {
        }

        self::assertSame(['envoye', 'echec'], array_column($this->lignes->getArrayCopy(), 'statut'));
        self::assertSame([1, 0], array_column($this->lignes->getArrayCopy(), 'unites'));
        self::assertSame(['rappel_paiement', 'autre'], array_column($this->lignes->getArrayCopy(), 'type'));
        self::assertSame('Rappel : … F à régler', $this->lignes[0]['sujet'], 'jamais de montant dans l\'objet gardé');
        self::assertStringNotContainsString('awa@', (string) $this->lignes[1]['erreur']);
    }

    public function testExportSansFormuleDansLeTableur(): void
    {
        self::assertSame('00223 76 00 00 99', CreditsMessagesController::destinataireCsv('+223 76 00 00 99'));
        self::assertSame('00223 •• •• 00 99', CreditsMessagesController::destinataireCsv('+223 •• •• 00 99'));
        self::assertSame('a•••@gmail.com', CreditsMessagesController::destinataireCsv('a•••@gmail.com'));
        self::assertSame("'=SOMME(A1:A9)", CreditsMessagesController::cellule('=SOMME(A1:A9)'));
        self::assertSame("'-1 SMS", CreditsMessagesController::cellule('-1 SMS'));
        self::assertSame('Boîte pleine', CreditsMessagesController::cellule('Boîte pleine'));
        self::assertSame('', CreditsMessagesController::cellule(''));
    }

    public function testDestinataireMasqueOuLisible(): void
    {
        $sms = EnvoiMessage::nouveau(EnvoiMessage::SMS, 'campagne', '22376000099', EnvoiMessage::ENVOYE, null, 1, null, new \DateTimeImmutable());
        self::assertSame('+223 •• •• 00 99', $sms->getDestinataireMasque());
        self::assertSame('+223 76 00 00 99', $sms->getDestinataireLisible());

        $email = EnvoiMessage::nouveau(EnvoiMessage::EMAIL, 'inconnu', 'awa.traore@gmail.com', 'bizarre', null, -3, '  ', new \DateTimeImmutable());
        self::assertStringNotContainsString('awa.traore', $email->getDestinataireMasque());
        self::assertSame('awa.traore@gmail.com', $email->getDestinataireLisible());
        self::assertSame('autre', $email->getType(), 'sorte inconnue rangée dans « Autre »');
        self::assertSame(EnvoiMessage::ECHEC, $email->getStatut());
        self::assertSame(0, $email->getUnites());
        self::assertNull($email->getSujet());
    }
}
