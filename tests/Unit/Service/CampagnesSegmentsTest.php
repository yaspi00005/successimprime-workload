<?php

namespace App\Tests\Unit\Service;

use App\Entity\Campagne;
use App\Entity\Clients;
use App\Service\ResultatsCampagnes;
use App\Service\SegmentsClients;
use PHPUnit\Framework\TestCase;

final class CampagnesSegmentsTest extends TestCase
{
    public function testPersonnaliser(): void
    {
        self::assertSame('Bonjour Awa, Keita / Awa Keita', Campagne::personnaliser('Bonjour {prénom}, {nom} / {entreprise}', 'Awa', 'Keita', null));
        self::assertSame('Bonjour cher client (Hôtel)', Campagne::personnaliser('Bonjour {prenom} ({entreprise})', null, null, 'Hôtel'));
    }

    public function testSegments(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-11');
        $client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);

        self::assertSame(['tous', 'entreprises', 'nouveaux'], SegmentsClients::segmentsDe($client, null, $maintenant));
        self::assertSame(['tous', 'entreprises', 'actifs', 'inactifs', 'gros'], SegmentsClients::segmentsDe($client, ['derniere' => new \DateTimeImmutable('2026-05-01'), 'ca' => 600000], $maintenant));
        self::assertSame(['tous', 'particuliers', 'actifs'], SegmentsClients::segmentsDe(new Clients(), ['derniere' => new \DateTimeImmutable('2026-10-01'), 'ca' => 1000], $maintenant));
        self::assertSame(['tous', 'entreprises', 'nouveaux'], SegmentsClients::segmentsDe((new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ONG), null, $maintenant));
        // Un revendeur au prix B2B reste un particulier s'il n'a pas de compte entreprise.
        self::assertSame(['tous', 'particuliers', 'nouveaux'], SegmentsClients::segmentsDe((new Clients())->setTypeClient('B2B'), null, $maintenant));
    }

    public function testJoignables(): void
    {
        $accord = (new Clients())->setTelephone('76000001')->setAccepteMarketing(true);
        $sansAccord = (new Clients())->setTelephone('76000002');
        $refusSms = (new Clients())->setTelephone('76000003')->setRecevoirSms(false);
        $sansTelephone = (new Clients())->setAccepteMarketing(true);
        $tous = [$accord, $sansAccord, $refusSms, $sansTelephone];

        self::assertSame([$accord], SegmentsClients::joignables($tous, 'whatsapp', 'accepte'));
        self::assertSame([$accord, $sansAccord], SegmentsClients::joignables($tous, 'whatsapp', 'sauf_refus'));
        self::assertSame([$accord, $sansAccord], SegmentsClients::joignables($tous, 'sms', 'sauf_refus'));
        self::assertSame([], SegmentsClients::joignables($tous, 'email', 'accepte'));
    }

    public function testAccordMarketingGardeSaDate(): void
    {
        $client = (new Clients())->setAccepteMarketing(true);
        $date = $client->getConsentementMarketingLe();
        self::assertNotNull($date);
        self::assertSame($date, $client->setAccepteMarketing(true)->getConsentementMarketingLe());
        self::assertNull($client->setAccepteMarketing(false)->getConsentementMarketingLe());
    }

    public function testMeilleureCampagne(): void
    {
        self::assertNull(ResultatsCampagnes::meilleure([1 => ['devis' => 0, 'commandes' => 0]]));
        self::assertSame(3, ResultatsCampagnes::meilleure([
            1 => ['devis' => 23, 'commandes' => 3],
            2 => ['devis' => 4, 'commandes' => 11],
            3 => ['devis' => 9, 'commandes' => 11],
        ]));
    }
}
