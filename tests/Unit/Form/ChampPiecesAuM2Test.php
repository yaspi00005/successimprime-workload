<?php

namespace App\Tests\Unit\Form;

use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Form\ChampPiecesAuM2;
use App\Service\PiecesParMetreCarre;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

/**
 * Case « Pièces au m² » de la saisie : seul le champ caché « 1 » / « 0 »
 * compte ; un envoi sans lui ne change pas la ligne.
 */
final class ChampPiecesAuM2Test extends TestCase
{
    public function testChoixLu(): void
    {
        self::assertTrue(ChampPiecesAuM2::choix('1'));
        self::assertFalse(ChampPiecesAuM2::choix('0'));
        foreach ([null, '', 'oui', ['1'], 1, true] as $autre) {
            self::assertNull(ChampPiecesAuM2::choix($autre), var_export($autre, true));
        }
    }

    public function testLaCaseCocheeMetLaLigneEnPiecesAuM2(): void
    {
        $ligne = self::autocollants(new CommandesDetails());

        self::formulaire($ligne)->submit(['quantite' => '1000', ChampPiecesAuM2::NOM => '1']);

        self::assertSame(PiecesParMetreCarre::ESPACEMENT_DEFAUT, $ligne->getEspacementPiecesM2(), 'sans réglage lu : 1 mm');
        self::assertSame(18070, $ligne->calculerTotaux()->getTotalHt());
    }

    public function testLaCaseRevientCocheeEtDecocherRetireLeCalcul(): void
    {
        $ligne = self::autocollants(new DevisDetails())->setEspacementPiecesM2(1.5);

        $form = self::formulaire($ligne);
        self::assertSame('1', $form->get(ChampPiecesAuM2::NOM)->getData(), 'ligne déjà en pièces au m²');

        $form->submit(['quantite' => '1000', ChampPiecesAuM2::NOM => '0']);
        self::assertNull($ligne->getEspacementPiecesM2());
    }

    public function testUnEnvoiSansLeChampNeChangeRien(): void
    {
        $ligne = self::autocollants(new CommandesDetails())->setEspacementPiecesM2(2);

        self::formulaire($ligne)->submit(['quantite' => '1000']);

        self::assertSame(2.0, $ligne->getEspacementPiecesM2());
    }

    private static function autocollants(CommandesDetails|DevisDetails $ligne): CommandesDetails|DevisDetails
    {
        return $ligne->setModeCalcul('metre_carre')->setLargeur(0.05)->setLongueur(0.05)->setQuantite(1000)->setPrixUnitaire(6500);
    }

    private static function formulaire(CommandesDetails|DevisDetails $ligne): FormInterface
    {
        $builder = Forms::createFormFactory()->createBuilder(FormType::class, $ligne, ['data_class' => $ligne::class]);
        $builder->add('quantite', NumberType::class);
        ChampPiecesAuM2::ajouter($builder, null);

        return $builder->getForm();
    }
}
