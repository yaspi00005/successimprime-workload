<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Service\DossiersClients\LienProtocole;
use PHPUnit\Framework\TestCase;

/** « Ouvrir avec Illustrator » : lien succes-fichier:// signé et limité au dossier des clients. */
final class LienProtocoleTest extends TestCase
{
    private const RACINE = '\\\\SERVEUR\\Clients';

    public function testLienSigneAccepte(): void
    {
        $p = new LienProtocole(LienProtocole::clePostes('secret'));
        $chemin = '\\\\SERVEUR\\Clients\\C-0087 Hôtel Niger\\01 Conceptions\\kakemono_v3.ai';
        $lien = $p->lien('ouvrir', $chemin, 1000);

        self::assertStringStartsWith('succes-fichier://ouvrir?chemin=', $lien);
        self::assertSame(['action' => 'ouvrir', 'chemin' => $chemin], $p->verifier($lien, self::RACINE, 1000));
    }

    public function testLienModifieOuPerimeOuMalSigneRefuse(): void
    {
        $p = new LienProtocole('cle');
        $chemin = '\\\\SERVEUR\\Clients\\A\\a.ai';
        $lien = $p->lien('afficher', $chemin, 1000);

        self::assertNull($p->verifier(str_replace('a.ai', 'b.ai', $lien), self::RACINE, 1000));
        self::assertNull($p->verifier(str_replace('afficher', 'ouvrir', $lien), self::RACINE, 1000));
        self::assertNull($p->verifier($lien, self::RACINE, 1000 + LienProtocole::DUREE + 1));
        self::assertNull((new LienProtocole('autre cle'))->verifier($lien, self::RACINE, 1000));
    }

    public function testCheminHorsRacineRefuse(): void
    {
        $p = new LienProtocole('cle');

        foreach (['C:\\Windows\\System32\\calc.exe', '\\\\SERVEUR\\Clients\\..\\Compta\\paie.xlsx', '\\\\SERVEUR\\ClientsBis\\a.ai', '\\\\SERVEUR\\Clients'] as $chemin) {
            self::assertNull($p->verifier($p->lien('ouvrir', $chemin, 1000), self::RACINE, 1000), $chemin);
        }
    }

    public function testSousRacine(): void
    {
        self::assertTrue(LienProtocole::sousRacine('\\\\serveur\\clients\\A\\b.ai', self::RACINE));
        self::assertTrue(LienProtocole::sousRacine('/srv/clients/A/./b.ai', '/srv/clients'));
        self::assertFalse(LienProtocole::sousRacine('/srv/clients/../etc/passwd', '/srv/clients'));
        self::assertFalse(LienProtocole::sousRacine('/srv/clients-bis/a', '/srv/clients'));
        self::assertNull(LienProtocole::normaliser('../a'));
    }

    public function testCheminVuDesPostes(): void
    {
        self::assertSame(
            '\\\\SERVEUR\\Clients\\C-0087 Hôtel Niger\\01 Conceptions\\a.ai',
            LienProtocole::cheminPoste('/srv/clients/C-0087 Hôtel Niger/01 Conceptions/a.ai', '/srv/clients', self::RACINE),
        );
        self::assertSame('D:\\Clients\\A\\a.ai', LienProtocole::cheminPoste('D:\\Clients\\A\\a.ai', 'D:\\Clients', ''));
        self::assertNull(LienProtocole::cheminPoste('/srv/autre/a.ai', '/srv/clients', self::RACINE));
    }

    public function testActionInconnueRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LienProtocole('cle'))->lien('supprimer', '\\\\SERVEUR\\Clients\\a', 1000);
    }
}
