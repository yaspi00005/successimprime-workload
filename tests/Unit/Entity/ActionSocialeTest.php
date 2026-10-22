<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ActionSociale;
use App\Entity\CompteClient;
use App\Entity\ImageSite;
use PHPUnit\Framework\TestCase;

/**
 * « Nos actions sociales » (docs/site-vitrine.md) : une action ne paraît
 * qu'avec un titre, un texte et au moins une photo ; la carte « Nous
 * soutenir » se ferme une fois pour toutes.
 */
final class ActionSocialeTest extends TestCase
{
    public function testUneActionNeParaitQuAvecTexteEtPhoto(): void
    {
        $action = (new ActionSociale())->setTitre('  Fournitures scolaires  ')->setPublie(true);
        self::assertSame('Fournitures scolaires', $action->getTitre());
        self::assertFalse($action->estVisible(), 'publiée sans texte ni photo');
        self::assertSame(['le texte', 'une photo'], $action->manques());
        self::assertSame(['Pour paraître sur le site, il manque le texte.', 'Pour paraître sur le site, il manque une photo.'], $action->reglesDePublication());

        $action->setTexte("Remise des cahiers.\r\n\r\nMerci aux enseignants.");
        self::assertSame("Remise des cahiers.\n\nMerci aux enseignants.", $action->getTexte());
        self::assertSame(['une photo'], $action->manques());
        self::assertFalse($action->estVisible());

        $photo = ImageSite::depuis(self::png(), 'image/png', 4, 4);
        $action->ajouterImage($photo)->ajouterImage($photo);
        self::assertCount(1, $action->getImages(), 'une photo n\'est ajoutée qu\'une fois');
        self::assertSame($photo, $action->getImagePrincipale());
        self::assertSame([], $action->manques());
        self::assertTrue($action->estVisible());

        self::assertFalse((clone $action)->setPublie(false)->estVisible(), 'brouillon');
        self::assertFalse((clone $action)->setTexte('   ')->estVisible(), 'texte vidé');
        self::assertNull((clone $action)->setTexte('   ')->getTexte());
        self::assertSame(['le titre'], (clone $action)->setTitre(' ')->manques());

        $action->retirerImage($photo);
        self::assertNull($action->getImagePrincipale());
        self::assertFalse($action->estVisible(), 'dernière photo retirée');
    }

    public function testChampsBornes(): void
    {
        $action = new ActionSociale();
        self::assertSame((new \DateTimeImmutable('today'))->format('Y-m-d'), $action->getDateAction()->format('Y-m-d'), 'datée du jour par défaut');
        self::assertSame(160, mb_strlen($action->setTitre(str_repeat('é', 200))->getTitre()));
        self::assertNull($action->setLieu('   ')->getLieu(), 'lieu facultatif');
        self::assertSame('Sébénikoro, Bamako', $action->setLieu(' Sébénikoro, Bamako ')->getLieu());
        self::assertSame(0, $action->setOrdre(-4)->getOrdre());
        self::assertSame(999, $action->setOrdre(5000)->getOrdre());
        self::assertFalse($action->isPublie(), 'brouillon à la création');
    }

    public function testLaCarteNousSoutenirSeFermeUneFois(): void
    {
        $compte = new CompteClient();
        self::assertNull($compte->getSoutienMasqueLe());
        $le = new \DateTimeImmutable('2026-10-18 09:00');
        $compte->masquerSoutien($le)->masquerSoutien(new \DateTimeImmutable('2026-10-19 10:00'));
        self::assertSame($le, $compte->getSoutienMasqueLe(), 'la première fermeture reste');
    }

    private static function png(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
