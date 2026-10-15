<?php

namespace App\Tests\Unit\Service\Atelier;

use App\Service\Atelier\CatalogueAtelier;
use PHPUnit\Framework\TestCase;

/**
 * Fiche des modèles GLB du studio (public/modeles-3d/modeles.json,
 * docs/atelier-3d.md) : relue sans être crue, elle ne donne que des
 * nombres pour les repères et du texte court pour les crédits.
 */
final class CatalogueAtelierTest extends TestCase
{
    public function testFicheDesModelesLueEnFrancaisOuEnAnglais(): void
    {
        $m = CatalogueAtelier::lireModeles3d((string) json_encode([
            'tshirt.glb' => [
                'front' => [0, 0, 1],
                'chest' => [0.0, 0.42, 0.13],
                'back' => [0, 0.40, -0.12],
                'heightCm' => 74,
                'credits' => ['title' => 'T-Shirt', 'author' => 'Awa D.', 'license' => 'CC BY 4.0', 'licenseUrl' => 'https://creativecommons.org/licenses/by/4.0/', 'source' => 'https://exemple.org/tshirt'],
            ],
            'casquette' => [
                'devant' => [0, 0, 1], 'face' => [0, 0.07, 0.1], 'cote' => [0.09, 0.05, 0], 'arrière' => [0, 0.06, -0.09],
                'credits' => ['titre' => 'Casquette', 'auteur' => 'Moussa', 'licence' => 'CC0'],
            ],
            'robe' => ['poitrine' => [0, 1, 0]],
        ]));

        self::assertSame(['tshirt', 'casquette'], array_keys($m), 'forme inconnue écartée');
        self::assertSame(['devant' => [0.0, 0.0, 1.0], 'poitrine' => [0.0, 0.42, 0.13], 'dos' => [0.0, 0.4, -0.12]], $m['tshirt']['reperes']);
        self::assertSame(74.0, $m['tshirt']['tailleCm']);
        self::assertSame('CC BY 4.0', $m['tshirt']['credits']['licence']);
        self::assertSame('https://exemple.org/tshirt', $m['tshirt']['credits']['sourceUrl']);
        self::assertSame(['devant', 'poitrine', 'cote', 'arriere'], array_keys($m['casquette']['reperes']), 'centre du devant d\'une casquette : « face »');
        self::assertNull($m['casquette']['tailleCm'], 'sans taille : le GLB est en mètres');
    }

    public function testFicheMalFormeeOuPiegeeSansEffet(): void
    {
        self::assertSame([], CatalogueAtelier::lireModeles3d('pas du json'));
        self::assertSame([], CatalogueAtelier::lireModeles3d('[1, 2]'));

        $m = CatalogueAtelier::lireModeles3d((string) json_encode(['polo' => [
            'devant' => [0, 0, 0], // direction nulle : ignorée
            'poitrine' => ['0', 1, 2], // texte : ignoré
            'dos' => [0, 1],
            'hauteurCm' => -3,
            'credits' => [
                'titre' => "Polo\x07 <b>gras</b>".str_repeat('x', 300),
                'licenceUrl' => 'javascript:alert(1)',
                'sourceUrl' => 'https://exemple.org/a b',
            ],
        ]]));
        self::assertSame([], $m['polo']['reperes']);
        self::assertNull($m['polo']['tailleCm']);
        self::assertSame(160, mb_strlen($m['polo']['credits']['titre']), 'texte court, échappé à l\'affichage par Twig');
        self::assertStringStartsWith('Polo  <b>', $m['polo']['credits']['titre']);
        self::assertArrayNotHasKey('licenceUrl', $m['polo']['credits'], 'seulement des adresses http(s)');
        self::assertArrayNotHasKey('sourceUrl', $m['polo']['credits']);

        $sansNom = CatalogueAtelier::lireModeles3d('{"polo": {"credits": {"licence": "CC0"}}}');
        self::assertNull($sansNom['polo']['credits'], 'crédit sans titre ni auteur : pas affiché');
    }
}
