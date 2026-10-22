<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * Fiche d'une facture : « Encaisser » et « Enregistrer un paiement »
 * mènent à la page de paiement de la commande, réservée au droit
 * Encaissements (CommandesController). Sans ce droit (graphiste par
 * défaut), les boutons ne s'affichent pas, comme sur la commande et la
 * liste des factures.
 */
final class FactureEncaisserTest extends TestCase
{
    public function testLesBoutonsDePaiementDemandentLeDroitEncaissements(): void
    {
        $gabarit = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/factures/show.html.twig');

        self::assertMatchesRegularExpression("/set peutEncaisser = is_granted\\('ROLE_PAIEMENT_ENCAISSER'\\)/", $gabarit);

        $morceaux = explode("path( 'app_commandes_paiement'", $gabarit);
        self::assertGreaterThanOrEqual(3, \count($morceaux), 'les deux boutons de paiement');
        array_pop($morceaux);
        foreach ($morceaux as $avant) {
            // La condition qui entoure le lien : le dernier « {% if » avant lui.
            $condition = substr($avant, (int) strrpos($avant, '{% if'));
            self::assertStringContainsString('peutEncaisser', $condition);
        }
    }
}
