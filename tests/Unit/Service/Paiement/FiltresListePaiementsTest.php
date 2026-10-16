<?php

namespace App\Tests\Unit\Service\Paiement;

use App\Entity\Paiements;
use App\Service\Paiement\FiltresListePaiements;
use PHPUnit\Framework\TestCase;

/**
 * Filtres de la liste des paiements réglés par les flèches des en-têtes
 * (docs/filtres-tableaux.md) : seules les valeurs connues passent.
 */
final class FiltresListePaiementsTest extends TestCase
{
    public function testSansParametreAucunFiltreEtTriParDate(): void
    {
        $filtres = FiltresListePaiements::depuis([]);

        self::assertSame('recent', $filtres['tri']);
        self::assertFalse(FiltresListePaiements::actifs($filtres));
        foreach (['mode', 'statut', 'compte', 'du', 'au', 'min', 'max'] as $cle) {
            self::assertNull($filtres[$cle], $cle);
        }
    }

    public function testLesValeursConnuesSontGardees(): void
    {
        $filtres = FiltresListePaiements::depuis([
            'mode' => Paiements::MODE_ORANGE_MONEY,
            'statut' => Paiements::STATUT_EN_ATTENTE,
            'compte' => '3',
            'du' => '2026-10-01',
            'au' => '2026-10-15',
            'min' => '25 000',
            'max' => '100000',
            'tri' => 'montant_desc',
        ]);

        self::assertSame(Paiements::MODE_ORANGE_MONEY, $filtres['mode']);
        self::assertSame(Paiements::STATUT_EN_ATTENTE, $filtres['statut']);
        self::assertSame(3, $filtres['compte']);
        self::assertSame('2026-10-01', $filtres['du']?->format('Y-m-d'));
        self::assertSame('2026-10-15', $filtres['au']?->format('Y-m-d'));
        self::assertSame(25000, $filtres['min']);
        self::assertSame(100000, $filtres['max']);
        self::assertSame('montant_desc', $filtres['tri']);
        self::assertTrue(FiltresListePaiements::actifs($filtres));
    }

    public function testLesValeursInconnuesOuMalEcritesSontIgnorees(): void
    {
        $filtres = FiltresListePaiements::depuis([
            'mode' => 'bitcoin',
            'statut' => "valide' OR 1=1",
            'compte' => '-2',
            'du' => '2026-02-30',
            'au' => '15/10/2026',
            'min' => '-500',
            'max' => '12,5',
            'tri' => 'p.montant; DROP',
        ]);

        foreach (['mode', 'statut', 'compte', 'du', 'au', 'min', 'max'] as $cle) {
            self::assertNull($filtres[$cle], $cle);
        }
        self::assertSame('recent', $filtres['tri']);
        self::assertFalse(FiltresListePaiements::actifs($filtres));
    }

    public function testUnTableauDansLAdresseNeCassePasLaListe(): void
    {
        $filtres = FiltresListePaiements::depuis(['mode' => ['especes'], 'min' => ['1']]);

        self::assertNull($filtres['mode']);
        self::assertNull($filtres['min']);
    }

    public function testLesFiltresRedeviennentDesParametresDAdresse(): void
    {
        $parametres = FiltresListePaiements::enParametres(FiltresListePaiements::depuis([
            'mode' => Paiements::MODE_WAVE,
            'compte' => '7',
            'du' => '2026-10-01',
            'min' => '1 000',
            'statut' => 'inconnu',
            'tri' => 'recent',
        ]));

        self::assertSame(['mode' => 'wave', 'compte' => '7', 'du' => '2026-10-01', 'min' => '1000'], $parametres);
        self::assertSame(['tri' => 'ancien'], FiltresListePaiements::enParametres(FiltresListePaiements::depuis(['tri' => 'ancien'])));
    }

    public function testLeTriSeulNEstPasUnFiltre(): void
    {
        self::assertFalse(FiltresListePaiements::actifs(FiltresListePaiements::depuis(['tri' => 'ancien'])));
        self::assertTrue(FiltresListePaiements::actifs(FiltresListePaiements::depuis(['min' => '0'])), 'minimum 0 : filtre posé');
    }
}
