<?php

namespace App\Tests\Unit\Service\Sondages;

use App\Service\Sondages\Themes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ThemesTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function propositions(): iterable
    {
        yield 'délai' => ['Respecter l\'heure annoncée, le retard nous a gênés.', Themes::DELAI];
        yield 'prix' => ['Le tarif des bâches a augmenté, une remise serait bienvenue.', Themes::PRIX];
        yield 'qualité' => ['Les couleurs étaient pâles sur la bâche.', Themes::QUALITE];
        yield 'accueil' => ['Caissière très aimable, merci pour l\'accueil.', Themes::ACCUEIL];
        yield 'livraison' => ['Le livreur ne connaissait pas l\'adresse.', Themes::LIVRAISON];
        yield 'autre' => ['Ouvrir le samedi après-midi.', Themes::AUTRE];
        yield 'mot court entier seulement' => ['Je cherche un partenaire.', Themes::AUTRE];
        yield 'vide' => ['   ', null];
    }

    #[DataProvider('propositions')]
    public function testClassementParMotsCles(string $texte, ?string $theme): void
    {
        self::assertSame($theme, Themes::classer($texte));
    }

    public function testLibelleParDefaut(): void
    {
        self::assertSame('Prix', Themes::libelle(Themes::PRIX));
        self::assertSame('Autre', Themes::libelle('inconnu'));
    }
}
