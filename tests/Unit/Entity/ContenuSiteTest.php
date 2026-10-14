<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ArticleConseil;
use App\Entity\AvisClient;
use App\Entity\CategorieProduit;
use App\Entity\PageSite;
use App\Entity\Partenaire;
use App\Entity\Realisation;
use PHPUnit\Framework\TestCase;

/** Règles de publication du contenu du site vitrine : rien de privé sans accord. */
final class ContenuSiteTest extends TestCase
{
    public function testUnAvisNeSeMontreQuAvecLAccordDuClient(): void
    {
        $avis = (new AvisClient())->setNomAffiche('Awa D.')->setTexte('  Travail soigné.  ')->setPublie(true);
        self::assertSame('Travail soigné.', $avis->getTexte());
        self::assertFalse($avis->estVisible(), 'publié sans accord');
        self::assertNotSame([], $avis->reglesDePublication());
        // La synchronisation ne fait pas descendre le texte ni le nom.
        self::assertSame('', AvisClient::textePublic('Travail soigné.', $avis));

        $avis->setAccordClient(true);
        self::assertTrue($avis->estVisible());
        self::assertSame([], $avis->reglesDePublication());
        self::assertSame('Travail soigné.', AvisClient::textePublic('Travail soigné.', $avis));

        self::assertFalse((clone $avis)->setPublie(false)->estVisible());
        self::assertFalse((clone $avis)->setTexte(' ')->estVisible(), 'sans texte');
        self::assertSame(5, $avis->setNote(9)->getNote());
        self::assertSame(1, $avis->setNote(0)->getNote());
        self::assertSame(AvisClient::TEXTE_MAX, mb_strlen($avis->setTexte(str_repeat('é', 700))->getTexte()));
    }

    public function testUnPartenaireNeSeMontreQuAvecSonAccord(): void
    {
        $partenaire = (new Partenaire())->setNom('Imprimerie amie')->setVisible(true);
        self::assertFalse($partenaire->estVisible());
        self::assertNotSame([], $partenaire->reglesDePublication());
        $partenaire->setAccordObtenu(true);
        self::assertTrue($partenaire->estVisible());
        self::assertSame([], $partenaire->reglesDePublication());
        self::assertFalse($partenaire->setVisible(false)->estVisible());
    }

    public function testLeLienDUnPartenaireEstUneAdresseWeb(): void
    {
        $partenaire = new Partenaire();
        self::assertSame('https://exemple.ml', $partenaire->setLien(' https://exemple.ml ')->getLien());
        self::assertSame('http://exemple.ml/a', $partenaire->setLien('http://exemple.ml/a')->getLien());
        foreach (['javascript:alert(1)', 'exemple.ml', '//exemple.ml', 'https://a.ml/<x>', '', null, 'https://'.str_repeat('a', 260)] as $refuse) {
            self::assertNull($partenaire->setLien($refuse)->getLien(), (string) $refuse);
        }
    }

    public function testLeNomDuClientDUneRealisationResteAuLocalSansAccord(): void
    {
        $realisation = (new Realisation())->setTitre('Bâche de façade')->setNomClient('  Pharmacie du Fleuve ');
        self::assertSame('Pharmacie du Fleuve', $realisation->getNomClient());
        self::assertNull($realisation->getClientAffiche());
        self::assertNull(Realisation::nomClientPublic('Pharmacie du Fleuve', $realisation));
        self::assertSame([], $realisation->reglesDePublication(), 'publiable : le nom ne part pas, c\'est tout');

        $realisation->setAccordClient(true);
        self::assertSame('Pharmacie du Fleuve', $realisation->getClientAffiche());
        self::assertSame('Pharmacie du Fleuve', Realisation::nomClientPublic('Pharmacie du Fleuve', $realisation));

        self::assertNotSame([], (new Realisation())->reglesDePublication(), 'sans titre');
        self::assertNull($realisation->setNomClient('  ')->getNomClient());
    }

    public function testLaCategorieDUneRealisationPartParSonIdentifiantPublic(): void
    {
        $categorie = (new CategorieProduit())->setNom('Grand format');
        $realisation = (new Realisation())->setCategorie($categorie);
        self::assertSame($categorie->getPublicId()->toRfc4122(), $realisation->getCategorieRef());
        self::assertNull($realisation->setCategorie(null)->getCategorieRef());
    }

    public function testUnConseilProgrammeNApparaitPasAvantSaDate(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $article = (new ArticleConseil())->setTitre('Choisir sa bâche')->setSlug('choisir-sa-bache')->setCorps('Texte.')
            ->setPublie(true)->setPublieLe(new \DateTimeImmutable('2026-10-15 09:00'));
        self::assertFalse($article->estVisible($maintenant), 'programmé pour demain');
        self::assertTrue($article->estVisible($maintenant->modify('+1 day')));
        self::assertFalse((clone $article)->setPublie(false)->estVisible($maintenant->modify('+1 day')));
        self::assertSame([], $article->reglesDePublication());
        self::assertNotSame([], (clone $article)->setCorps(' ')->reglesDePublication());
        self::assertNotSame([], (clone $article)->setSlug('')->reglesDePublication());
    }

    public function testUnePageSansTexteNeSePubliePas(): void
    {
        $page = new PageSite('faq');
        self::assertNotSame([], $page->reglesDePublication());
        $page->setTitre('Questions fréquentes')->setCorps('### Délais ?');
        self::assertSame([], $page->reglesDePublication());
        self::assertNull(PageSite::texteOuNull('  ', 10));
        self::assertSame('abc', PageSite::texteOuNull(' abcdef ', 3));
        foreach (PageSite::PAGES as $slug => $definition) {
            self::assertMatchesRegularExpression('/^app_site_/', $definition['route'], $slug);
        }
    }
}
