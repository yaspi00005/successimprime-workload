<?php

namespace App\Tests\Unit\Service\Assistant;

use App\Service\Assistant\CoutAssistant;
use PHPUnit\Framework\TestCase;

final class CoutAssistantTest extends TestCase
{
    private const TUILE = ['entree' => 4.0, 'sortie' => 20.0, 'cacheLecture' => 0.2, 'cacheEcriture' => 5.0];

    public function testDepenseDUneReponse(): void
    {
        $cout = CoutAssistant::calculer(['input_tokens' => 1000, 'output_tokens' => 500, 'cache_read_input_tokens' => 2000, 'cache_creation_input_tokens' => 1000], 'claude-opus-5-5', self::TUILE);

        self::assertSame(['entree' => 1000, 'sortie' => 500, 'cacheLecture' => 2000, 'cacheEcriture' => 1000], $cout['jetons']);
        // 1000 × 4 + 500 × 20 + 2000 × 0,2 + 1000 × 5 = 19 400 dollars par million.
        self::assertEqualsWithDelta(0.0194, $cout['usd'], 1e-12);
        self::assertSame(11.64, CoutAssistant::fcfa($cout['usd'], 600));
    }

    public function testLeTarifDeLaTuileSAppliqueAuModeleDeLaConversation(): void
    {
        $tuile = ['entree' => 8.0, 'sortie' => 0.0, 'cacheLecture' => 0.0, 'cacheEcriture' => 0.0];
        $cout = CoutAssistant::calculer(['input_tokens' => 1_000_000, 'output_tokens' => 0], 'claude-opus-5-5', $tuile);

        self::assertEqualsWithDelta(8.0, $cout['usd'], 1e-9);
    }

    public function testRepliDuServeurCompteChaqueTentativeASonTarif(): void
    {
        // Le premier modèle a refusé, le repli a répondu : l'usage du haut ne montre que la dernière tentative.
        $usage = [
            'input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0,
            'iterations' => [
                ['type' => 'message', 'model' => null, 'input_tokens' => 1000, 'output_tokens' => 10, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
                ['type' => 'fallback_message', 'model' => 'claude-opus-5', 'input_tokens' => 1000, 'output_tokens' => 200, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
            ],
        ];
        $cout = CoutAssistant::calculer($usage, 'claude-opus-5-5', self::TUILE);

        self::assertSame(['entree' => 2000, 'sortie' => 210, 'cacheLecture' => 0, 'cacheEcriture' => 0], $cout['jetons']);
        // Opus 5.5 : 1000 × 4 + 10 × 20 ; Opus 5 : 1000 × 5 + 200 × 25.
        self::assertEqualsWithDelta((4000 + 200 + 5000 + 5000) / 1_000_000, $cout['usd'], 1e-12);
    }

    public function testModeleInconnuAuPlusCher(): void
    {
        self::assertSame(['entree' => 5.0, 'sortie' => 25.0, 'cacheLecture' => 0.5, 'cacheEcriture' => 6.25], CoutAssistant::tarif('claude-futur-9'));
        self::assertSame(CoutAssistant::TARIFS['claude-haiku-5-5'], CoutAssistant::tarif('claude-haiku-5-5'));
    }

    public function testPlafondDuJour(): void
    {
        self::assertFalse(CoutAssistant::plafondAtteint(4999.99, 5000));
        self::assertTrue(CoutAssistant::plafondAtteint(5000.0, 5000));
        self::assertTrue(CoutAssistant::plafondAtteint(0.0, 0), '0 coupe l\'assistant');
    }
}
