<?php

namespace App\Tests\Unit\Service\Production;

use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\PieceTirage;
use App\Entity\TirageRouleau;
use App\Service\Production\TiragesRouleau;
use PHPUnit\Framework\TestCase;

/**
 * Deux rouleaux créés en même temps avec le même travail : le second
 * enregistré l'emporte, le premier refuse de placer ou de démarrer
 * (docs/production-rouleau.md).
 */
final class OrdresAilleursTest extends TestCase
{
    private function rouleau(string $numero): TirageRouleau
    {
        return new TirageRouleau($numero, new Machines(), 'Bâche 440 g', 'BACHE440', 3.2, 10, 4, null, new \DateTimeImmutable('2026-10-22 09:00'));
    }

    private function surLeRouleau(TirageRouleau $tirage): OrdreProduction
    {
        $o = new OrdreProduction();
        $tirage->ajouterPiece(new PieceTirage($tirage, $o, 1));
        $o->setTirage($tirage);

        return $o;
    }

    public function testRienAilleursQuandTousLesTravauxSontSurCeRouleau(): void
    {
        $a = $this->rouleau('R-1022-01');
        $this->surLeRouleau($a);
        $this->surLeRouleau($a);

        self::assertSame([], TiragesRouleau::ordresAilleurs($a));
    }

    public function testTravailPasseSurUnAutreRouleauOuRetire(): void
    {
        $a = $this->rouleau('R-1022-01');
        $b = $this->rouleau('R-1022-02');
        $passe = $this->surLeRouleau($a);
        $retire = $this->surLeRouleau($a);
        $this->surLeRouleau($a);
        $passe->setTirage($b);
        $retire->setTirage(null);

        self::assertSame([
            $passe->getNumero().' est passé sur le rouleau R-1022-02.',
            $retire->getNumero().' n\'est plus sur ce rouleau.',
        ], TiragesRouleau::ordresAilleurs($a));
    }

    public function testUnRouleauAnnuleNeRetientPasLeTravail(): void
    {
        $a = $this->rouleau('R-1022-01');
        $b = $this->rouleau('R-1022-02');
        $o = $this->surLeRouleau($a);
        $o->setTirage($b);
        $b->annuler(new \DateTimeImmutable('2026-10-22 09:05'));

        self::assertSame([$o->getNumero().' n\'est plus sur ce rouleau.'], TiragesRouleau::ordresAilleurs($a));
    }
}
