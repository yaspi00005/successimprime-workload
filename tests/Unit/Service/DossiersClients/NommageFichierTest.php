<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Service\DossiersClients\NommageFichier;
use PHPUnit\Framework\TestCase;

/** Fichiers à classer (DS03) : nom automatique, sous-dossier proposé, versions. */
final class NommageFichierTest extends TestCase
{
    public function testNomAutomatiqueDeLaMaquette(): void
    {
        self::assertSame('CM-0175_photo-facade_2026-10-19.jpg', NommageFichier::nomAutomatique('CM-0175', 'photo façade', new \DateTimeImmutable('2026-10-19'), 'JPG'));
        // Photo WhatsApp liée à la commande « Bâche façade » : nom tiré de la commande, pas du téléphone.
        $nom = NommageFichier::nomAutomatique('CM-0175', NommageFichier::description('IMG-20261019-WA0012.jpg', 'photos', 'Bâche façade', 'whatsapp'), new \DateTimeImmutable('2026-10-19'), 'jpg');
        self::assertMatchesRegularExpression('/^CM-0175_[a-z0-9-]*facade[a-z0-9-]*_2026-10-19\.jpg$/', $nom);
    }

    public function testDescriptionSansNumeroNiDateNiMotsDuTelephone(): void
    {
        $d = NommageFichier::description('IMG-20261019-WA0012.jpg', 'photos', null, 'whatsapp');
        self::assertDoesNotMatchRegularExpression('/\d{6,}|wa0012|img/i', $d);
        self::assertNotSame('', $d);
        self::assertSame('logo-clinique-hd', NommageFichier::description('logo clinique HD.png', 'recus'));
    }

    public function testNomJamaisEcrase(): void
    {
        $existants = ['a.jpg' => true, 'a-2.jpg' => true];
        self::assertSame('a-3.jpg', NommageFichier::nomLibre('a.jpg', static fn (string $n): bool => isset($existants[$n])));
        self::assertSame('b.jpg', NommageFichier::nomLibre('b.jpg', static fn (string $n): bool => isset($existants[$n])));
    }

    public function testSousDossierPropose(): void
    {
        self::assertSame('bat', NommageFichier::sousDossier('BAT kakemono.pdf')['cle']);
        self::assertSame('documents', NommageFichier::sousDossier('devis signé.pdf', 'scanner')['cle']);
        self::assertSame('prets', NommageFichier::sousDossier('etiquette prix v2 FINAL.pdf')['cle']);
        self::assertSame('photos', NommageFichier::sousDossier('IMG-20261019-WA0012.jpg', 'whatsapp')['cle']);
        self::assertSame('conceptions', NommageFichier::sousDossier('Sans titre-1.psd')['cle']);
    }

    public function testOrigine(): void
    {
        self::assertSame('whatsapp', NommageFichier::origine('IMG-20261019-WA0012.jpg')['type']);
        self::assertSame('scanner', NommageFichier::origine('Scanner/scan devis.pdf')['type']);
        self::assertSame('WhatsApp Images', NommageFichier::origine('WhatsApp Images/IMG-20261019-WA0012.jpg')['libelle']);
        self::assertSame('Bureau', NommageFichier::origine('Bureau/logo.png')['libelle']);
    }

    public function testNumerosDeCommandeDeClientEtDeDevis(): void
    {
        self::assertSame(['CM-0175'], NommageFichier::numeros('photo CM-0175 facade.jpg'));
        self::assertContains('CMD-DC0001', NommageFichier::numeros('WhatsApp Images CMD-DC0001 IMG.jpg'));
        self::assertContains('D-2026-0214', NommageFichier::numeros('devis D-2026-0214 signé.pdf'));
        self::assertSame([], NommageFichier::numeros('logo clinique HD.png'));
    }

    public function testVersions(): void
    {
        self::assertSame(['base' => 'kakemono-soiree-jazz.ai', 'version' => 3], NommageFichier::version('kakemono-soiree-jazz_v3.ai'));
        self::assertSame('kakemono-soiree-jazz.ai', NommageFichier::version('kakemono-soiree-jazz FINAL.ai')['base']);
        self::assertNull(NommageFichier::version('kakemono-soiree-jazz FINAL.ai')['version']);
    }

    public function testSourcesEtProgrammes(): void
    {
        self::assertTrue(NommageFichier::estSource('affiche.AI'));
        self::assertTrue(NommageFichier::estSource('photo.psd'));
        self::assertFalse(NommageFichier::estSource('bat.pdf'));
        self::assertTrue(NommageFichier::estProgramme('facture.pdf.exe'));
        self::assertTrue(NommageFichier::estProgramme('raccourci.lnk'));
        self::assertFalse(NommageFichier::estProgramme('kakemono.ai'));
    }
}
