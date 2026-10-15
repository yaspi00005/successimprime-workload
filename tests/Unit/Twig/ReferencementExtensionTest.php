<?php

namespace App\Tests\Unit\Twig;

use App\Twig\ReferencementExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ReferencementExtensionTest extends TestCase
{
    public function testTitreAvecLeSuffixeQuiTient(): void
    {
        self::assertSame('Questions fréquentes · Succès Imprim, imprimerie à Bamako', ReferencementExtension::titre('Questions fréquentes'));
        self::assertSame('Préparer son fichier pour l\'impression · Succès Imprim', ReferencementExtension::titre("Préparer son fichier\n pour l'impression"));
        // Trop long pour un suffixe : le titre seul.
        $long = 'Comment choisir la bonne bâche pour une façade exposée au soleil';
        self::assertSame($long, ReferencementExtension::titre($long));
        // Le nom y est déjà : rien d'ajouté.
        self::assertSame('Succès Imprim à Sotuba', ReferencementExtension::titre('Succès Imprim à Sotuba'));
        self::assertSame('Nos produits · Succès Imprim', ReferencementExtension::titre('Nos produits · Succès Imprim'));
        foreach (['Questions fréquentes', 'Préparer son fichier pour l\'impression'] as $titre) {
            self::assertLessThanOrEqual(ReferencementExtension::TITRE_MAX, mb_strlen(ReferencementExtension::titre($titre)));
        }
    }

    public function testDescriptionCoupeeEntreDeuxMots(): void
    {
        self::assertSame('Bâches et roll-up à Bamako.', ReferencementExtension::description("  Bâches et\n roll-up   à Bamako. "));
        self::assertSame('', ReferencementExtension::description(null));

        $texte = str_repeat('impression de bâches, ', 12);
        $description = ReferencementExtension::description($texte);
        self::assertLessThanOrEqual(ReferencementExtension::DESCRIPTION_MAX, mb_strlen($description));
        self::assertStringEndsWith('bâches…', $description);
    }

    public function testCheminCanoniqueSansParametres(): void
    {
        $requete = Request::create('https://192.168.1.10:8000/produits?univers=3&utm_source=x');
        self::assertSame('/produits', ReferencementExtension::cheminCanonique($requete));

        $apercu = Request::create('/site');
        $apercu->attributes->set('_route', 'app_site_accueil');
        self::assertSame('/', ReferencementExtension::cheminCanonique($apercu));
        self::assertSame('/', ReferencementExtension::cheminCanonique(null));
    }

    public function testFeuilleDeStyleDansLaPage(): void
    {
        $css = "/* titre */\n.a  >  .b {\n  color: red;\n  background: url(\"../vendor/police.woff2\");\n}\n.c { background: url(../images/x.png) }\n.d{background:url(/images/y.png)}\n.e{background:url(data:image/png;base64,AAAA)}\n.f{content:'</style>'}";

        $resultat = ReferencementExtension::minifier(ReferencementExtension::urlsDepuisLaRacine($css, 'css/site.css'));

        self::assertSame('.a>.b{color: red;background: url("/vendor/police.woff2")}.c{background: url(/images/x.png)}.d{background:url(/images/y.png)}.e{background:url(data:image/png;base64,AAAA)}.f{content:\'<\/style>\'}', $resultat);
        self::assertStringNotContainsStringIgnoringCase('</style', $resultat);
    }
}
