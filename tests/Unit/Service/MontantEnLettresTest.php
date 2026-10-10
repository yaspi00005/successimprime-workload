<?php

namespace App\Tests\Unit\Service;

use App\Service\MontantEnLettres;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MontantEnLettresTest extends TestCase
{
    /**
     * @return iterable<array{int, string}>
     */
    public static function montants(): iterable
    {
        yield [0, 'zéro'];
        yield [1, 'un'];
        yield [17, 'dix-sept'];
        yield [21, 'vingt et un'];
        yield [71, 'soixante et onze'];
        yield [77, 'soixante-dix-sept'];
        yield [80, 'quatre-vingts'];
        yield [81, 'quatre-vingt-un'];
        yield [91, 'quatre-vingt-onze'];
        yield [99, 'quatre-vingt-dix-neuf'];
        yield [100, 'cent'];
        yield [200, 'deux cents'];
        yield [201, 'deux cent un'];
        yield [1000, 'mille'];
        yield [1080, 'mille quatre-vingts'];
        yield [80_000, 'quatre-vingt mille'];
        yield [200_000, 'deux cent mille'];
        yield [233_000, 'deux cent trente-trois mille'];
        yield [1_000_000, 'un million'];
        yield [200_000_000, 'deux cents millions'];
        yield [2_500_000, 'deux millions cinq cent mille'];
        yield [1_000_000_000, 'un milliard'];
    }

    #[DataProvider('montants')]
    public function testEnLettres(int $montant, string $attendu): void
    {
        self::assertSame($attendu, MontantEnLettres::enLettres($montant));
    }

    public function testPhraseFacture(): void
    {
        self::assertSame(
            'Arrêtée la présente facture à la somme de deux cent trente-trois mille (233 000) francs CFA TTC.',
            MontantEnLettres::phraseFacture(233_000)
        );
        self::assertStringEndsWith('francs CFA.', MontantEnLettres::phraseFacture(5_000, false));
    }
}
