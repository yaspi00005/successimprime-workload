<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;

/**
 * « Devis et commandes » : les indicateurs commerciaux ne s'affichent
 * qu'avec leur droit. Sans lui (graphiste), la grille des cartes doit
 * rester fermée : une balise en trop sortait la liste du cadre de la page
 * et la poussait sous le menu.
 */
final class VentesIndicateursTest extends TestCase
{
    public function testLesBalisesRestentEquilibreesAvecEtSansLesIndicateurs(): void
    {
        $gabarit = (string) file_get_contents(\dirname(__DIR__, 3).'/templates/ventes/index.html.twig');
        $corps = substr($gabarit, (int) strpos($gabarit, '{% block body %}'));
        $corps = substr($corps, 0, (int) strpos($corps, '<div class="card si-bloc">'));
        self::assertStringContainsString('{% if voirStatsVentes %}', $corps);

        $bloc = '/\{% if voirStatsVentes %\}(.*?)\{% endif %\}/s';
        $avec = (string) preg_replace($bloc, '$1', $corps);
        $sans = (string) preg_replace($bloc, '', $corps);

        foreach (['avec le droit' => $avec, 'sans le droit' => $sans] as $cas => $html) {
            self::assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), $cas);
        }
        self::assertStringNotContainsString('Taux de transformation', $sans);
        self::assertStringContainsString('Commandes en cours', $sans);
    }
}
