<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\Conception;
use App\Service\Synchro\FichiersSynchro;
use PHPUnit\Framework\TestCase;

final class FichiersSynchroTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/si-fichiers-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dossier.'/{,.}*', \GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->dossier);
    }

    public function testEnvoiParMorceauxEtReprise(): void
    {
        $contenu = random_bytes(10000);
        $empreinte = hash('sha256', $contenu);
        [$a, $b] = [substr($contenu, 0, 6000), substr($contenu, 6000)];

        self::assertSame(['recu' => 0, 'termine' => false], FichiersSynchro::ecrire($this->dossier, 'bat.pdf', $empreinte, 10000, -1, null));
        self::assertSame(['recu' => 6000, 'termine' => false], FichiersSynchro::ecrire($this->dossier, 'bat.pdf', $empreinte, 10000, 0, $a));
        // Coupure puis reprise : le site dit où il en est, un morceau mal placé n'est pas écrit.
        self::assertSame(['recu' => 6000, 'termine' => false], FichiersSynchro::ecrire($this->dossier, 'bat.pdf', $empreinte, 10000, -1, null));
        self::assertSame(['recu' => 6000, 'termine' => false], FichiersSynchro::ecrire($this->dossier, 'bat.pdf', $empreinte, 10000, 0, $a));
        self::assertFileDoesNotExist($this->dossier.'/bat.pdf', 'pas rangé avant d\'être complet');

        self::assertSame(['recu' => 10000, 'termine' => true], FichiersSynchro::ecrire($this->dossier, 'bat.pdf', $empreinte, 10000, 6000, $b));
        self::assertSame($contenu, file_get_contents($this->dossier.'/bat.pdf'));
        self::assertSame(['recu' => 10000, 'termine' => true], FichiersSynchro::ecrire($this->dossier, 'bat.pdf', $empreinte, 10000, -1, null), 'déjà là');
    }

    public function testEmpreinteFausseRefusee(): void
    {
        $this->expectExceptionMessage('Empreinte différente');
        try {
            FichiersSynchro::ecrire($this->dossier, 'bat.pdf', str_repeat('a', 64), 3, 0, 'abc');
        } finally {
            self::assertFileDoesNotExist($this->dossier.'/bat.pdf');
        }
    }

    public function testNomDangereuxRefuse(): void
    {
        foreach (['../evil.php', '.htaccess', 'a/b.pdf', ''] as $nom) {
            try {
                FichiersSynchro::ecrire($this->dossier, $nom, str_repeat('a', 64), 3, 0, 'abc');
                self::fail($nom);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLeClientNeVoitQueSonHistoireDeBat(): void
    {
        $publics = Conception::evenementsPublics([
            ['le' => '2026-10-12T10:00:00+00:00', 'type' => 'prise', 'titre' => 'Prise en charge', 'detail' => 'Moussa démarre le chronomètre'],
            ['le' => '2026-10-12T11:00:00+00:00', 'type' => 'bat', 'titre' => 'BAT V1 envoyé', 'detail' => '2 h après l\'échéance'],
            ['le' => '2026-10-12T12:00:00+00:00', 'type' => 'erreur', 'titre' => 'Erreur à corriger', 'detail' => 'erreur du graphiste'],
            ['le' => '2026-10-12T13:00:00+00:00', 'type' => 'retour', 'titre' => 'Retour client', 'detail' => '« logo en bleu » · demande du client'],
        ]);
        self::assertSame(['bat', 'retour'], array_column($publics, 'type'));
        self::assertNull($publics[0]['detail'], 'pas de retard affiché au client');
        self::assertStringContainsString('logo en bleu', (string) $publics[1]['detail']);
    }
}
