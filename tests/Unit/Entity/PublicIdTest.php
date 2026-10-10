<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\Produits;
use App\Entity\Reclamation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class PublicIdTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function entitesSynchronisees(): iterable
    {
        foreach ([Clients::class, Commandes::class, Devis::class, Factures::class, Paiements::class, Produits::class, Reclamation::class] as $classe) {
            yield (new \ReflectionClass($classe))->getShortName() => [$classe];
        }
    }

    /**
     * @param class-string $classe
     */
    #[DataProvider('entitesSynchronisees')]
    public function testChaqueNouvelEnregistrementRecoitUnUuidV7Unique(string $classe): void
    {
        $premier = new $classe();
        $second = new $classe();

        self::assertInstanceOf(UuidV7::class, $premier->getPublicId());
        self::assertFalse($premier->getPublicId()->equals($second->getPublicId()));
    }
}
