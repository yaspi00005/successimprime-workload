<?php

namespace App\Service\Assistant;

use Anthropic\Client;
use Psr\Http\Client\ClientInterface;

/**
 * Client du SDK officiel de l'API Claude. La clé vient de .env.local (jamais
 * affichée) ; en démonstration de développement, les réponses viennent de
 * RepondeurDemo, sans appel ni clé.
 */
final class FabriqueClaude
{
    public function __construct(
        private readonly ReglagesAssistant $reglages,
        private readonly ClientInterface $transport,
    ) {
    }

    public function client(): Client
    {
        $demo = $this->reglages->demo();

        return new Client(
            apiKey: $demo ? 'demo' : $this->reglages->cle(),
            requestOptions: [
                'transporter' => $demo ? new RepondeurDemo() : $this->transport,
                'maxRetries' => 1,
                'timeout' => 60.0,
            ],
            openTelemetry: false,
        );
    }
}
