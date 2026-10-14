<?php

namespace App\Service\Conception\Generateur;

use App\Service\Conception\PromptConception;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Images par l'API OpenAI (ou une API compatible) : POST
 * {adresse}/images/generations, image rendue en base64 dans la réponse.
 * Le modèle se choisit par IA_IMAGES_MODELE (gpt-image-1 par défaut ;
 * dall-e-3 et dall-e-2 acceptés).
 *
 * La clé n'est jamais écrite dans les journaux ni dans les messages.
 */
final class GenerateurOpenAi implements GenerateurImages
{
    public const MODELE_DEFAUT = 'gpt-image-1';

    /** Une image prend souvent 20 à 60 secondes. */
    public const DELAI_SECONDES = 120;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $cle,
        private readonly string $modele = self::MODELE_DEFAUT,
        private readonly string $adresse = 'https://api.openai.com/v1',
    ) {
    }

    public function nom(): string
    {
        return 'openai';
    }

    public function modele(): string
    {
        return '' !== trim($this->modele) ? trim($this->modele) : self::MODELE_DEFAUT;
    }

    public function estDisponible(): bool
    {
        return '' !== trim($this->cle) && str_starts_with($this->adresse, 'https://');
    }

    /**
     * Corps de la requête selon le modèle : taille par orientation,
     * réponse en base64 (jamais une adresse à aller chercher).
     *
     * @return array<string, mixed>
     */
    public function requete(string $prompt, string $orientation): array
    {
        $modele = $this->modele();
        if (str_starts_with($modele, 'dall-e-2')) {
            return ['model' => $modele, 'prompt' => mb_substr($prompt, 0, 1000), 'n' => 1, 'size' => '1024x1024', 'response_format' => 'b64_json'];
        }
        if (str_starts_with($modele, 'dall-e-3')) {
            $taille = [PromptConception::PORTRAIT => '1024x1792', PromptConception::PAYSAGE => '1792x1024'][$orientation] ?? '1024x1024';

            return ['model' => $modele, 'prompt' => $prompt, 'n' => 1, 'size' => $taille, 'response_format' => 'b64_json'];
        }
        $taille = [PromptConception::PORTRAIT => '1024x1536', PromptConception::PAYSAGE => '1536x1024'][$orientation] ?? '1024x1024';

        return ['model' => $modele, 'prompt' => $prompt, 'n' => 1, 'size' => $taille];
    }

    public function generer(string $prompt, string $orientation, array $textes = []): ImageGeneree
    {
        if (!$this->estDisponible()) {
            throw new \RuntimeException('Clé du générateur d\'images absente.');
        }
        try {
            $reponse = $this->http->request('POST', rtrim($this->adresse, '/').'/images/generations', [
                'headers' => ['Authorization' => 'Bearer '.$this->cle, 'Accept' => 'application/json'],
                'json' => $this->requete($prompt, $orientation),
                'timeout' => self::DELAI_SECONDES,
                'max_duration' => self::DELAI_SECONDES + 30,
            ]);
            $statut = $reponse->getStatusCode();
            $donnees = $reponse->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Générateur injoignable : '.mb_substr($e->getMessage(), 0, 150), 0, $e);
        }
        if ($statut >= 300) {
            $message = \is_array($donnees['error'] ?? null) ? (string) ($donnees['error']['message'] ?? '') : '';
            throw new \RuntimeException(\sprintf('Générateur : erreur %d %s', $statut, mb_substr(str_replace($this->cle, '***', $message), 0, 150)));
        }
        $base64 = $donnees['data'][0]['b64_json'] ?? null;
        if (!\is_string($base64) || '' === $base64) {
            throw new \RuntimeException('Générateur : réponse sans image.');
        }
        $contenu = base64_decode($base64, true);
        if (false === $contenu) {
            throw new \RuntimeException('Générateur : image illisible.');
        }

        return ImageGeneree::depuis($contenu);
    }
}
