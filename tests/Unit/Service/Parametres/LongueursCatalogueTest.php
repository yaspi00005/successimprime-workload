<?php

namespace App\Tests\Unit\Service\Parametres;

use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Service\Parametres\LongueursCatalogue;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\TestCase;

/**
 * Nom et description des finitions, formats, supports et types
 * d'impression : la limite suit la colonne, et le champ de la fenêtre porte
 * la même (maxlength).
 */
final class LongueursCatalogueTest extends TestCase
{
    private const RACINE = __DIR__.'/../../../..';

    public function testUnTexteQuiTientPasse(): void
    {
        // Les caractères comptent, pas les octets : 255 « é » tiennent.
        self::assertNull(LongueursCatalogue::erreur([
            'Nom' => [str_repeat('é', 255), 255],
            'Description' => ['', 255],
        ]));
    }

    public function testLePremierTexteTropLongEstSignale(): void
    {
        self::assertSame('Nom du format : 150 caractères au plus.', LongueursCatalogue::erreur([
            'Nom du format' => [str_repeat('a', 151), LongueursCatalogue::FORMAT_NOM],
        ]));
        self::assertSame('Description : 255 caractères au plus.', LongueursCatalogue::erreur([
            'Nom' => ['Offset', 255],
            'Description' => [str_repeat('é', 256), 255],
            'Autre' => [str_repeat('x', 300), 10],
        ]));
    }

    public function testLesLimitesSuiventLesColonnes(): void
    {
        self::assertSame(LongueursCatalogue::FINITION_NOM, self::longueur(Finition::class, 'nom'));
        self::assertSame(LongueursCatalogue::FORMAT_NOM, self::longueur(Format::class, 'nom'));
        self::assertSame(LongueursCatalogue::SUPPORT_NOM, self::longueur(Supports::class, 'nom'));
        self::assertSame(LongueursCatalogue::TYPE_IMPRESSION_NOM, self::longueur(TypesImpression::class, 'nom'));
        self::assertSame(LongueursCatalogue::TYPE_IMPRESSION_DESCRIPTION, self::longueur(TypesImpression::class, 'description'));

        // Descriptions en texte long : pas de limite à vérifier.
        foreach ([Finition::class, Format::class, Supports::class] as $classe) {
            self::assertSame(Types::TEXT, self::colonne($classe, 'description')->type, $classe);
        }
    }

    public function testLesChampsDesFenetresPortentLaLimite(): void
    {
        $champs = [
            'finition' => ['finitionCreateNom' => LongueursCatalogue::FINITION_NOM, 'finitionEditNom' => LongueursCatalogue::FINITION_NOM],
            'format' => ['formatCreateNom' => LongueursCatalogue::FORMAT_NOM, 'formatEditNom' => LongueursCatalogue::FORMAT_NOM],
            'supports' => ['supportCreateNom' => LongueursCatalogue::SUPPORT_NOM, 'supportEditNom' => LongueursCatalogue::SUPPORT_NOM],
            'types_impression' => [
                'typeImpressionCreateNom' => LongueursCatalogue::TYPE_IMPRESSION_NOM,
                'typeImpressionEditNom' => LongueursCatalogue::TYPE_IMPRESSION_NOM,
                'typeImpressionCreateDescription' => LongueursCatalogue::TYPE_IMPRESSION_DESCRIPTION,
                'typeImpressionEditDescription' => LongueursCatalogue::TYPE_IMPRESSION_DESCRIPTION,
            ],
        ];

        foreach ($champs as $dossier => $ids) {
            $page = (string) file_get_contents(self::RACINE.'/templates/'.$dossier.'/index.html.twig');
            foreach ($ids as $id => $maximum) {
                self::assertMatchesRegularExpression('~<(?:input|textarea)\b[^>]*\bid="'.$id.'"[^>]*\bmaxlength="'.$maximum.'"~', $page, $dossier.' : '.$id);
            }
        }
    }

    /** @param class-string $classe */
    private static function longueur(string $classe, string $propriete): ?int
    {
        return self::colonne($classe, $propriete)->length;
    }

    /** @param class-string $classe */
    private static function colonne(string $classe, string $propriete): Column
    {
        $attributs = (new \ReflectionProperty($classe, $propriete))->getAttributes(Column::class);
        self::assertCount(1, $attributs, $classe.'::'.$propriete);

        return $attributs[0]->newInstance();
    }
}
