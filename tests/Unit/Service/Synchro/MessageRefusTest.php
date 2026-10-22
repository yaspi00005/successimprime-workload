<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\Signature;
use PHPUnit\Framework\TestCase;

final class MessageRefusTest extends TestCase
{
    private const MAINTENANT = 1_760_000_000;

    public function testLeMotifDuSiteDitQuoiRegler(): void
    {
        self::assertStringContainsString('12 min', AgentSynchro::messageRefus(json_encode(['refus' => Signature::REFUS_HEURE, 'heure_site' => self::MAINTENANT + 720]), null, self::MAINTENANT));
        self::assertStringContainsString('en-têtes', AgentSynchro::messageRefus(json_encode(['refus' => Signature::REFUS_ENTETES, 'heure_site' => self::MAINTENANT]), null, self::MAINTENANT));
        $cle = AgentSynchro::messageRefus(json_encode(['refus' => Signature::REFUS_SIGNATURE, 'heure_site' => self::MAINTENANT]), null, self::MAINTENANT);
        self::assertStringContainsString('pas la même', $cle);
        self::assertStringNotContainsString('heure', $cle);
    }

    public function testUnSitePasEncoreAJourSeJugeParSonHeure(): void
    {
        // Réponse vide : seul l'en-tête Date du serveur web renseigne.
        $date = gmdate('D, d M Y H:i:s', self::MAINTENANT - 3600).' GMT';
        self::assertStringContainsString('60 min', AgentSynchro::messageRefus('', $date, self::MAINTENANT));

        $alHeure = gmdate('D, d M Y H:i:s', self::MAINTENANT).' GMT';
        self::assertStringContainsString('ou l\'heure', AgentSynchro::messageRefus('', $alHeure, self::MAINTENANT));
        self::assertStringContainsString('ou l\'heure', AgentSynchro::messageRefus('', null, self::MAINTENANT));
    }
}
