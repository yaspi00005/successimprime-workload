<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pages du catalogue (Réglages › Produits et options) : plus de texte
 * d'exemple du thème (« User index », « 1 - 30 of 546 users », « Latest »),
 * un titre en français et un fil d'Ariane qui mène aux Paramètres.
 */
final class PagesCatalogueSansTexteDuThemeTest extends TestCase
{
    private const TEXTES_DU_THEME = [
        '/User index/i',
        '/Liste des Utilisateurs/i',
        '/\d+\s*-\s*\d+\s+of\s+\d+\s+users/i',
        '/>\s*(Latest|Oldest|Newest)\s*</i',
        '/placeholder=""/',
        '/<a href="#">/',
    ];

    /** @return iterable<string, array{string, string}> */
    public static function pages(): iterable
    {
        yield 'finitions' => ['finition/index.html.twig', 'Finitions'];
        yield 'types d’impression' => ['types_impression/index.html.twig', 'Types d’impression'];
        yield 'supports' => ['supports/index.html.twig', 'Supports'];
        yield 'formats' => ['format/index.html.twig', 'Formats'];
        yield 'catégories' => ['categorie_produit/index.html.twig', 'Catégories de produits'];
        yield 'configurations' => ['produit_configuration/index.html.twig', 'Configurations des produits'];
        yield 'produits' => ['produits/index.html.twig', 'Produits'];
        yield 'machines' => ['machines/index.html.twig', 'Machines'];
    }

    #[DataProvider('pages')]
    public function testPageSansTexteDuTheme(string $gabarit, string $titre): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/'.$gabarit);

        foreach (self::TEXTES_DU_THEME as $motif) {
            self::assertDoesNotMatchRegularExpression($motif, $source, $gabarit);
        }
        self::assertMatchesRegularExpression('/\{%\s*block title\s*%\}\s*'.preg_quote($titre, '/').'\s*\{%\s*endblock/u', $source, $gabarit);
    }
}
