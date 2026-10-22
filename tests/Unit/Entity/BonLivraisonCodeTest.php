<?php

namespace App\Tests\Unit\Entity;

use App\Entity\BonLivraison;
use PHPUnit\Framework\TestCase;

/** Code de réception (maquettes DB10, DB11) : tiré à la création, essais comptés, effacements à la remise. */
final class BonLivraisonCodeTest extends TestCase
{
    public function testUnCodeAQuatreChiffresEstTireALaCreation(): void
    {
        $bon = new BonLivraison();

        self::assertMatchesRegularExpression('/^\d{4}$/', (string) $bon->getCodeReception());
        self::assertSame($bon->getCodeReception(), $bon->donnerCode()->getCodeReception());
    }

    public function testLeBonCodeEstNoteEtLesEssaisSontLimites(): void
    {
        $bon = new BonLivraison();
        $code = (string) $bon->getCodeReception();
        $faux = str_pad((string) (((int) $code + 1) % 10000), 4, '0', \STR_PAD_LEFT);
        $le = new \DateTimeImmutable('2026-10-21 11:22');

        self::assertFalse($bon->verifierCode($faux, $le));
        self::assertSame(BonLivraison::CODE_ESSAIS_MAX - 1, $bon->essaisCodeRestants());
        self::assertTrue($bon->verifierCode(' '.substr($code, 0, 2).' '.substr($code, 2), $le));
        self::assertSame($le, $bon->getCodeVerifieLe());

        $autre = new BonLivraison();
        $bonCode = (string) $autre->getCodeReception();
        $mauvais = str_pad((string) (((int) $bonCode + 1) % 10000), 4, '0', \STR_PAD_LEFT);
        for ($i = 0; $i < BonLivraison::CODE_ESSAIS_MAX; ++$i) {
            $autre->verifierCode($mauvais, $le);
        }
        // Plus d'essai : même le bon code ne passe plus, le livreur fait signer.
        self::assertFalse($autre->verifierCode($bonCode, $le));
        self::assertSame(0, $autre->essaisCodeRestants());
    }

    public function testLaPositionEtLeTelephoneDuLivreurSEffacentALaRemise(): void
    {
        $bon = (new BonLivraison())->noterLivreurSuivi('Bakary', '+223 76 00 00 21')->noterPositionLivreur(12.6392, -8.0029, new \DateTimeImmutable());
        self::assertSame('+22376000021', $bon->getLivreurTelephone());
        self::assertSame(12.6392, $bon->getLivreurLatitude());

        $bon->oublierLivreurEnRoute();
        self::assertSame('Bakary', $bon->getLivreurNom());
        self::assertNull($bon->getLivreurTelephone());
        self::assertNull($bon->getLivreurLatitude());
    }

    public function testLaNoteGardeLesEtiquettesConnues(): void
    {
        $bon = (new BonLivraison())->noterLivraison(9, ['a_l_heure', 'inconnue', 'livreur_poli'], new \DateTimeImmutable());

        self::assertSame(5, $bon->getNoteLivraison());
        self::assertSame(['a_l_heure', 'livreur_poli'], $bon->getNoteTags());
    }
}
