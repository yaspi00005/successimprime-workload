<?php

namespace App\Tests\Unit\Twig;

use App\Service\Site\MarkdownSite;
use App\Twig\SiteContenuExtension;
use PHPUnit\Framework\TestCase;

final class SiteContenuExtensionTest extends TestCase
{
    public function testDonneesStructureesSansFermerLaBalise(): void
    {
        $html = SiteContenuExtension::jsonLd(['name' => 'Bâche </script><script>alert(1)</script> & "co"']);
        self::assertStringStartsWith('<script type="application/ld+json">', $html);
        self::assertSame(1, substr_count($html, '</script>'), 'seule la vraie fin de balise');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('Bâche', $html, 'accents gardés');
        $json = substr($html, \strlen('<script type="application/ld+json">'), -\strlen('</script>'));
        self::assertSame('Bâche </script><script>alert(1)</script> & "co"', json_decode($json, true)['name']);
    }

    public function testTexteEtLienDUnMorceauDeHtml(): void
    {
        $element = MarkdownSite::enLigne('Gabarit roll-up 85 × 200 — [PDF](https://exemple.ml/roll-up.pdf?a=1&b=2)');
        self::assertSame('Gabarit roll-up 85 × 200 — PDF', SiteContenuExtension::sansBalises($element));
        self::assertSame('https://exemple.ml/roll-up.pdf?a=1&b=2', SiteContenuExtension::lienDe($element));
        self::assertNull(SiteContenuExtension::lienDe('Sans lien'));
        self::assertNull(SiteContenuExtension::lienDe('<a href="javascript:alert(1)">x</a>'), 'adresse revérifiée');
        self::assertSame('', SiteContenuExtension::sansBalises(null));
    }

    public function testDateEnFrancais(): void
    {
        self::assertSame('14 octobre 2026', SiteContenuExtension::date(new \DateTimeImmutable('2026-10-14')));
        self::assertSame('1er août 2026', SiteContenuExtension::date(new \DateTimeImmutable('2026-08-01')));
        self::assertSame('décembre 2025', SiteContenuExtension::date(new \DateTimeImmutable('2025-12-31'), false));
        self::assertSame('', SiteContenuExtension::date(null));
    }

    public function testMentionDemoRetiree(): void
    {
        self::assertSame('Grand format', SiteContenuExtension::sansDemo('Grand format (démo)'));
        self::assertSame('', SiteContenuExtension::sansDemo(null));
    }
}
