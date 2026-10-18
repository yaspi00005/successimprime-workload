<?php

namespace App\Tests\Unit\Service\Parametres;

use App\Controller\ParametresController;
use App\Service\Parametres\CatalogueParametres;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Chaque tuile doit pouvoir être ouverte et enregistrée : sa clé respecte
 * l'exigence des routes du panneau (« atelier_3d » était refusée).
 */
final class ClesTuilesTest extends TestCase
{
    public function testToutesLesClesPassentLesRoutesDuPanneau(): void
    {
        $exigences = [];
        foreach ((new \ReflectionClass(ParametresController::class))->getMethods() as $methode) {
            foreach ($methode->getAttributes(Route::class) as $attribut) {
                $exigence = $attribut->newInstance()->requirements['tuile'] ?? null;
                if (null !== $exigence) {
                    $exigences[$methode->getName()] = $exigence;
                }
            }
        }
        self::assertNotEmpty($exigences);

        foreach (array_keys(CatalogueParametres::tuiles()) as $cle) {
            foreach ($exigences as $methode => $exigence) {
                self::assertMatchesRegularExpression('#^'.$exigence.'$#', $cle, sprintf('La tuile « %s » est refusée par %s.', $cle, $methode));
            }
        }
    }
}
