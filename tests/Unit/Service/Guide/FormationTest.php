<?php

namespace App\Tests\Unit\Service\Guide;

use App\Service\Guide\CatalogueGuide;
use App\Service\Guide\Formation;
use PHPUnit\Framework\TestCase;

/**
 * Fiches du formateur (/aide/formation) et docs/formation.md : une
 * demi-journée par rôle en blocs de 30 minutes, des exercices sur les
 * comptes de démonstration, un résultat attendu à chaque exercice.
 */
final class FormationTest extends TestCase
{
    private const COMPTES = ['demo.kadiatou', 'demo.aminata', 'demo.seydou', 'demo.aissata', 'demo.oumar', 'demo.bakary', 'demo.awa'];

    public function testUnPlanParRoleDuGuide(): void
    {
        $roles = array_keys(CatalogueGuide::GROUPES);
        foreach (array_keys(Formation::plans()) as $role) {
            self::assertContains($role, $roles);
        }
        self::assertSame(['caisse', 'graphiste', 'production', 'livreur', 'entretien', 'direction'], array_keys(Formation::plans()));
    }

    public function testBlocsDeTrenteMinutesEtFichesConnues(): void
    {
        foreach (Formation::plans() as $role => $plan) {
            self::assertContains($plan['compte'], self::COMPTES, $role);
            $heures = array_column($plan['blocs'], 'heure');
            self::assertNotEmpty($heures);
            $minutes = array_map(static fn (string $h): int => (int) substr($h, 0, 2) * 60 + (int) substr($h, 3, 2), $heures);
            foreach ($minutes as $i => $m) {
                if ($i > 0) {
                    self::assertSame(30, $m - $minutes[$i - 1], $role.' : bloc de '.$heures[$i]);
                }
            }
            // La durée annoncée est celle des blocs (une demi-journée au plus).
            $duree = \count($heures) * 30;
            self::assertLessThanOrEqual(240, $duree, $role);
            self::assertSame(($duree / 60).' h', $plan['duree'], $role);

            foreach ($plan['blocs'] as $bloc) {
                foreach ($bloc['fiches'] as $cle) {
                    self::assertNotNull(CatalogueGuide::fiche($cle), $role.' : fiche '.$cle);
                }
            }
        }
    }

    public function testExercicesAvecResultatAttendu(): void
    {
        $total = 0;
        foreach (Formation::plans() as $role => $plan) {
            self::assertNotEmpty($plan['exercices'], $role);
            foreach ($plan['exercices'] as $exercice) {
                self::assertContains($exercice['compte'], self::COMPTES, $role.' : '.$exercice['titre']);
                self::assertNotEmpty($exercice['consigne'], $exercice['titre']);
                self::assertNotSame('', trim($exercice['attendu']), $exercice['titre']);
                ++$total;
            }
            self::assertNotEmpty($plan['final']['consigne'], $role);
            self::assertNotSame('', trim($plan['final']['attendu']), $role);
        }
        self::assertGreaterThanOrEqual(30, $total);
    }

    public function testExerciceFinalDuJalonJ7(): void
    {
        $caisse = Formation::plans()['caisse'];
        $texte = implode(' ', [$caisse['final']['titre'], ...$caisse['final']['consigne'], $caisse['final']['attendu']]);

        self::assertStringContainsString('J7', $caisse['final']['titre']);
        foreach (['client', 'devis', 'commande', 'acompte', 'facture'] as $etape) {
            self::assertStringContainsStringIgnoringCase($etape, $texte);
        }
    }

    /** docs/formation.md reprend les mêmes exercices que la page du formateur. */
    public function testLaDocReprendLesExercices(): void
    {
        $doc = (string) file_get_contents(\dirname(__DIR__, 4).'/docs/formation.md');
        foreach (Formation::plans() as $plan) {
            self::assertStringContainsString($plan['titre'], $doc);
            self::assertStringContainsString($plan['final']['titre'], $doc);
            foreach ($plan['exercices'] as $exercice) {
                self::assertStringContainsString($exercice['titre'], $doc);
                self::assertStringContainsString($exercice['attendu'], $doc);
            }
        }
    }
}
