<?php

namespace App\Tests\Unit\Service\Push;

use App\Service\Push\WebPush;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class WebPushTest extends TestCase
{
    /** Exemple de la RFC 8291, section 5. */
    public function testChiffrementConformeALaRfc(): void
    {
        $d = WebPush::b64d(...);
        $corps = WebPush::chiffrer(
            'When I grow up, I want to be a watermelon',
            $d('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            $d('BTBZMqHH6r4Tts7J_aSIgg'),
            [$d('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), $d('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')],
            $d('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        self::assertSame('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', WebPush::b64e($corps));
    }

    public function testLeJetonVapidSeVerifieAvecLaClePublique(): void
    {
        $cles = WebPush::nouvellesCles();
        $push = new WebPush(new MockHttpClient(), $cles['publique'], $cles['prive'], 'mailto:test@example.com');
        [$entete, $charge, $signature] = explode('.', $push->jeton('https://fcm.googleapis.com', 2000000000));

        self::assertSame(['aud' => 'https://fcm.googleapis.com', 'exp' => 2000000000, 'sub' => 'mailto:test@example.com'], json_decode(WebPush::b64d($charge), true));
        $brute = WebPush::b64d($signature);
        self::assertSame(64, \strlen($brute));
        $entier = static fn (string $x): string => "\x02".\chr(\strlen($x = ltrim($x, "\0") ?: "\0") + (\ord($x[0]) > 127 ? 1 : 0)).(\ord($x[0]) > 127 ? "\0" : '').$x;
        $der = $entier(substr($brute, 0, 32)).$entier(substr($brute, 32));
        $der = "\x30".\chr(\strlen($der)).$der;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::b64d($cles['publique'])), 64, "\n")."-----END PUBLIC KEY-----\n";
        self::assertSame(1, openssl_verify($entete.'.'.$charge, $der, $pem, \OPENSSL_ALGO_SHA256));
    }

    public function testSansClesRienNEstConfigure(): void
    {
        self::assertFalse((new WebPush(new MockHttpClient(), '', '', 'mailto:x@y.z'))->estConfigure());
    }
}
