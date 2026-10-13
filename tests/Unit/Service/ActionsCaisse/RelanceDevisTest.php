<?php

namespace App\Tests\Unit\Service\ActionsCaisse;

use App\Entity\ActionCaisse;
use App\Entity\Clients;
use App\Service\ActionsCaisse\ActionsCaisse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelanceDevisTest extends TestCase
{
    /**
     * @return iterable<string, array{int, ?string, ?string}>
     */
    public static function delaisProvider(): iterable
    {
        yield 'avant 48 h' => [47, null, null];
        yield 'première relance à 48 h' => [48, 'devis:12', null];
        yield 'toujours la première à J+6' => [6 * 24 + 23, 'devis:12', null];
        yield 'seconde à J+7, après la première' => [7 * 24, 'devis:12:2', 'devis:12'];
        yield 'seconde à J+20' => [20 * 24, 'devis:12:2', 'devis:12'];
    }

    #[DataProvider('delaisProvider')]
    public function testPaliersJ2EtJ7(int $heures, ?string $cause, ?string $apres): void
    {
        $relance = ActionsCaisse::relanceDevis(12, $heures, 48, 7);

        self::assertSame($cause, $relance['cause'] ?? null);
        self::assertSame($apres, $relance['apres'] ?? null);
    }

    public function testSansSecondeRelance(): void
    {
        self::assertSame('devis:12', ActionsCaisse::relanceDevis(12, 30 * 24, 48, 0)['cause']);
        // Une seconde relance réglée avant la première n'a pas de sens : seule la première part.
        self::assertSame('devis:12', ActionsCaisse::relanceDevis(12, 30 * 24, 200, 3)['cause']);
    }

    public function testLeMessageDeLaSecondeRelanceNeDonneAucunMontant(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-13 09:00');
        $action = (new ActionCaisse('devis', 'devis:12:2', 'Awa Mode', $maintenant, $maintenant))
            ->lier((new Clients())->setTelephone('76 00 00 01'), null, 'devis', 12)
            ->setDetail('Devis DV-0231 · '.ActionsCaisse::SECONDE_RELANCE.', sans réponse depuis 7 jours');

        $message = rawurldecode(explode('?text=', (string) ActionsCaisse::liens($action)['whatsapp'])[1]);

        self::assertStringContainsString('Notre devis dv-0231 est toujours valable', $message);
        self::assertDoesNotMatchRegularExpression('/\d+\s?F\b/', $message);
    }
}
