<?php

namespace App\Tests\Unit\Security;

use App\Security\CodeDeConnexion;
use App\Service\OrangeSmsService;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Codes de connexion pour les tests : SMS jamais configuré (aucun appel
 * réseau), e-mails gardés en mémoire, limites de rate_limiter.yaml.
 */
final class FabriqueCodes
{
    /** @var list<Email> */
    public array $emails = [];

    public bool $emailEnPanne = false;

    public function codes(string $environnement = 'dev', bool $emailRegle = true): CodeDeConnexion
    {
        $sms = new OrangeSmsService(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), '', '', '', '');
        $boite = $this;
        $mailer = new class($boite) implements TransportInterface {
            public function __construct(private readonly FabriqueCodes $boite)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                if ($this->boite->emailEnPanne) {
                    throw new TransportException('Connexion au serveur d\'envoi refusée.');
                }
                \assert($message instanceof Email);
                $this->boite->emails[] = $message;

                return null;
            }

            public function __toString(): string
            {
                return 'boite://essai';
            }
        };
        $stockage = new InMemoryStorage();

        return new CodeDeConnexion(
            'secret-de-test',
            $environnement,
            $sms,
            $mailer,
            $emailRegle ? 'smtp://boite.test:465' : 'null://null',
            $emailRegle ? 'contact@succesimprim.test' : '',
            new RateLimiterFactory(['id' => 'destinataire', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '15 minutes'], $stockage),
            new RateLimiterFactory(['id' => 'ip', 'policy' => 'sliding_window', 'limit' => 10, 'interval' => '1 hour'], $stockage),
            new NullLogger(),
        );
    }
}
