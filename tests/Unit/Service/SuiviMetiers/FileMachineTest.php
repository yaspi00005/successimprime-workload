<?php

namespace App\Tests\Unit\Service\SuiviMetiers;

use App\Entity\CommandesDetails;
use App\Entity\OrdreProduction;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\FileMachine;
use PHPUnit\Framework\TestCase;

final class FileMachineTest extends TestCase
{
    private static function ordre(string $largeur, string $longueur, int $quantite, ?string $prevue, ?string $termine): OrdreProduction
    {
        $ligne = new CommandesDetails();
        foreach (['largeur' => $largeur, 'longueur' => $longueur, 'quantite' => $quantite] as $champ => $valeur) {
            (new \ReflectionProperty(CommandesDetails::class, $champ))->setValue($ligne, $valeur);
        }
        $ordre = new OrdreProduction();
        foreach (['commandeDetail' => $ligne, 'quantite' => $quantite, 'finPrevueLe' => $prevue ? new \DateTimeImmutable($prevue) : null, 'termineLe' => $termine ? new \DateTimeImmutable($termine) : null] as $champ => $valeur) {
            (new \ReflectionProperty(OrdreProduction::class, $champ))->setValue($ordre, $valeur);
        }

        return $ordre;
    }

    public function testBacheTermineeAvantLHeure(): void
    {
        // 6 × 2 m × 2 = 24 m² : +5 et 2 × 1 pt
        $ordre = self::ordre('6', '2', 2, '2026-10-15 11:30', '2026-10-15 11:02');
        self::assertSame(24.0, FileMachine::m2($ordre));
        self::assertSame(['p_avant_heure' => 1, 'p_10m2' => 2.0], FileMachine::faitsOrdre($ordre));
        self::assertSame(7, (new Bareme())->points(FileMachine::faitsOrdre($ordre)));
    }

    public function testEnRetardEtPetiteSurface(): void
    {
        $ordre = self::ordre('1.37', '4.4', 1, '2026-10-15 11:30', '2026-10-15 12:10');
        self::assertSame([], FileMachine::faitsOrdre($ordre));
    }

    public function testSansDimensions(): void
    {
        self::assertNull(FileMachine::m2(self::ordre('0', '0', 100, null, null)));
    }
}
