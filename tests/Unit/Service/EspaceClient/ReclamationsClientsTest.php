<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\ReclamationClient;
use App\Service\EspaceClient\ReclamationsClients;
use PHPUnit\Framework\TestCase;

final class ReclamationsClientsTest extends TestCase
{
    public function testPngReduiteEtReencodee(): void
    {
        $image = imagecreatetruecolor(3200, 1600);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        [$octets, $type, $largeur, $hauteur] = ReclamationsClients::reduire($png);

        self::assertSame([1600, 800], [$largeur, $hauteur]);
        self::assertContains($type, ['image/jpeg', 'image/png']);
        self::assertSame([1600, 800], \array_slice((array) getimagesizefromstring($octets), 0, 2));
    }

    public function testCeQuiNEstPasUnePhotoEstRefuse(): void
    {
        foreach (['', 'pas une image', '%PDF-1.4 faux'] as $octets) {
            try {
                ReclamationsClients::reduire($octets);
                self::fail('Refus attendu');
            } catch (\DomainException $e) {
                self::assertStringContainsString('pas une photo', $e->getMessage());
            }
        }
    }

    public function testJpegSansExifNiCommentaire(): void
    {
        // SOI, APP0 (JFIF), APP1 (Exif avec une position), COM, DQT, SOS + données, EOI.
        $segment = static fn (int $m, string $d): string => "\xFF".\chr($m).pack('n', \strlen($d) + 2).$d;
        $exif = $segment(0xE1, "Exif\0\0GPS 12.65N 8.00W");
        $commentaire = $segment(0xFE, 'Appareil de Moussa');
        $jpeg = "\xFF\xD8"
            .$segment(0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0")
            .$exif
            .$commentaire
            .$segment(0xDB, str_repeat("\x01", 65))
            ."\xFF\xDA".pack('n', 4)."\0\0DONNEES\xFF\xD9";

        $propre = ReclamationsClients::jpegSansMetadonnees($jpeg);

        self::assertStringNotContainsString('GPS', $propre);
        self::assertStringNotContainsString('Moussa', $propre);
        self::assertStringContainsString('JFIF', $propre);
        self::assertStringEndsWith("DONNEES\xFF\xD9", $propre);
        self::assertSame(\strlen($jpeg) - \strlen($exif) - \strlen($commentaire), \strlen($propre));
    }

    public function testReponseEtCloture(): void
    {
        $r = ReclamationClient::nouvelle(new Clients(), '  Couleur du logo trop foncée ', 'Le bleu sort noir.', new \DateTimeImmutable('2026-10-12 09:00'));
        self::assertSame('Couleur du logo trop foncée', $r->getObjet());
        self::assertSame(ReclamationClient::OUVERTE, $r->getStatut());

        $r->repondre('Nous réimprimons, prêts vendredi.', ReclamationClient::EN_COURS, 'Kadiatou', new \DateTimeImmutable('2026-10-12 11:00'));
        self::assertFalse($r->estClose());
        self::assertNull($r->getDureeTraitement());

        $r->repondre('Réimprimés et livrés.', ReclamationClient::RESOLUE, 'Kadiatou', new \DateTimeImmutable('2026-10-13 10:00'));
        self::assertTrue($r->estClose());
        self::assertSame('1 jour', $r->getDureeTraitement());

        $this->expectExceptionMessage('déjà close');
        $r->repondre('Encore', ReclamationClient::EN_COURS, 'Kadiatou', new \DateTimeImmutable());
    }

    public function testReponseVideOuStatutOuvertRefuses(): void
    {
        $r = ReclamationClient::nouvelle(new Clients(), 'Retard', 'Toujours pas livré.', new \DateTimeImmutable());
        foreach ([['  ', ReclamationClient::RESOLUE], ['Ok', ReclamationClient::OUVERTE], ['Ok', 'inconnu']] as [$texte, $statut]) {
            try {
                $r->repondre($texte, $statut, 'Kadiatou', new \DateTimeImmutable());
                self::fail('Refus attendu');
            } catch (\DomainException) {
            }
        }
        self::assertSame(ReclamationClient::OUVERTE, $r->getStatut());
    }
}
