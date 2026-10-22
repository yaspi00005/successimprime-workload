<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ColisSuggeres;
use App\Service\Production\NomsProduits;
use PHPUnit\Framework\TestCase;

/**
 * Nombre de colis conseillé et contenu de chaque colis (PD05,
 * docs/production-colis.md) : bâche roulée = un colis par pièce,
 * kakémono = un colis pour deux, papier = cartons de 500.
 */
final class ColisSuggeresTest extends TestCase
{
    public function testLesNomsDesProduits(): void
    {
        self::assertSame(NomsProduits::ROULEAU, NomsProduits::reconnaitre('Bâche 440 g frontlit')['famille'] ?? null);
        self::assertSame(NomsProduits::HOUSSE, NomsProduits::reconnaitre(null, 'Kakemono 85 x 200')['famille'] ?? null);
        self::assertSame(NomsProduits::HOUSSE, NomsProduits::reconnaitre('Roll-up 85 × 200')['famille'] ?? null);
        self::assertSame(NomsProduits::CARTON, NomsProduits::reconnaitre('Flyers A5 recto')['famille'] ?? null);
        self::assertSame('carte de visite', NomsProduits::reconnaitre('Cartes de visite 350 g')['singulier'] ?? null);
        self::assertNull(NomsProduits::reconnaitre('Impression spéciale', ''));

        self::assertSame('2 bâches roulées', NomsProduits::resume(2, NomsProduits::reconnaitre('Bâche')));
        self::assertSame('1 kakémono', NomsProduits::resume(1, NomsProduits::reconnaitre('Kakémono')));
        self::assertSame('1 000 flyers', NomsProduits::resume(1000, NomsProduits::reconnaitre('Flyer')));
    }

    public function testLaRegleDeLaMaquette(): void
    {
        // CM-0183 : 2 bâches → 2 colis ; CM-0186 : 2 kakémonos → 1 ; CM-0430 : 1 kakémono → 1 ; CM-0181 : 1 000 flyers → 2 cartons.
        self::assertSame(2, ColisSuggeres::pourLigne(NomsProduits::ROULEAU, 2));
        self::assertSame(1, ColisSuggeres::pourLigne(NomsProduits::HOUSSE, 2));
        self::assertSame(1, ColisSuggeres::pourLigne(NomsProduits::HOUSSE, 1));
        self::assertSame(2, ColisSuggeres::pourLigne(NomsProduits::HOUSSE, 3));
        self::assertSame(2, ColisSuggeres::pourLigne(NomsProduits::CARTON, 1000));
        self::assertSame(1, ColisSuggeres::pourLigne(NomsProduits::CARTON, 120));
        self::assertSame(1, ColisSuggeres::pourLigne(NomsProduits::AUTRE, 40));
        self::assertSame(99, ColisSuggeres::pourLigne(NomsProduits::ROULEAU, 500));

        self::assertSame(3, ColisSuggeres::pourCommande([['famille' => NomsProduits::ROULEAU, 'quantite' => 2], ['famille' => NomsProduits::HOUSSE, 'quantite' => 1]]));
    }

    public function testDeuxBachesDansDeuxColis(): void
    {
        $colis = ColisSuggeres::repartir([self::ligne('Bâche 300 × 100 cm', 2, NomsProduits::ROULEAU, 'ourlet + œillets')], 2);

        self::assertCount(2, $colis);
        foreach ($colis as $c) {
            self::assertSame(NomsProduits::ROULEAU, $c['emballage']);
            self::assertSame('Bâche 300 × 100 cm × 1 · ourlet + œillets', $c['contenu'][0]['libelle']);
            self::assertSame(1, $c['contenu'][0]['quantite']);
        }
    }

    public function testMilleFlyersEnDeuxCartonsDeCinqCents(): void
    {
        $colis = ColisSuggeres::repartir([self::ligne('Flyers A5', 1000, NomsProduits::CARTON)], 2);

        self::assertSame([500, 500], array_map(static fn (array $c): int => $c['contenu'][0]['quantite'], $colis));
        self::assertSame('Flyers A5 × 500', $colis[0]['contenu'][0]['libelle']);
    }

    public function testUnSeulColisPourDeuxLignes(): void
    {
        $colis = ColisSuggeres::repartir([self::ligne('Kakémono 85 × 200 cm', 1, NomsProduits::HOUSSE), self::ligne('Flyers A5', 300, NomsProduits::CARTON, '', 2)], 1);

        self::assertCount(1, $colis);
        self::assertCount(2, $colis[0]['contenu']);
        self::assertSame(NomsProduits::AUTRE, $colis[0]['emballage']);
    }

    public function testJamaisPlusDeColisQueDePieces(): void
    {
        $colis = ColisSuggeres::repartir([self::ligne('Kakémono', 2, NomsProduits::HOUSSE)], 5);

        self::assertCount(2, $colis);
        self::assertSame(2, ColisSuggeres::maximum([['quantite' => 2]]));
    }

    public function testUnAutreNombreQueLaSuggestion(): void
    {
        // 2 bâches (2 colis conseillés) et 1 000 flyers (2 cartons) mis en 3 colis : chaque ligne garde au moins un colis.
        $colis = ColisSuggeres::repartir([self::ligne('Bâche', 2, NomsProduits::ROULEAU), self::ligne('Flyers', 1000, NomsProduits::CARTON, '', 2)], 3);

        self::assertCount(3, $colis);
        $parLigne = [];
        foreach ($colis as $c) {
            foreach ($c['contenu'] as $p) {
                $parLigne[$p['ligne']] = ($parLigne[$p['ligne']] ?? 0) + $p['quantite'];
            }
        }
        self::assertSame([1 => 2, 2 => 1000], $parLigne);
    }

    /** @return array{ordre: int, ligne: int, nom: string, finitions: string, quantite: int, famille: string} */
    private static function ligne(string $nom, int $quantite, string $famille, string $finitions = '', int $id = 1): array
    {
        return ['ordre' => $id, 'ligne' => $id, 'nom' => $nom, 'finitions' => $finitions, 'quantite' => $quantite, 'famille' => $famille];
    }
}
