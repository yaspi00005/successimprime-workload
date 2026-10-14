<?php

namespace App\Tests\Unit\Controller;

use App\Controller\SitePagesController;
use App\Entity\CategorieProduit;
use App\Entity\Realisation;
use App\Service\Site\MarkdownSite;
use PHPUnit\Framework\TestCase;

final class SitePagesControllerTest extends TestCase
{
    public function testCarteDUneRealisationSansLeClientNiLaDemo(): void
    {
        $categorie = (new CategorieProduit())->setNom('Grand format (démo)');
        $realisation = (new Realisation())->setTitre('Bâche de façade (démo)')->setNomClient('Pharmacie du Fleuve')->setCategorie($categorie);

        $carte = SitePagesController::carte($realisation, $categorie);
        self::assertNull($carte['client'], 'pas de nom sans l\'accord du client');
        self::assertSame(['Grand format', 'grand-format'], [$carte['categorie'], $carte['filtre']]);
        self::assertSame('grand', $carte['illustration']);

        $realisation->setAccordClient(true);
        self::assertSame('Pharmacie du Fleuve', SitePagesController::carte($realisation, $categorie)['client']);
    }

    public function testIllustrationDUnEvenementEtSansCategorie(): void
    {
        $carte = SitePagesController::carte((new Realisation())->setTitre('Banderole · festival'), null);
        self::assertSame('evenement', $carte['illustration']);
        self::assertNull($carte['categorie']);
        self::assertNull($carte['filtre']);
    }

    public function testDonneesFaq(): void
    {
        $contenu = MarkdownSite::sections("Intro.\n\n## Commandes\n### Quels délais ?\nEn général **48 h**, selon la quantité.\n### Sans réponse ?\n## Paiement\n### Orange Money ?\n- Oui, à la commande.");
        $donnees = SitePagesController::donneesFaq($contenu);

        self::assertSame('FAQPage', $donnees['@type']);
        self::assertSame(['Quels délais ?', 'Orange Money ?'], array_column($donnees['mainEntity'], 'name'), 'une question sans réponse est laissée de côté');
        self::assertSame('En général 48 h, selon la quantité.', $donnees['mainEntity'][0]['acceptedAnswer']['text']);
        self::assertSame('Oui, à la commande.', $donnees['mainEntity'][1]['acceptedAnswer']['text']);
        self::assertNull(SitePagesController::donneesFaq(MarkdownSite::sections('Rien que du texte.')));
    }
}
