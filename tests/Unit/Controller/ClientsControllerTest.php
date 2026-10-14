<?php

namespace App\Tests\Unit\Controller;

use App\Controller\ClientsController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ClientsControllerTest extends TestCase
{
    /** Les relevés PDF d'un client montrent ce qu'il a payé : pas sans le droit Paiements. */
    public function testRelevesDuClientAvecLeDroitPaiements(): void
    {
        foreach (['pdfImpayes', 'pdfToutesCommandes'] as $methode) {
            $droits = array_map(
                static fn (\ReflectionAttribute $attribut): mixed => $attribut->newInstance()->attribute,
                (new \ReflectionMethod(ClientsController::class, $methode))->getAttributes(IsGranted::class),
            );

            self::assertSame(['ROLE_PAIEMENT_VOIR'], $droits, $methode);
        }
    }
}
