<?php

namespace App\Tests\Unit\Service\Documentation;

use App\Service\Documentation\SecretsGuide;
use PHPUnit\Framework\TestCase;

final class SecretsGuideTest extends TestCase
{
    public function testMotifsDeSecrets(): void
    {
        self::assertNotSame([], SecretsGuide::chercher('{"dsn":"mysql://u:motdepasse@h/db"}'));
        self::assertNotSame([], SecretsGuide::chercher("-----BEGIN PRIVATE KEY-----\nabc"));
        self::assertNotSame([], SecretsGuide::chercher(str_repeat('a1', 32)));
        self::assertSame([], SecretsGuide::chercher('{"route":"app_devis_new","chemin":"/devis/new"}'));
    }

    public function testValeurDeLEnvironnementSansLaMontrer(): void
    {
        $valeur = 'zq9x-essai-pas-une-vraie-cle';
        $trouve = SecretsGuide::chercher('debut '.$valeur.' fin', ['SYNC_CLE' => $valeur]);
        self::assertCount(1, $trouve);
        self::assertStringNotContainsString($valeur, $trouve[0]);
        self::assertSame([], SecretsGuide::chercher('rien ici', ['SYNC_CLE' => $valeur]));
    }

    public function testLeMessageNeMontrePasLeSecret(): void
    {
        $trouve = SecretsGuide::chercher("ligne 1\nmysql://u:motdepasse@h/db");
        self::assertStringNotContainsString('motdepasse', $trouve[0]);
        self::assertStringContainsString('ligne 2', $trouve[0]);
    }
}
