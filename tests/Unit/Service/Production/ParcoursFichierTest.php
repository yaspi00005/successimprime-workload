<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\ParcoursFichier;
use PHPUnit\Framework\TestCase;

/**
 * Étape « Fichier de production » du parcours (maquette PD01) : ce que
 * l'analyse du prépresse a vraiment trouvé, sans promettre une conversion
 * que le serveur ne fait pas.
 */
final class ParcoursFichierTest extends TestCase
{
    public function testImageTaillePeuDefinieEtRvb(): void
    {
        $analyse = [
            ['libelle' => 'Résolution réelle à 300 × 100 cm', 'valeur' => '12 dpi (conseillé : 72)', 'etat' => 'erreur'],
            ['libelle' => 'Mode colorimétrique', 'valeur' => 'RVB : les couleurs vives peuvent ternir à l\'impression', 'etat' => 'attention'],
            ['libelle' => 'Polices', 'valeur' => 'Image : pas de police à incorporer', 'etat' => 'ok'],
        ];

        self::assertSame(['PNG', '12 dpi (conseillé : 72)', 'RVB : conversion aux couleurs d\'impression au RIP'], ParcoursFichier::infosFichier('awa_300x100_v1.png', $analyse));
    }

    public function testPdfFormatFondsPerdusEtCmjn(): void
    {
        $analyse = [
            ['libelle' => 'Format de page', 'valeur' => '300 × 100 cm', 'etat' => 'a_verifier'],
            ['libelle' => 'Fonds perdus', 'valeur' => '20 mm', 'etat' => 'ok'],
            ['libelle' => 'Mode colorimétrique', 'valeur' => 'CMJN', 'etat' => 'ok'],
        ];

        self::assertSame(['PDF', '300 × 100 cm', 'fond perdu 20 mm', 'CMJN'], ParcoursFichier::infosFichier('bache.pdf', $analyse));
    }

    public function testSansAnalyseNiNom(): void
    {
        self::assertSame([], ParcoursFichier::infosFichier('', []));
        self::assertSame(['Non lisible automatiquement'], ParcoursFichier::infosFichier('', [['libelle' => 'Type de fichier', 'valeur' => 'Non lisible automatiquement']]));
    }
}
