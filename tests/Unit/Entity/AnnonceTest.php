<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Annonce;
use PHPUnit\Framework\TestCase;

final class AnnonceTest extends TestCase
{
    public function testVisibleSeulementActiveEtDansSaPeriode(): void
    {
        $jour = new \DateTimeImmutable('2026-10-15 18:30');
        $annonce = (new Annonce())->setTexte('Fermé le 20 octobre (Maouloud).');

        self::assertFalse($annonce->estVisible($jour), 'pas active : reste à l\'atelier');
        self::assertSame('brouillon', $annonce->etat($jour));

        $annonce->setPublie(true);
        self::assertTrue($annonce->estVisible($jour), 'sans dates : tout de suite et sans fin');
        self::assertSame('active', $annonce->etat($jour));

        // Premier et dernier jour compris, quelle que soit l'heure.
        $annonce->setDebut(new \DateTimeImmutable('2026-10-15 23:00'))->setFin(new \DateTimeImmutable('2026-10-15 01:00'));
        self::assertTrue($annonce->estVisible(new \DateTimeImmutable('2026-10-15 00:01')));
        self::assertTrue($annonce->estVisible(new \DateTimeImmutable('2026-10-15 23:59')));
        self::assertFalse($annonce->estVisible(new \DateTimeImmutable('2026-10-14 23:59')));
        self::assertSame('programmee', $annonce->etat(new \DateTimeImmutable('2026-10-14')));
        self::assertFalse($annonce->estVisible(new \DateTimeImmutable('2026-10-16 00:00')));
        self::assertSame('terminee', $annonce->etat(new \DateTimeImmutable('2026-10-16')));

        $vide = (new Annonce())->setPublie(true);
        self::assertFalse($vide->estVisible($jour), 'sans texte : jamais montrée');
    }

    public function testTexteSurUneLigneEtLimite(): void
    {
        $annonce = (new Annonce())->setTexte("  Promo du mois :\n -20 %   sur les bâches  ");
        self::assertSame('Promo du mois : -20 % sur les bâches', $annonce->getTexte());

        $annonce->setTexte(str_repeat('é', 250));
        self::assertSame(Annonce::TEXTE_MAX, mb_strlen($annonce->getTexte()));
        self::assertSame(60, mb_strlen($annonce->getTitre()), 'le titre de la liste est le début du texte');
    }

    public function testSeulsLesLiensSursSontGardes(): void
    {
        $annonce = new Annonce();
        self::assertSame('/nos-produits', $annonce->setLien(' /nos-produits ')->getLien());
        self::assertFalse($annonce->lienExterne());
        self::assertSame('https://exemple.ml/promo', $annonce->setLien('https://exemple.ml/promo')->getLien());
        self::assertTrue($annonce->lienExterne());

        foreach (['javascript:alert(1)', '//evil.example', 'data:text/html,x', 'https://a.b/"onmouseover=x', ''] as $lien) {
            self::assertNull($annonce->setLien($lien)->getLien(), $lien);
        }
        self::assertNull($annonce->setLien(null)->getLien());

        self::assertSame('En savoir plus', $annonce->setLibelleLien('   ')->getLibelleLienAffiche());
        self::assertSame('Voir les bâches', $annonce->setLibelleLien(' Voir les bâches ')->getLibelleLienAffiche());
    }

    public function testStyleCibleEtOrdreBornes(): void
    {
        $annonce = (new Annonce())->setStyle('clignotant')->setCible('partout')->setOrdre(5000);
        self::assertSame(Annonce::INFORMATION, $annonce->getStyle());
        self::assertSame(Annonce::LES_DEUX, $annonce->getCible());
        self::assertSame(999, $annonce->getOrdre());
        self::assertSame(0, $annonce->setOrdre(-3)->getOrdre());

        self::assertTrue($annonce->pourCible(Annonce::SITE));
        self::assertTrue($annonce->pourCible(Annonce::ESPACE));
        $annonce->setCible(Annonce::ESPACE);
        self::assertFalse($annonce->pourCible(Annonce::SITE));
        self::assertTrue($annonce->pourCible(Annonce::ESPACE));
        self::assertSame('Alerte', $annonce->setStyle(Annonce::ALERTE)->getStyleLibelle());
    }

    public function testReglesDePublication(): void
    {
        self::assertSame(['Une annonce a besoin d\'un texte.'], (new Annonce())->reglesDePublication());
        $annonce = (new Annonce())->setTexte('Retard de livraison cette semaine.')
            ->setDebut(new \DateTimeImmutable('2026-10-20'))->setFin(new \DateTimeImmutable('2026-10-18'));
        self::assertCount(1, $annonce->reglesDePublication());
        self::assertSame([], $annonce->setFin(new \DateTimeImmutable('2026-10-20'))->reglesDePublication(), 'un seul jour');
    }
}
