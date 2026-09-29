<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WhatsAppService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly string $apiVersion
    ) {
    }

    /**
     * true si le compte WhatsApp Business (jeton + numero) a ete
     * renseigne dans .env.local. Permet d'afficher/desactiver le
     * bouton d'envoi sans provoquer une erreur d'API a chaque clic.
     */
    public function estConfigure(): bool
    {
        return $this->accessToken !== ''
            && $this->phoneNumberId !== '';
    }

    public function envoyerTemplate(
        string $telephone,
        string $template,
        string $langue = 'fr',
        array $parametres = []
    ): array {
        $components = [];

        if ($parametres !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->parametresTexte($parametres),
            ];
        }

        return $this->envoyer(
            $telephone,
            $template,
            $langue,
            $components
        );
    }

    /**
     * Envoie un modele avec un document en en-tete (facture/devis en
     * PDF), reference par une URL publique et non par un fichier
     * televerse : plus simple, pas d'appel supplementaire a l'API
     * media de Meta.
     */
    public function envoyerDocument(
        string $telephone,
        string $template,
        string $lienDocument,
        string $nomFichier,
        array $parametresTexte = [],
        string $langue = 'fr'
    ): array {
        $components = [
            [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'document',
                        'document' => [
                            'link' => $lienDocument,
                            'filename' => $nomFichier,
                        ],
                    ],
                ],
            ],
        ];

        if ($parametresTexte !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => $this->parametresTexte($parametresTexte),
            ];
        }

        return $this->envoyer(
            $telephone,
            $template,
            $langue,
            $components
        );
    }

    private function envoyer(
        string $telephone,
        string $template,
        string $langue,
        array $components
    ): array {
        $telephone = $this->normaliserTelephone(
            $telephone
        );

        $response = $this->httpClient->request(
            'POST',
            sprintf(
                'https://graph.facebook.com/%s/%s/messages',
                $this->apiVersion,
                $this->phoneNumberId
            ),
            [
                'headers' => [
                    'Authorization' =>
                        'Bearer ' . $this->accessToken,

                    'Content-Type' =>
                        'application/json',
                ],

                'json' => [
                    'messaging_product' =>
                        'whatsapp',

                    'recipient_type' =>
                        'individual',

                    'to' =>
                        $telephone,

                    'type' =>
                        'template',

                    'template' => [
                        'name' =>
                            $template,

                        'language' => [
                            'code' =>
                                $langue,
                        ],

                        'components' =>
                            $components,
                    ],
                ],
            ]
        );

        return $response->toArray(false);
    }

    private function parametresTexte(array $parametres): array
    {
        return array_map(
            static fn($parametre): array => [
                'type' => 'text',
                'text' => (string) $parametre,
            ],
            $parametres
        );
    }

    private function normaliserTelephone(
        string $telephone
    ): string {
        $telephone = preg_replace(
            '/\D+/',
            '',
            $telephone
        ) ?? '';

        /*
         * Mali :
         * 78478742
         * devient
         * 22378478742
         */
        if (
            strlen($telephone) === 8
        ) {
            $telephone =
                '223' . $telephone;
        }

        return $telephone;
    }
}
