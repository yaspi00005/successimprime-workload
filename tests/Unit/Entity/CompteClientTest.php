<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CompteClient;
use PHPUnit\Framework\TestCase;

final class CompteClientTest extends TestCase
{
    public function testLesFormesDuMemeNumeroDonnentLeMemeCompte(): void
    {
        foreach (['76 12 34 56', '76123456', '+223 76 12 34 56', '0022376123456', '223-76-12-34-56'] as $saisi) {
            self::assertSame('22376123456', CompteClient::normaliserTelephone($saisi), $saisi);
        }
    }

    public function testNumeroInvalide(): void
    {
        self::assertNull(CompteClient::normaliserTelephone('7612345'));
        self::assertNull(CompteClient::normaliserTelephone('+33 6 12 34 56 78'));
        self::assertNull(CompteClient::normaliserTelephone(''));
    }

    public function testCompteClientNAAccesQuALEspace(): void
    {
        self::assertSame([CompteClient::ROLE], (new CompteClient())->getRoles());
    }
}
