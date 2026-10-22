<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Service\Synchro\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const CLE = '0123456789abcdef0123456789abcdef';

    public function testAccepteUneRequeteSigneeEtRefuseLesAutres(): void
    {
        $heure = 1_760_000_000;
        $sig = Signature::calculer(self::CLE, 'POST', '/api/synchro/envoyer', $heure, '{"a":1}');

        self::assertTrue(Signature::verifier(self::CLE, 'POST', '/api/synchro/envoyer', (string) $heure, '{"a":1}', $sig, $heure + 60));
        self::assertFalse(Signature::verifier(self::CLE, 'POST', '/api/synchro/envoyer', (string) $heure, '{"a":2}', $sig, $heure), 'contenu modifié');
        self::assertFalse(Signature::verifier(self::CLE, 'POST', '/api/synchro/accuser', (string) $heure, '{"a":1}', $sig, $heure), 'autre adresse');
        self::assertFalse(Signature::verifier(self::CLE, 'REPONSE', '/api/synchro/envoyer', (string) $heure, '{"a":1}', $sig, $heure), 'une réponse ne vaut pas requête');
        self::assertFalse(Signature::verifier(strrev(self::CLE), 'POST', '/api/synchro/envoyer', (string) $heure, '{"a":1}', $sig, $heure), 'autre clé');
        self::assertFalse(Signature::verifier(self::CLE, 'POST', '/api/synchro/envoyer', (string) $heure, '{"a":1}', $sig, $heure + 301), 'rejouée trop tard');
        self::assertFalse(Signature::verifier(self::CLE, 'POST', '/api/synchro/envoyer', 'abc', '{"a":1}', $sig, $heure), 'heure illisible');
    }

    public function testMotifDuRefus(): void
    {
        $heure = 1_760_000_000;
        $sig = Signature::calculer(self::CLE, 'POST', '/api/synchro/ping', $heure, '');

        self::assertNull(Signature::motifRefus(self::CLE, 'POST', '/api/synchro/ping', (string) $heure, '', $sig, $heure));
        self::assertSame(Signature::REFUS_ENTETES, Signature::motifRefus(self::CLE, 'POST', '/api/synchro/ping', '', '', '', $heure), 'en-têtes retirés en route');
        self::assertSame(Signature::REFUS_HEURE, Signature::motifRefus(self::CLE, 'POST', '/api/synchro/ping', (string) $heure, '', $sig, $heure + 600));
        self::assertSame(Signature::REFUS_SIGNATURE, Signature::motifRefus(strrev(self::CLE), 'POST', '/api/synchro/ping', (string) $heure, '', $sig, $heure));
    }

    public function testEmpreinteCourteQuiNeDonnePasLaCle(): void
    {
        self::assertSame(8, \strlen(Signature::empreinte(self::CLE)));
        self::assertSame(Signature::empreinte(self::CLE), Signature::empreinte(self::CLE));
        self::assertNotSame(Signature::empreinte(self::CLE), Signature::empreinte(self::CLE.' '), 'un espace en trop se voit');
        self::assertStringNotContainsString(substr(self::CLE, 0, 8), Signature::empreinte(self::CLE));
    }

    public function testRefuseUneCleTropCourte(): void
    {
        $sig = Signature::calculer('court', 'POST', '/x', 1, '');
        self::assertFalse(Signature::verifier('court', 'POST', '/x', '1', '', $sig, 1));
        self::assertFalse(Signature::cleValable(''));
        self::assertTrue(Signature::cleValable(self::CLE));
    }
}
