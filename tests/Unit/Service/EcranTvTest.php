<?php

namespace App\Tests\Unit\Service;

use App\Service\EcranTv;
use PHPUnit\Framework\TestCase;

final class EcranTvTest extends TestCase
{
    public function testNumeroCourtNeGardeQueLesChiffresDeFin(): void
    {
        self::assertSame('0151', EcranTv::numeroCourt('CMD-2026-0151', 9));
        self::assertSame('9', EcranTv::numeroCourt(null, 9));
        self::assertSame('9', EcranTv::numeroCourt('SANS-NUMERO', 9));
    }

    public function testLegendeDepuisNom(): void
    {
        self::assertSame('Habillage boutique', EcranTv::legendeDepuisNom('02_habillage-boutique.jpg'));
        self::assertSame('Video', EcranTv::legendeDepuisNom('10 - video.mp4'));
        self::assertSame('', EcranTv::legendeDepuisNom('03_.png'));
    }

    public function testTypeMedia(): void
    {
        self::assertSame('image', EcranTv::typeMedia('a.JPG'));
        self::assertSame('video', EcranTv::typeMedia('a.webm'));
        self::assertNull(EcranTv::typeMedia('a.php'));
        self::assertNull(EcranTv::typeMedia('.gitkeep'));
        self::assertNull(EcranTv::typeMedia('.renommage-0-a.jpg'));
    }

    public function testMorceauxMessageMetEnAvantLesCrochets(): void
    {
        self::assertSame(
            [['texte' => 'Payez par ', 'fort' => false], ['texte' => 'Orange Money', 'fort' => true]],
            EcranTv::morceauxMessage('Payez par [Orange Money]'),
        );
        self::assertSame([['texte' => 'Sans crochets', 'fort' => false]], EcranTv::morceauxMessage('Sans crochets'));
    }
}
