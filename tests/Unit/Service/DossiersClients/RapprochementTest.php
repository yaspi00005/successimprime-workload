<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Service\DossiersClients\Rapprochement;
use PHPUnit\Framework\TestCase;

/** Lier les dossiers existants (DS02) : code d'abord, puis ressemblance du nom. */
final class RapprochementTest extends TestCase
{
    public function testLeCodeDuClientDansLeNomDuDossierLie(): void
    {
        $p = Rapprochement::proposer(
            [87 => ['code' => 'C-0087', 'noms' => ['Hôtel Niger']]],
            ['HOTEL NIGER', 'C-0087 Hôtel Niger', 'Clinique Pasteur'],
        );

        self::assertSame('C-0087 Hôtel Niger', $p[87]['dossier']);
        self::assertSame(Rapprochement::LIE, $p[87]['statut']);
        self::assertSame(100, $p[87]['score']);
    }

    public function testLeCodeNEstPasPrisDansUnAutreNombre(): void
    {
        self::assertTrue(Rapprochement::contientCode('C-0087 Hôtel Niger', 'C-0087'));
        self::assertFalse(Rapprochement::contientCode('C-00871 Autre', 'C-0087'));
        self::assertFalse(Rapprochement::contientCode('XC-0087', 'C-0087'));
    }

    public function testNomSansAccentsNiMajusculesNiMotsDeSociete(): void
    {
        self::assertSame(100, Rapprochement::score('Hôtel Niger', 'HOTEL NIGER'));
        self::assertSame(100, Rapprochement::score('Pharmacie du Fleuve SARL', 'pharmacie fleuve'));
        self::assertSame(['ecole', 'etoiles'], Rapprochement::mots('École Les Étoiles'));
    }

    public function testNomProcheProposeASuggerer(): void
    {
        $p = Rapprochement::proposer(
            [231 => ['code' => 'C-0231', 'noms' => ['Awa Diarra']], 154 => ['code' => 'C-0154', 'noms' => ['École Les Étoiles']]],
            ['AWA MODE - Awa Diarra', 'Ecole Etoiles 2025', 'Garage Sanogo'],
        );

        self::assertSame('AWA MODE - Awa Diarra', $p[231]['dossier']);
        self::assertSame(Rapprochement::SUGGERE, $p[231]['statut']);
        self::assertGreaterThanOrEqual(Rapprochement::SEUIL_SUGGESTION, $p[231]['score']);
        self::assertSame('Ecole Etoiles 2025', $p[154]['dossier']);
        self::assertSame(Rapprochement::SUGGERE, $p[154]['statut']);
    }

    public function testAbreviationReconnue(): void
    {
        // « Pharma » pour « Pharmacie » : début de mot de 4 lettres au moins.
        self::assertGreaterThanOrEqual(Rapprochement::SEUIL_SUGGESTION, Rapprochement::score('Pharmacie du Fleuve', 'Pharma Fleuve'));
    }

    public function testRienDeProcheDonneIntrouvable(): void
    {
        $p = Rapprochement::proposer([176 => ['code' => 'C-0176', 'noms' => ['Garage Sanogo']]], ['HOTEL NIGER', 'Clinique Pasteur Hamdallaye']);

        self::assertNull($p[176]['dossier']);
        self::assertSame(Rapprochement::INTROUVABLE, $p[176]['statut']);
    }

    public function testUnDossierNeVaQuAUnSeulClientLePlusRessemblant(): void
    {
        $p = Rapprochement::proposer(
            [1 => ['code' => '', 'noms' => ['Boulangerie Ballo']], 2 => ['code' => '', 'noms' => ['Boulangerie Ballo et Fils']]],
            ['Boulangerie Ballo et Fils'],
        );

        $attribues = array_filter($p, static fn (array $x): bool => 'Boulangerie Ballo et Fils' === $x['dossier']);
        self::assertCount(1, $attribues);
    }
}
