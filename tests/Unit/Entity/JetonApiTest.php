<?php

namespace App\Tests\Unit\Entity;

use App\Entity\JetonApi;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class JetonApiTest extends TestCase
{
    public function testLeJetonNestGardeQueSousFormeDEmpreinte(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-13 10:00');
        [$jeton, $texte] = JetonApi::creer($this->utilisateur(), '  Excel du gérant ', null, 90, $maintenant);

        self::assertTrue(JetonApi::formeValide($texte));
        self::assertSame(hash('sha256', $texte), $jeton->getEmpreinte());
        self::assertStringNotContainsString(substr($texte, 9), $jeton->getEmpreinte().$jeton->getDebut());
        self::assertSame(substr($texte, 0, 9), $jeton->getDebut());
        self::assertSame('Excel du gérant', $jeton->getNom());
        self::assertEquals(new \DateTimeImmutable('2027-01-11 10:00'), $jeton->getExpireLe());
    }

    public function testFormeValide(): void
    {
        self::assertFalse(JetonApi::formeValide('si_123'));
        self::assertFalse(JetonApi::formeValide('xx_'.str_repeat('a', 40)));
        self::assertFalse(JetonApi::formeValide('si_'.str_repeat('A', 40)));
        self::assertTrue(JetonApi::formeValide('si_'.str_repeat('0f', 20)));
    }

    public function testExpireRevoqueOuPersonneDesactivee(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-13 10:00');
        $utilisateur = $this->utilisateur();
        [$jeton] = JetonApi::creer($utilisateur, 'Rapports', null, 30, $maintenant);
        self::assertTrue($jeton->estValide($maintenant));
        self::assertSame('actif', $jeton->getEtat($maintenant));

        $plusTard = $maintenant->modify('+31 days');
        self::assertFalse($jeton->estValide($plusTard));
        self::assertSame('expire', $jeton->getEtat($plusTard));

        [$sansFin] = JetonApi::creer($utilisateur, 'Application', null, 0, $maintenant);
        self::assertNull($sansFin->getExpireLe());
        self::assertTrue($sansFin->estValide($plusTard));

        $utilisateur->setActif(false);
        self::assertFalse($sansFin->estValide($maintenant));
        $utilisateur->setActif(true);

        $sansFin->revoquer($maintenant);
        self::assertFalse($sansFin->estValide($maintenant));
        self::assertSame('revoque', $sansFin->getEtat($maintenant));
    }

    public function testUsageNoteAuPlusUneFoisParMinute(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-13 10:00:00');
        [$jeton] = JetonApi::creer($this->utilisateur(), 'Rapports', null, 30, $maintenant);

        self::assertTrue($jeton->noterUtilisation($maintenant, '192.168.1.20'));
        self::assertFalse($jeton->noterUtilisation($maintenant->modify('+30 seconds'), '192.168.1.20'));
        self::assertTrue($jeton->noterUtilisation($maintenant->modify('+30 seconds'), '192.168.1.21'));
        self::assertTrue($jeton->noterUtilisation($maintenant->modify('+2 minutes'), '192.168.1.21'));
        self::assertSame('192.168.1.21', $jeton->getDerniereIp());
    }

    private function utilisateur(): User
    {
        return (new User())->setUsername('demo.fanta')->setActif(true);
    }
}
