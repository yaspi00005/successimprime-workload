<?php

namespace App\Tests\Unit\Service;

use App\Service\OrangeSmsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OrangeSmsServiceTest extends TestCase
{
    public function testAdressesAuFormatOrange(): void
    {
        $envois = [];
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$envois): MockResponse {
            if (str_ends_with($url, '/oauth/v3/token')) {
                return new MockResponse('{"access_token":"jeton","expires_in":3600}');
            }
            $envois[] = ['url' => $url, 'corps' => json_decode($options['body'], true)];

            return new MockResponse('{}', ['http_code' => 201]);
        });
        // Expéditeur donné sans « tel:+ », destinataire sans indicatif.
        $sms = new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), 'id', 'secret', '2230000', 'SUCCES');

        self::assertTrue($sms->envoyerSms('76 00 00 99', 'Bonjour')['succes']);
        self::assertTrue($sms->envoyerSms('00223 76 00 00 99', 'Bonjour')['succes']);

        foreach ($envois as $envoi) {
            self::assertStringContainsString('/outbound/tel%3A%2B2230000/requests', $envoi['url']);
            self::assertSame('tel:+2230000', $envoi['corps']['outboundSMSMessageRequest']['senderAddress']);
            self::assertSame('tel:+22376000099', $envoi['corps']['outboundSMSMessageRequest']['address']);
        }
        self::assertSame('tel:+2230000', OrangeSmsService::adresseExpediteur('tel:+2230000'));
        self::assertSame('tel:+2230000', OrangeSmsService::adresseExpediteur('+223 0000'));
    }

    public function testErreurOrangeLisible(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"access_token":"jeton","expires_in":3600}'),
            new MockResponse('{"requestError":{"serviceException":{"messageId":"SVC0004","text":"No valid addresses provided in message part %1","variables":["senderAddress"]}}}', ['http_code' => 400]),
        ]);
        $sms = new OrangeSmsService($client, new ArrayAdapter(), new NullLogger(), 'id', 'secret', 'tel:+2230000', '');

        $erreur = (string) $sms->envoyerSms('76000099', 'Bonjour')['erreur'];
        self::assertStringContainsString('message part senderAddress', $erreur);
        self::assertStringNotContainsString('Array', $erreur);
        self::assertStringContainsString('tel:+2230000', $erreur);
    }
}
