<?php

namespace App\Tests\Unit\Twig;

use App\Entity\Articles;
use App\Entity\CommandesDetails;
use App\Entity\Produits;
use App\Twig\NomCommercialExtension;
use PHPUnit\Framework\TestCase;

/** Filtres de gabarit du nom commercial : ordre des noms et échappement (docs/noms-commerciaux.md). */
final class NomCommercialExtensionTest extends TestCase
{
    public function testClientEtErp(): void
    {
        $twig = new NomCommercialExtension();
        $vinyle = (new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant');

        self::assertSame('<span class="nc-nom">Autocollant</span> <small class="nc-technique">Vinyle adhésif</small>', $twig->nomClient($vinyle));
        self::assertSame('Vinyle adhésif <small class="nc-commercial text-muted">Autocollant</small>', $twig->nomInterne($vinyle));
        self::assertSame('Autocollant (Vinyle adhésif)', $twig->texteClient($vinyle));
        self::assertSame('Vinyle adhésif (Autocollant)', $twig->texteInterne($vinyle));
        // Sans nom commercial : la désignation seule, sans balise ni parenthèses.
        $bache = (new Produits())->setNom('Bâche');
        self::assertSame('Bâche', $twig->nomClient($bache));
        self::assertSame('Bâche', $twig->texteInterne($bache));
        self::assertSame('', $twig->nomClient(null));
    }

    public function testToutEstEchappe(): void
    {
        $twig = new NomCommercialExtension();
        $piege = (new Articles())->setDesignation('Encre <script>alert(1)</script>')->setNomCommercial('Cartouche "cyan" & co');

        $html = $twig->nomClient($piege);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Cartouche &quot;cyan&quot; &amp; co', $html);
        self::assertStringContainsString('Encre &lt;script&gt;', $twig->nomInterne($piege));
    }

    public function testLignes(): void
    {
        $twig = new NomCommercialExtension();
        $ligne = (new CommandesDetails())->setProduit((new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant'))
            ->setDesignation('Vinyle adhésif (100 x 50 cm)')->setLargeur('1')->setLongueur('0.5')->setQuantite(2);

        self::assertSame('<span class="nc-nom">Autocollant</span> <small class="nc-technique">Vinyle adhésif (100 x 50 cm)</small>', $twig->ligneClient($ligne));
        self::assertSame('Vinyle adhésif (100 x 50 cm) <small class="nc-commercial text-muted">Autocollant</small>', $twig->ligneInterne($ligne));
        self::assertSame('Autocollant · Vinyle adhésif (100 x 50 cm)', $twig->texteLigne($ligne));
        self::assertSame('Vinyle adhésif (100 x 50 cm) (Autocollant)', $twig->texteLigneInterne($ligne));
        // La taille est déjà dans la désignation : seule la quantité suit.
        self::assertSame('<span class="nc-nom">Autocollant</span> <small class="nc-technique">Vinyle adhésif (100 x 50 cm)</small> × 2', $twig->titreLigneClient($ligne));
        // Ligne vide, aperçu sans base (tableau) ou rien du tout.
        self::assertSame('Prestation', $twig->ligneClient(new CommandesDetails()));
        self::assertSame('Bâche 400 × 150 cm', $twig->ligneClient(['designation' => 'Bâche 400 × 150 cm']));
        self::assertSame('—', $twig->ligneInterne(null, '—'));
        self::assertSame('<b>', $twig->texteLigne(['designation' => '<b>']), 'texte simple : Twig l\'échappe');
        self::assertSame('vinyle adhesif autocollant', $twig->cleRecherche('Vinyle adhésif', 'Autocollant', null));
    }
}
