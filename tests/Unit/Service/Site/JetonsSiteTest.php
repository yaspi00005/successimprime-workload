<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\JetonsSite;
use PHPUnit\Framework\TestCase;

final class JetonsSiteTest extends TestCase
{
    public function testEncodageEtDecodage(): void
    {
        $jetons = new JetonsSite('secret-de-test');
        foreach ([0, 1, 2, 12, 999, 123456, 0xFFFFFFFF] as $id) {
            $jeton = $jetons->encoder(JetonsSite::CONFIGURATION, $id);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{11}$/', $jeton);
            self::assertStringNotContainsString((string) $id, $id > 9 ? $jeton : 'x');
            self::assertSame($id, $jetons->decoder(JetonsSite::CONFIGURATION, $jeton));
        }
    }

    public function testJetonStableEtPropreAuSecret(): void
    {
        $a = new JetonsSite('secret-de-test');
        self::assertSame($a->encoder(JetonsSite::CONFIGURATION, 12), (new JetonsSite('secret-de-test'))->encoder(JetonsSite::CONFIGURATION, 12));
        // Deux identifiants qui se suivent n'ont rien en commun.
        self::assertNotSame(substr($a->encoder(JetonsSite::CONFIGURATION, 12), 0, 4), substr($a->encoder(JetonsSite::CONFIGURATION, 13), 0, 4));
        // Un autre APP_SECRET : d'autres jetons, et ceux d'avant sont refusés.
        $b = new JetonsSite('autre-secret');
        self::assertNotSame($a->encoder(JetonsSite::CONFIGURATION, 12), $b->encoder(JetonsSite::CONFIGURATION, 12));
        self::assertNull($b->decoder(JetonsSite::CONFIGURATION, $a->encoder(JetonsSite::CONFIGURATION, 12)));
    }

    public function testJetonForgeRefuse(): void
    {
        $jetons = new JetonsSite('secret-de-test');
        $jeton = $jetons->encoder(JetonsSite::CONFIGURATION, 12);

        // Chaque caractère changé : refusé.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        for ($i = 0; $i < JetonsSite::LONGUEUR; ++$i) {
            $autre = $jeton;
            $autre[$i] = $alphabet[(strpos($alphabet, $jeton[$i]) + 1) % 64];
            self::assertNull($jetons->decoder(JetonsSite::CONFIGURATION, $autre), 'caractère '.$i);
        }
        // Le jeton d'un autre type n'ouvre pas une configuration.
        self::assertNull($jetons->decoder(JetonsSite::CONFIGURATION, $jetons->encoder(JetonsSite::FINITION, 12)));
        foreach (['12', '', 'AAAAAAAAAAA', 'AAAAAAAAAAAA', $jeton.'=', ' '.$jeton, 'abc/def+ghi', null, 12, [$jeton]] as $faux) {
            self::assertNull($jetons->decoder(JetonsSite::CONFIGURATION, $faux), var_export($faux, true));
        }
    }

    public function testIdentifiantHorsBornes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JetonsSite('secret-de-test'))->encoder(JetonsSite::CONFIGURATION, -1);
    }

    public function testParametresDAdresse(): void
    {
        $jetons = new JetonsSite('secret-de-test');
        $c12 = $jetons->encoder(JetonsSite::CONFIGURATION, 12);
        $f3 = $jetons->encoder(JetonsSite::FINITION, 3);
        $f5 = $jetons->encoder(JetonsSite::FINITION, 5);

        $encodes = $jetons->encoderParametres(['produit' => 12, 'precedent' => '12', 'finitions' => [3, '5'], 'quantite' => 10, 'largeur' => '300']);
        self::assertSame(['produit' => $c12, 'precedent' => $c12, 'finitions' => [$f3, $f5], 'quantite' => 10, 'largeur' => '300'], $encodes);
        // Ancienne écriture des finitions (« 3,5 »), et jetons déjà là : inchangés.
        self::assertSame([$f3, $f5], $jetons->encoderParametres(['finitions' => '3,5'])['finitions']);
        self::assertSame(['produit' => $c12], $jetons->encoderParametres(['produit' => $c12]));

        self::assertSame(['produit' => 12, 'precedent' => 12, 'finitions' => [3, 5], 'quantite' => 10, 'largeur' => '300'], $jetons->decoderParametres($encodes));
        // Identifiants en chiffres et jetons faux : écartés (sauf formulaire envoyé).
        self::assertSame(['finitions' => [3], 'quantite' => '1'], $jetons->decoderParametres(['produit' => '12', 'finitions' => ['5', $f3, 'faux'], 'quantite' => '1']));
        self::assertSame(['produit' => 12, 'finitions' => [5, 3]], $jetons->decoderParametres(['produit' => '12', 'finitions' => ['5', $f3]], JetonsSite::PARAMETRES_PRODUIT, true));

        self::assertTrue(JetonsSite::contientIdentifiants(['produit' => '12']));
        self::assertTrue(JetonsSite::contientIdentifiants(['finitions' => [$f3, '7']]));
        self::assertTrue(JetonsSite::contientIdentifiants(['finitions' => '3,5']));
        self::assertFalse(JetonsSite::contientIdentifiants(['produit' => $c12, 'finitions' => [$f3], 'quantite' => '10']));
    }
}
