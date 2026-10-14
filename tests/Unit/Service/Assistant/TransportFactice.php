<?php

namespace App\Tests\Unit\Service\Assistant;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Transport HTTP des tests de l'assistant : rend les réponses préparées
 * dans l'ordre et garde chaque requête envoyée (corps et en-têtes).
 */
final class TransportFactice implements ClientInterface
{
    /** @var list<array{statut: int, corps: array<string, mixed>}> */
    private array $file = [];

    /** @var list<array{corps: array<string, mixed>, brut: string, entetes: array<string, string>}> */
    public array $requetes = [];

    /**
     * @param list<array<string, mixed>> $contenu blocs de contenu, au format de l'API
     * @param array<string, mixed>       $usage
     */
    public function repondre(array $contenu, string $arret = 'end_turn', array $usage = [], string $modele = 'claude-opus-5-5'): self
    {
        $this->file[] = ['statut' => 200, 'corps' => [
            'id' => 'msg_'.(\count($this->file) + 1),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $modele,
            'content' => $contenu,
            'stop_reason' => $arret,
            'stop_sequence' => null,
            'usage' => $usage + ['input_tokens' => 1000, 'output_tokens' => 100, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
        ]];

        return $this;
    }

    public function echouer(int $statut): self
    {
        $this->file[] = ['statut' => $statut, 'corps' => ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'refusé']]];

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $brut = (string) $request->getBody();
        $entetes = [];
        foreach ($request->getHeaders() as $nom => $valeurs) {
            $entetes[strtolower($nom)] = implode(', ', $valeurs);
        }
        $this->requetes[] = ['corps' => json_decode($brut, true) ?? [], 'brut' => $brut, 'entetes' => $entetes];

        $reponse = array_shift($this->file) ?? throw new \LogicException('Aucune réponse préparée pour cet appel.');
        $f = new Psr17Factory();

        return $f->createResponse($reponse['statut'])
            ->withHeader('Content-Type', 'application/json')
            ->withBody($f->createStream((string) json_encode($reponse['corps'])));
    }
}
