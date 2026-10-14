<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\ArticleConseil;
use App\Entity\AvisClient;
use App\Entity\ImageSite;
use App\Entity\PageSite;
use App\Entity\Partenaire;
use App\Entity\Realisation;
use App\Service\Site\ContenuInitial;
use App\Service\Site\ContenuSite;
use App\Service\Site\IconesSite;
use App\Service\Site\MarkdownSite;
use PHPUnit\Framework\TestCase;

final class ContenuSiteTest extends TestCase
{
    public function testAdresseDUnConseil(): void
    {
        self::assertSame('bache-440-g-ou-510-g', ContenuSite::slug('Bâche 440 g ou 510 g ?'));
        self::assertSame('dtf-ou-serigraphie-que-choisir', ContenuSite::slug('  DTF ou sérigraphie : que choisir ?  '));
        self::assertSame('l-oeil-du-graphiste', ContenuSite::slug('L\'œil du graphiste'));
        self::assertSame('', ContenuSite::slug('?!'));
        $long = ContenuSite::slug(str_repeat('mot ', 60));
        self::assertLessThanOrEqual(ContenuSite::SLUG_MAX, \strlen($long));
        self::assertStringEndsNotWith('-', $long);
        self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $long, 'accepté par la route app_site_conseil');
    }

    public function testPremiereAdresseLibre(): void
    {
        self::assertSame('roll-up', ContenuSite::premiereLibre('roll-up', []));
        self::assertSame('roll-up-2', ContenuSite::premiereLibre('roll-up', ['roll-up']));
        self::assertSame('roll-up-4', ContenuSite::premiereLibre('roll-up', ['roll-up', 'roll-up-2', 'roll-up-3', 'roll-up-5']));
    }

    public function testTypesEtImagesDuContenu(): void
    {
        self::assertSame('realisations', ContenuSite::typeDe(new Realisation()));
        self::assertSame('avis', ContenuSite::typeDe(new AvisClient()));
        self::assertSame('pages', ContenuSite::typeDe(new PageSite('faq')));
        self::assertNull(ContenuSite::typeDe(new \stdClass()));

        self::assertSame([], ContenuSite::imagesDe(new AvisClient()), 'un avis n\'a pas d\'image');
        self::assertSame([], ContenuSite::imagesDe(new ArticleConseil()));
        $png = self::png();
        $logo = ImageSite::depuis($png, 'image/png', 4, 4, ImageSite::USAGE_LOGO);
        self::assertSame([$logo], ContenuSite::imagesDe((new Partenaire())->setLogo($logo)));
        $photo = ImageSite::depuis($png, 'image/png', 4, 4);
        self::assertSame([$photo], ContenuSite::imagesDe((new Realisation())->ajouterImage($photo)->ajouterImage($photo)));
    }

    public function testLeContenuProvisoireResteModeste(): void
    {
        foreach (ContenuInitial::realisations() as $r) {
            self::assertStringContainsString('(démo)', $r['titre']);
        }
        foreach (ContenuInitial::conseils() as $c) {
            self::assertStringContainsString('(démo)', $c['titre']);
            self::assertNotSame('', MarkdownSite::texte($c['corps']));
        }
        $pages = ContenuInitial::pages();
        self::assertSame(array_keys(PageSite::PAGES), array_keys($pages), 'une page par page du site, dans l\'ordre');
        $tout = json_encode([$pages, ContenuInitial::realisations(), ContenuInitial::conseils()], \JSON_UNESCAPED_UNICODE);
        // Pas de note inventée, pas de nombre de clients ou de commandes, pas de prix.
        self::assertDoesNotMatchRegularExpression('/\d\s*(clients|commandes|projets)|\d[,.]\d\s*\/\s*5|★|\d[\d\s]*\s*(F\s?CFA|FCFA|francs)/iu', (string) $tout);
        foreach ($pages as $slug => $page) {
            self::assertLessThanOrEqual(300, mb_strlen($page['description']), $slug);
            self::assertStringNotContainsString('<', $page['corps'], $slug.' : du Markdown, pas de HTML');
        }
        self::assertStringContainsString('3,20 m', $pages['equipements']['corps']);
    }

    public function testIconeDUneCarteSelonSonTitre(): void
    {
        self::assertSame('bat', IconesSite::pour('BAT avant chaque impression'));
        self::assertSame('reimpression', IconesSite::pour('Réimpression si le défaut vient de nous'));
        self::assertSame('prix', IconesSite::pour('Paiement Orange Money'));
        self::assertSame('ciseaux', IconesSite::pour('Fonds perdus & marges'));
        self::assertSame('livraison', IconesSite::pour('Pose sur site'));
        self::assertSame(IconesSite::DEFAUT, IconesSite::pour('Autre chose'));
        // Mot cherché en début de mot seulement : « combat » n'est pas un BAT.
        self::assertSame(IconesSite::DEFAUT, IconesSite::pour('Le combat'));
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
