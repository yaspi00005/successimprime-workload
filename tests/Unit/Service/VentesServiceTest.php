<?php

namespace App\Tests\Unit\Service;

use App\Entity\Devis;
use App\Service\VentesService;
use PHPUnit\Framework\TestCase;

final class VentesServiceTest extends TestCase
{
    public function testStatutDevisEnvoyePuisARelancer(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-10 15:00');

        self::assertSame(
            ['libelle' => 'Envoyé · J+2', 'ton' => 'orange'],
            VentesService::statutDevis(Devis::STATUT_ENVOYE, new \DateTimeImmutable('2026-10-08 09:00'), $maintenant)
        );
        self::assertSame(
            ['libelle' => 'À relancer', 'ton' => 'rouge'],
            VentesService::statutDevis(Devis::STATUT_ENVOYE, new \DateTimeImmutable('2026-10-07'), $maintenant)
        );
        self::assertSame('Accepté', VentesService::statutDevis(Devis::STATUT_ACCEPTE, null, $maintenant)['libelle']);
    }

    public function testStatutCommandeSuitLaLigneLaMoinsAvancee(): void
    {
        self::assertSame('BAT envoyé', VentesService::statutCommande(['prete', 'bat', 'impression'])['libelle']);
        self::assertSame('Prête', VentesService::statutCommande(['prete', 'livree'])['libelle']);
        self::assertSame('En livraison', VentesService::statutCommande(['en_livraison', 'livree'])['libelle']);
        self::assertSame('Livrée', VentesService::statutCommande(['livree', 'annulee'])['libelle']);
        self::assertSame('Annulée', VentesService::statutCommande(['annulee'])['libelle']);
        self::assertSame('Vide', VentesService::statutCommande([])['libelle']);
    }

    public function testObjet(): void
    {
        self::assertSame('Roll-up × 5', VentesService::objet([['Roll-up', 5]]));
        self::assertSame('Banderole + 2', VentesService::objet([['Banderole', 1], ['Flyers', 500], [null, 1]]));
        self::assertSame('—', VentesService::objet([]));
    }

    public function testTaux(): void
    {
        self::assertSame(62, VentesService::taux(31, 50));
        self::assertNull(VentesService::taux(0, 0));
    }
}
