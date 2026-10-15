<?php

namespace App\Tests\Unit\Service;

use App\Entity\Commandes;
use App\Service\SuiviCommandeService;
use PHPUnit\Framework\TestCase;

final class SuiviCommandeServiceTest extends TestCase
{
    private const ETAPES = [
        ['libelle' => 'Confirmée', 'detail' => null],
        ['libelle' => 'Acompte payé', 'detail' => null],
        ['libelle' => 'PAO', 'detail' => null],
        ['libelle' => 'BAT validés', 'detail' => null],
        ['libelle' => 'Impression', 'detail' => null],
        ['libelle' => 'Finition', 'detail' => null],
        ['libelle' => 'Livrée', 'detail' => null],
    ];

    public function testLaPremiereEtapeNonFaiteEstEnCours(): void
    {
        $etats = array_column(
            SuiviCommandeService::marquerEtapes(self::ETAPES, [true, true, true, false, false, false, false]),
            'etat'
        );

        self::assertSame(['faite', 'faite', 'faite', 'en_cours', 'a_venir', 'a_venir', 'a_venir'], $etats);
    }

    public function testUnAcompteManquantNeBloquePasLaProduction(): void
    {
        $etats = array_column(
            SuiviCommandeService::marquerEtapes(self::ETAPES, [true, false, true, true, false, false, false]),
            'etat'
        );

        self::assertSame(['faite', 'a_venir', 'faite', 'faite', 'en_cours', 'a_venir', 'a_venir'], $etats);
    }

    public function testCommandeLivreeEstEntierementFaite(): void
    {
        $etats = array_column(
            SuiviCommandeService::marquerEtapes(self::ETAPES, array_fill(0, 7, true)),
            'etat'
        );

        self::assertSame(array_fill(0, 7, 'faite'), $etats);
    }

    public function testCommandeEnLigneAConfirmerNEstPasMontreeConfirmee(): void
    {
        $commande = (new Commandes())->setStatut(false)->setDateCommande(new \DateTimeImmutable('2026-10-15 09:00'));

        $premiere = (new SuiviCommandeService())->etapes($commande)[0];

        self::assertSame('en_cours', $premiere['etat']);
        self::assertSame('à confirmer', $premiere['detail']);

        $commande->setStatut(true);
        $premiere = (new SuiviCommandeService())->etapes($commande)[0];

        self::assertSame('faite', $premiere['etat']);
        self::assertSame('15/10', $premiere['detail']);
    }
}
