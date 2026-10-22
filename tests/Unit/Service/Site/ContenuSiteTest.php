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

    public function testUneAnnonceSeRegleDansLAtelier(): void
    {
        $em = $this->createStub(\Doctrine\ORM\EntityManagerInterface::class);
        $contenu = new ContenuSite($em, new \App\Service\Site\ImagesSite(sys_get_temp_dir().'/si-annonces-public', sys_get_temp_dir().'/si-annonces-originaux'));
        $maintenant = new \DateTimeImmutable('2026-10-15 09:00');
        self::assertSame('annonces', ContenuSite::typeDe(new \App\Entity\Annonce()));
        self::assertSame([], ContenuSite::imagesDe(new \App\Entity\Annonce()), 'une annonce n\'a pas d\'image');

        $annonce = new \App\Entity\Annonce();
        $retour = $contenu->enregistrer($annonce, [
            'texte' => "Fermé le 20 octobre\r\n(Maouloud).", 'lien' => 'javascript:alert(1)', 'style' => 'alerte', 'cible' => 'espace',
            'debut' => '2026-10-16', 'fin' => '2026-10-20', 'ordre' => '2', 'publie' => '1',
        ], [], $maintenant);
        self::assertSame([], $retour['erreurs']);
        self::assertSame(['Lien ignoré : seule une adresse web (https://…) ou une page du site (/nos-produits) est acceptée.'], $retour['avertissements']);
        self::assertSame('Fermé le 20 octobre (Maouloud).', $annonce->getTexte());
        self::assertNull($annonce->getLien());
        self::assertSame(['alerte', 'espace', 2], [$annonce->getStyle(), $annonce->getCible(), $annonce->getOrdre()]);
        self::assertTrue($annonce->isPublie());
        self::assertSame('programmee', $annonce->etat($maintenant));
        self::assertEquals($maintenant, $annonce->getModifieLe(), 'la date de modification part avec la synchronisation');

        // Trop long, période à l'envers, date impossible : rien n'est gardé.
        $retour = $contenu->enregistrer(new \App\Entity\Annonce(), ['texte' => str_repeat('a', 201), 'debut' => '2026-10-20', 'fin' => '2026-10-18'], [], $maintenant);
        self::assertCount(2, $retour['erreurs']);
        $retour = $contenu->enregistrer(new \App\Entity\Annonce(), ['texte' => 'Promo', 'fin' => '2026-02-31'], [], $maintenant);
        self::assertSame(['Le dernier jour n\'est pas valable.'], $retour['erreurs']);
        $retour = $contenu->enregistrer(new \App\Entity\Annonce(), ['texte' => '   '], [], $maintenant);
        self::assertSame(['Le texte de l\'annonce est obligatoire.'], $retour['erreurs']);
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
