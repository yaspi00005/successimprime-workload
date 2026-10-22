<?php

namespace App\Tests\Unit\Service\Sondages;

use App\Entity\ReponseSondage;
use App\Service\Sondages\Questions;
use App\Service\Sondages\SondageCommande;
use PHPUnit\Framework\TestCase;

final class QuestionsTest extends TestCase
{
    public function testNormaliserLaSaisieDeLAdmin(): void
    {
        $questions = Questions::normaliser([
            ['type' => Questions::ETOILES, 'libelle' => '  Votre   note ', 'obligatoire' => '1'],
            ['type' => Questions::TEXTE, 'libelle' => ''],
            ['type' => Questions::CHOIX_UNIQUE, 'libelle' => 'Stand préféré', 'choix' => "Bâche\r\nTextile\nTextile\n"],
        ]);

        self::assertSame([
            ['cle' => 'q1', 'type' => Questions::ETOILES, 'libelle' => 'Votre note', 'choix' => [], 'obligatoire' => true],
            ['cle' => 'q2', 'type' => Questions::CHOIX_UNIQUE, 'libelle' => 'Stand préféré', 'choix' => ['Bâche', 'Textile'], 'obligatoire' => false],
        ], $questions);
    }

    public function testNormaliserRefuseUnChoixUniqueEtUnTypeInconnu(): void
    {
        $this->expectExceptionMessage('au moins deux choix');
        Questions::normaliser([['type' => Questions::CHOIX_MULTIPLE, 'libelle' => 'Services', 'choix' => ['Bâche']]]);
    }

    public function testValiderNeGardeQueDesValeursPermises(): void
    {
        $questions = Questions::normaliser([
            ['type' => Questions::ETOILES, 'libelle' => 'Note', 'obligatoire' => true],
            ['type' => Questions::NPS, 'libelle' => 'Recommandation'],
            ['type' => Questions::CHOIX_MULTIPLE, 'libelle' => 'Services', 'choix' => ['Bâche', 'Textile']],
            ['type' => Questions::OUI_NON, 'libelle' => 'Projet'],
        ]);

        $reponses = Questions::valider($questions, ['q1' => '4', 'q2' => '11', 'q3' => ['Textile', 'Inventé', 'Bâche'], 'q4' => 'peut-être']);

        self::assertSame(['q1' => 4, 'q3' => ['Bâche', 'Textile']], $reponses);
    }

    public function testValiderExigeLesQuestionsObligatoires(): void
    {
        $questions = Questions::normaliser([['type' => Questions::ETOILES, 'libelle' => 'Note', 'obligatoire' => true]]);

        $this->expectExceptionMessage('Répondez à « Note »');
        Questions::valider($questions, ['q1' => '0']);
    }

    public function testSondageDeCommandeAvecNotesParArticle(): void
    {
        $valide = SondageCommande::valider(['a1', 'a2'], [SondageCommande::GLOBALE => '5', 'articles' => ['a1' => '2', 'a2' => '9', 'autre' => '5']]);

        self::assertSame([SondageCommande::GLOBALE => 5], $valide['reponses']);
        self::assertSame(['a1' => 2], $valide['articles']);
    }

    public function testMauvaiseNoteRappelableCreeUneAlerte(): void
    {
        $rappelable = ReponseSondage::nouvelle(ReponseSondage::LIBRE, [], ReponseSondage::CANAL_QR, new \DateTimeImmutable())
            ->setNotes(2, null)->setIdentite('Awa', '76000001')->setProposition('Le retard nous a gênés.');
        $rappelable->analyser();
        self::assertSame(ReponseSondage::ALERTE_A_RAPPELER, $rappelable->getAlerte());
        self::assertSame('delai', $rappelable->getTheme());
        self::assertSame('a_etudier', $rappelable->getStatutProposition());

        // Anonyme sans téléphone : personne à rappeler, pas d'alerte.
        $anonyme = ReponseSondage::nouvelle(ReponseSondage::LIBRE, [], ReponseSondage::CANAL_QR, new \DateTimeImmutable())->setNotes(1, null);
        $anonyme->analyser();
        self::assertNull($anonyme->getAlerte());

        $bonne = ReponseSondage::nouvelle(ReponseSondage::LIBRE, [], ReponseSondage::CANAL_QR, new \DateTimeImmutable())->setNotes(4, null)->setIdentite('Awa', '76000001');
        $bonne->analyser();
        self::assertNull($bonne->getAlerte());
    }
}
