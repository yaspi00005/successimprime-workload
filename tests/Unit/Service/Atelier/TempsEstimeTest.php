<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Entity\RetourPrepresse;
use App\Service\Atelier\TempsEstime;
use PHPUnit\Framework\TestCase;

final class TempsEstimeTest extends TestCase
{
    public function testExempleOp03BacheDeuxExemplaires(): void
    {
        // 16 m² à 12 m²/h = 80 min ; séchage 30 ; ourlet + œillets sur 2 bâches = 40 ; contrôle 10.
        $etapes = TempsEstime::proposer(16.0, 12.0, 2, ['Ourlet', 'Œillets'], true, 8.0);
        self::assertSame(['impression', 'sechage', 'finitions', 'controle'], array_column($etapes, 'cle'));
        self::assertSame([80, 30, 40, 10], array_column($etapes, 'propose'));
        self::assertSame(160, TempsEstime::total($etapes));
        self::assertSame('Impression · 16 m² à 12 m²/h', $etapes[0]['libelle']);
        self::assertSame('Ourlet + Œillets (2 ex.)', $etapes[2]['libelle']);
        self::assertSame('2 h 40', TempsEstime::duree(160));
    }

    public function testSansVitesseNiFinitionPasDePropositionPourLImpression(): void
    {
        $etapes = TempsEstime::proposer(3.4, null, 2, [], true);
        self::assertSame(['impression', 'controle'], array_column($etapes, 'cle'));
        self::assertNull($etapes[0]['propose']);
        self::assertSame(10, TempsEstime::total($etapes));
    }

    public function testPetitesPiecesFinitionParLotDeCent(): void
    {
        // 250 cartes pelliculées : 3 lots de 100 × 15 min, sans séchage (pas de solvant).
        $etapes = TempsEstime::proposer(null, null, 250, ['Pelliculage mat'], false, 0.005);
        self::assertSame(45, $etapes[1]['propose']);
        self::assertSame('Pelliculage mat', $etapes[1]['libelle']);
    }

    public function testArrondiAuxCinqMinutesSuperieures(): void
    {
        self::assertSame(0, TempsEstime::arrondir(0));
        self::assertSame(5, TempsEstime::arrondir(0.4));
        self::assertSame(25, TempsEstime::arrondir(21.3));
        self::assertSame(80, TempsEstime::arrondir(80.0));
    }

    public function testLireUneDureeSaisie(): void
    {
        self::assertSame(90, TempsEstime::lire('1 h 30'));
        self::assertSame(90, TempsEstime::lire('1h30'));
        self::assertSame(90, TempsEstime::lire('1:30'));
        self::assertSame(120, TempsEstime::lire('2 h'));
        self::assertSame(45, TempsEstime::lire('45 min'));
        self::assertSame(50, TempsEstime::lire('50'));
        self::assertNull(TempsEstime::lire(''));
        self::assertNull(TempsEstime::lire('bientôt'));
        self::assertNull(TempsEstime::lire('0'));
    }

    public function testAffichageDesDurees(): void
    {
        self::assertSame('30 min', TempsEstime::duree(30));
        self::assertSame('1 h 05', TempsEstime::duree(65));
        self::assertSame('3 h 00', TempsEstime::duree(180));
        self::assertSame('—', TempsEstime::duree(null));
    }

    public function testMachineTropEtroitePourLaPiece(): void
    {
        $machines = [['id' => 1, 'nom' => 'Solvant 3,20 m', 'largeur' => 3.2], ['id' => 2, 'nom' => 'Solvant 1,80 m', 'largeur' => 1.8], ['id' => 3, 'nom' => 'Laser', 'largeur' => null]];
        $r = TempsEstime::machinesPossibles(4.0, 2.0, $machines);
        self::assertTrue($r[0]['possible']);
        self::assertFalse($r[1]['possible']);
        self::assertSame('trop étroite pour 2 m', $r[1]['raison']);
        // Laize inconnue : on ne bloque pas.
        self::assertTrue($r[2]['possible']);
        // Une pièce tournée passe : 1,5 m de large sur 1,80 m.
        self::assertTrue(TempsEstime::machinesPossibles(3.0, 1.5, $machines)[1]['possible']);
    }

    public function testConsommationDeRouleauEtSens(): void
    {
        $r = TempsEstime::rouleau(4.0, 2.0, 2, 3.2);
        self::assertNotNull($r);
        self::assertSame('2 × 4,1 m = 8,2 m de rouleau', $r['texte']);
        self::assertSame('2 m dans la largeur, 4 m dans la longueur', $r['sens']);
        self::assertNull(TempsEstime::rouleau(null, 2.0, 1, 3.2));
    }

    public function testDebutArrondiOuQuandLaMachineSeLibere(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 10:21:10');
        self::assertSame('10:25', TempsEstime::debut($maintenant, null)->format('H:i'));
        self::assertSame('10:30', TempsEstime::debut($maintenant, new \DateTimeImmutable('2026-10-19 10:26'))->format('H:i'));
        self::assertSame('10:25', TempsEstime::debut($maintenant, new \DateTimeImmutable('2026-10-19 09:00'))->format('H:i'));
        self::assertSame('10:20', TempsEstime::debut(new \DateTimeImmutable('2026-10-19 10:20:00'), null)->format('H:i'));
    }

    public function testEcartEtEcartMoyen(): void
    {
        self::assertSame(7.7, TempsEstime::ecart(70, 65));
        self::assertSame(-25.0, TempsEstime::ecart(30, 40));
        self::assertNull(TempsEstime::ecart(null, 40));
        // |7,7| + |−25| + |14,3| = 47 → 16 %
        self::assertSame(16.0, TempsEstime::ecartMoyen([['estime' => 70, 'reel' => 65], ['estime' => 30, 'reel' => 40], ['estime' => 40, 'reel' => 35]]));
        self::assertNull(TempsEstime::ecartMoyen([]));
    }

    public function testDansLesTemps(): void
    {
        $fin = new \DateTimeImmutable('2026-10-20 13:30');
        self::assertTrue(TempsEstime::dansLesTemps($fin, new \DateTimeImmutable('2026-10-20')));
        self::assertFalse(TempsEstime::dansLesTemps($fin, new \DateTimeImmutable('2026-10-20 12:00')));
        self::assertNull(TempsEstime::dansLesTemps($fin, null));
    }

    public function testAnnotationNettoyee(): void
    {
        $propre = RetourPrepresse::nettoyerAnnotation([
            ['type' => 'cercle', 'x1' => -1, 'y1' => 0.2, 'x2' => 0.5, 'y2' => 3],
            ['type' => 'script', 'x1' => 0],
            ['type' => 'texte', 'x1' => 0.1, 'y1' => 0.1, 'texte' => '  '],
            ['type' => 'texte', 'x1' => 0.1, 'y1' => 0.1, 'texte' => 'texte à 3 mm du bord'],
        ]);
        self::assertCount(2, $propre);
        self::assertSame(0.0, $propre[0]['x1']);
        self::assertSame(1.0, $propre[0]['y2']);
        self::assertSame('texte à 3 mm du bord', $propre[1]['texte']);
    }
}
