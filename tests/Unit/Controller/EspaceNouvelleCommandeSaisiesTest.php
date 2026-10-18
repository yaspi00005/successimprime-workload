<?php

namespace App\Tests\Unit\Controller;

use App\Controller\EspaceNouvelleCommandeController;
use PHPUnit\Framework\TestCase;

/**
 * Saisies de la nouvelle commande (adresse tapée à la main, appel du
 * navigateur) : un tableau à la place d'un texte est écarté au lieu de
 * faire tomber la page.
 */
final class EspaceNouvelleCommandeSaisiesTest extends TestCase
{
    public function testUnTableauALaPlaceDUnTexteEstEcarte(): void
    {
        $saisies = self::saisies(['produit' => '900001', 'delai' => ['express'], 'unite_taille' => ['m'], 'refaire' => ['x'], 'quantite' => 2, 'oeillets' => true]);

        self::assertSame(['produit' => '900001', 'quantite' => '2', 'oeillets' => '1'], $saisies);
    }

    public function testFinitionsEtFichiersRestentDesListesDeTextes(): void
    {
        $saisies = self::saisies(['finitions' => [900001, '900002', ['x']], 'fichiers' => 'abc']);

        self::assertSame(['finitions' => ['900001', '900002']], $saisies);
        // Une liste vide reste une liste : « aucune finition », pas « finitions par défaut ».
        self::assertSame(['finitions' => []], self::saisies(['finitions' => []]));
    }

    /**
     * @param array<array-key, mixed> $brut
     *
     * @return array<string, mixed>
     */
    private static function saisies(array $brut): array
    {
        return (new \ReflectionMethod(EspaceNouvelleCommandeController::class, 'saisies'))->invoke(null, $brut);
    }
}
