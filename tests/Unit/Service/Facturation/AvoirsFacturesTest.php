<?php

namespace App\Tests\Unit\Service\Facturation;

use App\Service\Facturation\AvoirsFactures;
use PHPUnit\Framework\TestCase;

final class AvoirsFacturesTest extends TestCase
{
    public function testLaLigneEntiereRedonneSonTotalExact(): void
    {
        self::assertSame(90337, AvoirsFactures::montant(5, 5, 90337));
        self::assertSame(36135, AvoirsFactures::montant(2, 5, 90337));
        // 2 puis 3 : la somme des deux avoirs retombe sur le total de la ligne.
        self::assertSame(90337, AvoirsFactures::montant(2, 5, 90337) + (AvoirsFactures::montant(5, 5, 90337) - AvoirsFactures::montant(2, 5, 90337)));
        self::assertSame(0, AvoirsFactures::montant(0, 5, 90337));
    }

    public function testLaRemiseDeLaFactureSuitAuProrata(): void
    {
        // Facture de 200 000 F HT avec 20 000 F de remise : un avoir de 50 000 F en reprend 5 000.
        self::assertSame(5000, AvoirsFactures::partRemise(50000, 20000, 200000));
        self::assertSame(0, AvoirsFactures::partRemise(50000, 0, 200000));
    }
}
