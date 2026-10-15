<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\ReferencementSite;
use PHPUnit\Framework\TestCase;

final class ReferencementSiteTest extends TestCase
{
    public function testCodeGoogleSeulOuDansLaBalise(): void
    {
        $code = 'Ab3dEf_Gh1jKlMnOpQrStUvWxYz0123456789-abcd';

        self::assertSame($code, ReferencementSite::codeVerification($code, 'google'));
        self::assertSame($code, ReferencementSite::codeVerification('<meta name="google-site-verification" content="'.$code.'" />', 'google'));
        self::assertNull(ReferencementSite::codeVerification('court', 'google'));
        // D'une balise piégée, seul le code est gardé.
        self::assertSame($code, ReferencementSite::codeVerification('<meta content="'.$code.'"><script>alert(1)</script>', 'google'));
        self::assertNull(ReferencementSite::codeVerification($code.'"><script>alert(1)</script>', 'google'));
    }

    public function testCodeBing(): void
    {
        self::assertSame('0123456789ABCDEF0123456789ABCDEF', ReferencementSite::codeVerification('<meta name="msvalidate.01" content="0123456789abcdef0123456789abcdef" />', 'bing'));
        self::assertNull(ReferencementSite::codeVerification('0123456789abcdef', 'bing'));
        self::assertNull(ReferencementSite::codeVerification('0123456789ABCDEF0123456789ABCDEF', 'yahoo'));
    }

    public function testLiens(): void
    {
        $facebook = ReferencementSite::RESEAUX['facebook']['domaines'];

        self::assertSame('https://www.facebook.com/succesimprim', ReferencementSite::lien('https://www.facebook.com/succesimprim', $facebook));
        self::assertSame('https://facebook.com/succesimprim', ReferencementSite::lien('facebook.com/succesimprim', $facebook));
        self::assertSame('https://maps.app.goo.gl/AbCdEf', ReferencementSite::lien('https://maps.app.goo.gl/AbCdEf', ReferencementSite::DOMAINES_FICHE_GOOGLE));
        // Pas de http, pas d'autre domaine (même s'il le contient), pas de javascript:, pas de guillemets.
        self::assertNull(ReferencementSite::lien('http://www.facebook.com/succesimprim', $facebook));
        self::assertNull(ReferencementSite::lien('https://facebook.com.example.org/x', $facebook));
        self::assertNull(ReferencementSite::lien('https://evilfacebook.com/x', $facebook));
        self::assertNull(ReferencementSite::lien('javascript:alert(1)//facebook.com/', $facebook));
        self::assertNull(ReferencementSite::lien('https://www.facebook.com/a"onmouseover="x', $facebook));
        self::assertNull(ReferencementSite::lien('https://www.facebook.com/'.str_repeat('a', 500), $facebook));
    }

    public function testLireGardeLesBonnesValeursEtSignaleLesAutres(): void
    {
        $lu = ReferencementSite::lire([
            'google' => ' <meta name="google-site-verification" content="Ab3dEf_Gh1jKlMnOpQrStUvWxYz0123456789-abcd" /> ',
            'bing' => 'pas un code',
            'fiche_google' => 'https://g.page/succes-imprim',
            'instagram' => 'https://evil.example.com/succesimprim',
            'tiktok' => ['tableau'],
            'inconnu' => 'x',
        ]);

        self::assertSame('Ab3dEf_Gh1jKlMnOpQrStUvWxYz0123456789-abcd', $lu['valeurs']['google']);
        self::assertSame('', $lu['valeurs']['bing']);
        self::assertSame('https://g.page/succes-imprim', $lu['valeurs']['fiche_google']);
        self::assertSame('', $lu['valeurs']['instagram']);
        self::assertSame('', $lu['valeurs']['tiktok']);
        self::assertSame(array_keys(ReferencementSite::DEFAUTS), array_keys($lu['valeurs']));
        self::assertSame(['bing', 'instagram'], array_keys($lu['erreurs']));
    }

    public function testManquesDansLOrdre(): void
    {
        $manques = ReferencementSite::manques(['site' => true, 'adresse' => true, 'telephone' => true, 'plan' => true, 'reseaux' => false]);

        self::assertSame([
            ReferencementSite::MANQUES['email'],
            ReferencementSite::MANQUES['position'],
            ReferencementSite::MANQUES['fiche_google'],
            ReferencementSite::MANQUES['reseaux'],
            ReferencementSite::MANQUES['google'],
        ], $manques);
        self::assertSame([], ReferencementSite::manques(array_fill_keys(array_keys(ReferencementSite::MANQUES), true)));
    }
}
