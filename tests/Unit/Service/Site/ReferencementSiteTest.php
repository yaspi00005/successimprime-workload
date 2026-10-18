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

    public function testFicheGoogleDeLaBoutiqueParDefaut(): void
    {
        self::assertSame('https://maps.app.goo.gl/S53cwaekd2L3FHP86', ReferencementSite::lire([])['valeurs']['fiche_google']);
        self::assertSame(ReferencementSite::FICHE_GOOGLE, ReferencementSite::lien(ReferencementSite::FICHE_GOOGLE, ReferencementSite::DOMAINES_FICHE_GOOGLE));
        // Un lien saisi la remplace ; un lien refusé laisse celle de la boutique.
        self::assertSame('https://g.page/autre-fiche', ReferencementSite::lire(['fiche_google' => 'https://g.page/autre-fiche'])['valeurs']['fiche_google']);
        $refuse = ReferencementSite::lire(['fiche_google' => 'https://evil.example.org/x']);
        self::assertSame(ReferencementSite::FICHE_GOOGLE, $refuse['valeurs']['fiche_google']);
        self::assertSame(['fiche_google'], array_keys($refuse['erreurs']));
    }

    public function testIdentifiantDeMesureSeulOuDansLaBaliseGoogle(): void
    {
        $balise = '<!-- Google tag (gtag.js) --> <script async src="https://www.googletagmanager.com/gtag/js?id=G-634VEQPP69"></script> <script> window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag(\'js\', new Date()); gtag(\'config\', \'G-634VEQPP69\'); </script>';

        self::assertSame('G-634VEQPP69', ReferencementSite::identifiantMesure($balise));
        self::assertSame('G-634VEQPP69', ReferencementSite::identifiantMesure(' g-634veqpp69 '));
        self::assertSame('G-ABCD1234', ReferencementSite::identifiantMesure("gtag('config', 'G-ABCD1234');"));
        // Ni ancien identifiant Universal Analytics, ni conteneur Tag Manager, ni balise piégée.
        foreach (['UA-12345-1', 'GTM-ABCD12', 'G-', 'G-634VEQPP69"><script>alert(1)</script>', 'G-ABC', ''] as $refuse) {
            self::assertNull(ReferencementSite::identifiantMesure($refuse), $refuse);
        }
    }

    public function testMesureDeLaBoutiqueParDefautEtCoupeeQuandOnVideLeChamp(): void
    {
        // Jamais réglée (ou réglage enregistré avant la mesure) : l'identifiant de la boutique.
        self::assertSame('G-634VEQPP69', ReferencementSite::lire([])['valeurs']['mesure']);
        self::assertSame(ReferencementSite::MESURE_GOOGLE, ReferencementSite::lire(['google' => ''])['valeurs']['mesure']);
        // Champ vidé : coupée.
        self::assertSame('', ReferencementSite::lire(['mesure' => '  '])['valeurs']['mesure']);
        // Un autre identifiant le remplace ; un identifiant refusé est signalé.
        self::assertSame('G-NOUVEAU123', ReferencementSite::lire(['mesure' => 'G-NOUVEAU123'])['valeurs']['mesure']);
        $refuse = ReferencementSite::lire(['mesure' => 'UA-12345-1']);
        self::assertSame(['mesure'], array_keys($refuse['erreurs']));
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

    public function testWhatsAppNumeroOuLien(): void
    {
        self::assertSame('https://wa.me/22370000000', ReferencementSite::lienWhatsApp('70 00 00 00'));
        self::assertSame('https://wa.me/22370000000', ReferencementSite::lienWhatsApp('+223 70 00 00 00'));
        self::assertSame('https://wa.me/22370000000', ReferencementSite::lienWhatsApp('00223 70-00-00-00'));
        self::assertSame('https://wa.me/22370000000', ReferencementSite::lienWhatsApp('https://wa.me/22370000000'));
        self::assertSame('https://whatsapp.com/channel/0029VaExemple', ReferencementSite::lienWhatsApp('https://whatsapp.com/channel/0029VaExemple'));
        self::assertSame('https://chat.whatsapp.com/AbCdEf', ReferencementSite::lienWhatsApp('chat.whatsapp.com/AbCdEf'));
        foreach (['1234', 'https://evil.example.org/wa.me', 'http://wa.me/22370000000', 'javascript:alert(1)'] as $refuse) {
            self::assertNull(ReferencementSite::lienWhatsApp($refuse), $refuse);
        }
        $lu = ReferencementSite::lire(['whatsapp' => '70 00 00 00', 'x' => 'https://x.com/exemple', 'youtube' => 'https://x.com/exemple']);
        self::assertSame('https://wa.me/22370000000', $lu['valeurs']['whatsapp']);
        self::assertSame('https://x.com/exemple', $lu['valeurs']['x']);
        self::assertSame(['youtube'], array_keys($lu['erreurs']));
    }

    public function testWhatsAppHorsDuSameAs(): void
    {
        foreach (ReferencementSite::RESEAUX as $cle => $reseau) {
            self::assertSame('whatsapp' !== $cle, $reseau['profil'], $cle);
            self::assertArrayHasKey($cle, ReferencementSite::DEFAUTS);
        }
    }
}
