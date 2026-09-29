<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi de SMS via l'API Orange SMS (utilisée pour les campagnes de
 * communication et les rappels de paiement). Remplace l'ancienne
 * classe App\Controller\config\Configsms : les identifiants viennent
 * désormais de .env.local (jamais du code source), et le jeton
 * OAuth n'est plus codé en dur -- il est demandé automatiquement à
 * Orange puis mis en cache jusqu'à son expiration.
 */
final class OrangeSmsService
{
    private const BASE_URL = 'https://api.orange.com';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $senderAddress,
        private readonly string $senderName
    ) {
    }

    /**
     * true si les identifiants Orange ont été renseignés dans
     * .env.local. Permet d'afficher/désactiver l'envoi de SMS sans
     * provoquer une erreur d'API à chaque appel.
     */
    public function estConfigure(): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && $this->senderAddress !== '';
    }

    /**
     * @param string|null $senderName Nom d'expéditeur à utiliser pour
     *                                cet envoi (ex. choisi lors d'une
     *                                campagne). Laissé null ou vide :
     *                                le nom par défaut de .env.local
     *                                est utilisé.
     * @return array{succes: bool, erreur: ?string}
     */
    public function envoyerSms(string $telephone, string $message, ?string $senderName = null): array
    {
        if (!$this->estConfigure()) {
            return [
                'succes' => false,
                'erreur' => 'Le service SMS Orange n’est pas configuré (identifiants manquants).',
            ];
        }

        $destinataire = $this->normaliserTelephone($telephone);

        if ($destinataire === null) {
            return [
                'succes' => false,
                'erreur' => 'Numéro de téléphone invalide : "' . $telephone . '".',
            ];
        }

        try {
            $token = $this->obtenirToken();
        } catch (\Throwable $exception) {
            $this->logger->error('SMS Orange : impossible d’obtenir un jeton.', [
                'exception' => $exception->getMessage(),
            ]);

            return [
                'succes' => false,
                'erreur' => 'Impossible de s’authentifier auprès d’Orange : ' . $exception->getMessage(),
            ];
        }

        $corps = [
            'outboundSMSMessageRequest' => [
                'address' => $destinataire,
                'senderAddress' => $this->senderAddress,
                'outboundSMSTextMessage' => [
                    'message' => $message,
                ],
            ],
        ];

        $nomExpediteur = $senderName !== null && trim($senderName) !== ''
            ? trim($senderName)
            : $this->senderName;

        if ($nomExpediteur !== '') {
            $corps['outboundSMSMessageRequest']['senderName'] = $nomExpediteur;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                self::BASE_URL . '/smsmessaging/v1/outbound/'
                    . urlencode($this->senderAddress) . '/requests',
                [
                    'auth_bearer' => $token,
                    'json' => $corps,
                ]
            );

            $statut = $response->getStatusCode();

            if ($statut !== 201) {
                $contenu = $response->toArray(false);

                return [
                    'succes' => false,
                    'erreur' => $this->extraireMessageErreur($contenu) ?? ('Réponse Orange inattendue (HTTP ' . $statut . ').'),
                ];
            }

            return ['succes' => true, 'erreur' => null];
        } catch (\Throwable $exception) {
            $this->logger->error('SMS Orange : échec de l’envoi.', [
                'telephone' => $destinataire,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'succes' => false,
                'erreur' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Récupère le jeton OAuth en cache, ou en demande un nouveau à
     * Orange s'il est absent/expiré. La durée de vie réelle
     * (expires_in) est fournie par Orange à chaque demande ; on
     * retire une marge de sécurité de 60 secondes pour ne jamais
     * envoyer une requête avec un jeton tout juste expiré.
     */
    private function obtenirToken(): string
    {
        return $this->cache->get(
            'orange_sms_token',
            function (ItemInterface $item): string {
                $identifiants = base64_encode($this->clientId . ':' . $this->clientSecret);

                $response = $this->httpClient->request(
                    'POST',
                    self::BASE_URL . '/oauth/v3/token',
                    [
                        'headers' => [
                            'Authorization' => 'Basic ' . $identifiants,
                            'Content-Type' => 'application/x-www-form-urlencoded',
                        ],
                        'body' => 'grant_type=client_credentials',
                    ]
                );

                $donnees = $response->toArray();

                $token = $donnees['access_token'] ?? null;

                if (!is_string($token) || $token === '') {
                    throw new \RuntimeException('Réponse Orange sans access_token.');
                }

                $dureeVie = (int) ($donnees['expires_in'] ?? 3600);

                $item->expiresAfter(max(60, $dureeVie - 60));

                return $token;
            }
        );
    }

    /**
     * Convertit un numéro local (ex. "78 47 87 42") au format
     * attendu par l'API Orange : "tel:+22378478742". Retourne null
     * si le numéro, une fois nettoyé, n'a pas un nombre de chiffres
     * plausible.
     */
    private function normaliserTelephone(string $telephone): ?string
    {
        $chiffres = preg_replace('/\D+/', '', $telephone) ?? '';

        if (strlen($chiffres) === 8) {
            $chiffres = '223' . $chiffres;
        }

        if (strlen($chiffres) < 10 || strlen($chiffres) > 15) {
            return null;
        }

        return 'tel:+' . $chiffres;
    }

    private function extraireMessageErreur(array $reponse): ?string
    {
        if (!empty($reponse['requestError']['serviceException'])) {
            $exception = $reponse['requestError']['serviceException'];

            return trim(($exception['text'] ?? '') . ' ' . ($exception['variables'] ?? ''));
        }

        if (!empty($reponse['requestError']['policyException'])) {
            $exception = $reponse['requestError']['policyException'];

            return trim(($exception['text'] ?? '') . ' ' . ($exception['variables'] ?? ''));
        }

        return $reponse['error_description'] ?? $reponse['message'] ?? null;
    }
}
