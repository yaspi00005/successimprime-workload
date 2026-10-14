<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\MarkdownSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownSiteTest extends TestCase
{
    public function testLeHtmlTapeSAfficheSansEtreInterprete(): void
    {
        self::assertSame("<p>Bonjour &lt;script&gt;alert(1)&lt;/script&gt; &amp; co</p>\n", MarkdownSite::html('Bonjour <script>alert(1)</script> & co'));
        self::assertSame("<h2>&lt;img src=x onerror=alert(1)&gt;</h2>\n", MarkdownSite::html('## <img src=x onerror=alert(1)>'));
        self::assertStringNotContainsString('<b>', MarkdownSite::html('- <b>gras</b>'));
        // Les marques internes des liens ne peuvent pas être tapées.
        self::assertSame("<p>a0b</p>\n", MarkdownSite::html("a\u{E000}0\u{E001}b"));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function liens(): iterable
    {
        yield 'web' => ['https://exemple.ml/page?a=1', 'https://exemple.ml/page?a=1'];
        yield 'page du site' => ['/faq', '/faq'];
        yield 'ancre' => ['#gabarits', '#gabarits'];
        yield 'e-mail' => ['mailto:contact@exemple.ml', 'mailto:contact@exemple.ml'];
        yield 'téléphone' => ['tel:+223 70 00 00 00', 'tel:+223 70 00 00 00'];
        yield 'javascript' => ['javascript:alert(1)', null];
        yield 'javascript en majuscules' => ['JavaScript:alert(1)', null];
        yield 'data' => ['data:text/html,<b>x</b>', null];
        yield 'autre site sans protocole' => ['//pirate.ml', null];
        yield 'guillemet' => ['https://a.ml/"onmouseover="x', null];
    }

    #[DataProvider('liens')]
    public function testSeulesLesAdressesSuresFontUnLien(string $url, ?string $attendu): void
    {
        self::assertSame($attendu, MarkdownSite::lienSur($url));
    }

    public function testLiensDansLeTexte(): void
    {
        self::assertSame(
            "<p>Voir <a href=\"https://exemple.ml\" rel=\"noopener\">le <strong>site</strong></a> ou <a href=\"/faq\">la FAQ</a>.</p>\n",
            MarkdownSite::html('Voir [le **site**](https://exemple.ml) ou [la FAQ](/faq).'),
        );
        // Lien refusé : le libellé reste, sans balise.
        $html = MarkdownSite::html('[clic](javascript:alert(1)) et [x](data:text/html,1)');
        self::assertStringNotContainsString('<a', $html);
        self::assertStringNotContainsString('javascript', $html);
        self::assertStringContainsString('clic', $html);
    }

    public function testEmphase(): void
    {
        self::assertSame(
            "<p><strong>gras</strong> et <em>italique</em> et <em>aussi</em> ; 2 * 3 * 4 ; snake_case_nom</p>\n",
            MarkdownSite::html('**gras** et *italique* et _aussi_ ; 2 * 3 * 4 ; snake_case_nom'),
        );
    }

    public function testListesEtapesEncadresEtPastilles(): void
    {
        self::assertSame("<ul><li>un</li><li>deux suite</li><li>trois</li></ul>\n<p>fin</p>\n", MarkdownSite::html("- un\n- deux\n  suite\n\n- trois\n\nfin"));
        self::assertSame("<ol><li>Envoyez</li><li>Validez</li></ol>\n", MarkdownSite::html("1. Envoyez\n2) Validez"));
        self::assertSame("<blockquote><p>Note <strong>forte</strong> ligne 2</p></blockquote>\n", MarkdownSite::html("> Note **forte**\n> ligne 2"));
        self::assertSame("<ul class=\"sc-pastilles\"><li>Œillets</li><li>Ourlets</li><li>Découpe</li></ul>\n", MarkdownSite::html('Œillets · Ourlets · Découpe'));
        // Deux éléments seulement : une phrase ordinaire.
        self::assertSame("<p>Bamako · Mali</p>\n", MarkdownSite::html('Bamako · Mali'));
    }

    public function testRetoursALaLigne(): void
    {
        self::assertSame("<p>Ligne un<br>ligne deux<br>ligne trois même ligne</p>\n", MarkdownSite::html("Ligne un  \nligne deux\\\nligne trois\nmême ligne"));
    }

    public function testLeSeulH1EstDansLeBandeau(): void
    {
        self::assertSame("<h2>Titre un</h2>\n<h4>Quatre</h4>\n<h4>Six</h4>\n", MarkdownSite::html("# Titre un\n#### Quatre\n###### Six"));
        self::assertStringNotContainsString('<h1', MarkdownSite::html("# A\n## B\n### C"));
    }

    public function testSectionsCartesEtEtiquettes(): void
    {
        $contenu = MarkdownSite::sections("Intro.\n\n## Nos machines\nTexte de section.\n### Grand format\n*Atelier*\n- 3,20 m\n### Grand format\n*Textile*\nDTF sur coton.\n## Nos machines\n### Seule");

        self::assertSame('Intro.', $contenu['intro'][0]['texte']);
        self::assertCount(2, $contenu['sections']);
        [$premiere, $seconde] = $contenu['sections'];
        self::assertSame(['Nos machines', 'nos-machines'], [$premiere['titre'], $premiere['ancre']]);
        self::assertSame('Texte de section.', $premiere['blocs'][0]['texte']);
        self::assertSame('nos-machines-2', $seconde['ancre'], 'ancres uniques dans la page');

        [$a, $b] = $premiere['cartes'];
        self::assertSame(['Grand format', 'grand-format', 'Atelier', 'liste'], [$a['titre'], $a['ancre'], $a['etiquette'], $a['blocs'][0]['type']]);
        // Étiquette suivie d'une ligne de texte : la ligne reste le paragraphe de la carte.
        self::assertSame(['grand-format-2', 'Textile', 'DTF sur coton.'], [$b['ancre'], $b['etiquette'], $b['blocs'][0]['html']]);
        self::assertSame([], $seconde['cartes'][0]['blocs']);
    }

    public function testCarteAvantToutTitreDeSection(): void
    {
        $contenu = MarkdownSite::sections("### Sans section\nTexte.");
        self::assertSame('', $contenu['sections'][0]['titre']);
        self::assertSame('Sans section', $contenu['sections'][0]['cartes'][0]['titre']);
    }

    public function testTexteBrutEtAncres(): void
    {
        self::assertSame('Titre gras & co', MarkdownSite::texte("## Titre\n**gras** & co"));
        self::assertSame('fonds-perdus-marges', MarkdownSite::ancre('Fonds perdus & marges'));
        self::assertSame('evenements', MarkdownSite::ancre('Événements'));
    }

    public function testUnTexteTropLongEstCoupe(): void
    {
        $html = MarkdownSite::html(str_repeat('a', MarkdownSite::LONGUEUR_MAX + 50));
        self::assertSame(MarkdownSite::LONGUEUR_MAX, \strlen(strip_tags(trim($html))));
    }
}
