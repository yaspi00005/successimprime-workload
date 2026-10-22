<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Service\Synchro\Serialiseur;
use Doctrine\ORM\EntityNotFoundException;
use PHPUnit\Framework\TestCase;

final class SerialiseurLienManquantTest extends TestCase
{
    public function testUneDonneeLieeSupprimeePartVide(): void
    {
        // Proxy Doctrine d'une facture effacée : le lire lève EntityNotFoundException.
        $facturePerdue = new class {
            public function getPublicId(): never
            {
                throw EntityNotFoundException::fromClassNameAndIdentifier('App\Entity\Factures', ['id' => '7']);
            }
        };

        $idPublic = new \ReflectionMethod(Serialiseur::class, 'idPublic');

        self::assertNull($idPublic->invoke(null, $facturePerdue));
    }
}
