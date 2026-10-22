<?php

namespace App\Tests\Unit\Security;

use App\Security\RetourInterne;
use PHPUnit\Framework\TestCase;

/** Un formulaire ne peut renvoyer que vers une page du site. */
final class RetourInterneTest extends TestCase
{
    public function testPagesDuSiteAcceptees(): void
    {
        foreach (['/fidelite?onglet=dormeurs', '/clients/0f3c2a1e-9b7d-4c1e-8f00-2b4f5d6e7a8b#fidelite', '/', '/%2F%2Fexemple', '/fidelite?onglet=dormeurs&tri[nom]=asc'] as $retour) {
            self::assertSame($retour, RetourInterne::chemin($retour), $retour);
        }
    }

    public function testAutresSitesRefuses(): void
    {
        foreach (['', 'https://exemple.com', '//exemple.com', '/\\exemple.com', "/\t/exemple.com", "/\n/exemple.com", 'javascript:alert(1)', 'fidelite', ' /fidelite'] as $retour) {
            self::assertNull(RetourInterne::chemin($retour), json_encode($retour));
        }
    }
}
