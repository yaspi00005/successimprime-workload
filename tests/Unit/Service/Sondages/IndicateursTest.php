<?php

namespace App\Tests\Unit\Service\Sondages;

use App\Service\Sondages\Indicateurs;
use App\Service\Sondages\Questions;
use PHPUnit\Framework\TestCase;

final class IndicateursTest extends TestCase
{
    public function testMoyenneEtClientsSatisfaits(): void
    {
        self::assertSame(3.8, Indicateurs::moyenne([5, 4, 4, 2, null, 4]));
        self::assertSame(80, Indicateurs::satisfaits([5, 4, 4, 2, 4]));
        self::assertNull(Indicateurs::moyenne([]));
        self::assertNull(Indicateurs::satisfaits([null]));
    }

    public function testNpsDeMoinsCentAPlusCent(): void
    {
        // 2 promoteurs, 1 passif, 1 détracteur sur 4.
        self::assertSame(25, Indicateurs::nps([10, 9, 8, 3]));
        self::assertSame(-100, Indicateurs::nps([0, 6]));
        self::assertNull(Indicateurs::nps([]));
    }

    public function testTauxDeReponse(): void
    {
        self::assertSame(34, Indicateurs::tauxReponse(17, 50));
        self::assertSame(100, Indicateurs::tauxReponse(12, 10));
        self::assertNull(Indicateurs::tauxReponse(5, null));
        self::assertNull(Indicateurs::tauxReponse(5, 0));
    }

    public function testSeuilsDAlerte(): void
    {
        self::assertTrue(Indicateurs::moyenneEnAlerte(4.1));
        self::assertFalse(Indicateurs::moyenneEnAlerte(4.2));
        self::assertTrue(Indicateurs::produitEnAlerte(3.4));
        self::assertFalse(Indicateurs::produitEnAlerte(3.5));
        self::assertTrue(Indicateurs::noteEnAlerte(2));
        self::assertFalse(Indicateurs::noteEnAlerte(3));
        self::assertFalse(Indicateurs::noteEnAlerte(null));

        $resume = Indicateurs::resume([4, 4, 3], [2, 3], 3, null);
        self::assertTrue($resume['alerte']);
        self::assertTrue($resume['alerteNps']);
        self::assertNull($resume['taux']);
    }

    public function testResultatsParQuestion(): void
    {
        $questions = [
            ['cle' => 'q1', 'type' => Questions::ETOILES, 'libelle' => 'Note', 'choix' => [], 'obligatoire' => true],
            ['cle' => 'q2', 'type' => Questions::CHOIX_MULTIPLE, 'libelle' => 'Services', 'choix' => ['Bâche', 'Textile'], 'obligatoire' => false],
            ['cle' => 'q3', 'type' => Questions::TEXTE, 'libelle' => 'Proposition', 'choix' => [], 'obligatoire' => false],
        ];
        $reponses = [
            9 => ['q1' => 5, 'q2' => ['Bâche', 'Textile'], 'q3' => 'Plus de couleurs'],
            8 => ['q1' => 4, 'q2' => ['Textile']],
            7 => ['q1' => 9, 'q2' => ['Inventé']],
        ];

        [$note, $services, $textes] = Indicateurs::parQuestion($questions, $reponses);

        // Une note hors de 1 à 5 ou un choix inventé ne compte pas.
        self::assertSame(2, $note['repondu']);
        self::assertSame(4.5, $note['moyenne']);
        self::assertSame(['note' => 5, 'nombre' => 1, 'part' => 50], $note['repartition'][0]);
        self::assertSame([['libelle' => 'Bâche', 'nombre' => 1, 'part' => 50], ['libelle' => 'Textile', 'nombre' => 2, 'part' => 100]], $services['options']);
        self::assertSame([['reponse' => 9, 'texte' => 'Plus de couleurs']], $textes['textes']);
    }
}
