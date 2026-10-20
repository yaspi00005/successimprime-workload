<?php

namespace App\Tests\Unit\Routing;

use App\Routing\CodeId;
use App\Routing\IdsChiffres;
use PHPUnit\Framework\TestCase;

final class CodeIdTest extends TestCase
{
    public function testAllerRetour(): void
    {
        $codes = new CodeId('cle-de-test');
        foreach ([1, 2, 3, 4217, 900001, 99999999, 0xFFFFFFFF] as $id) {
            $code = $codes->coder($id);
            self::assertMatchesRegularExpression('/^'.CodeId::MOTIF.'$/', $code);
            self::assertSame($id, $codes->decoder($code));
        }
    }

    public function testDeuxFichesQuiSeSuiventNeSeRessemblentPas(): void
    {
        $codes = new CodeId('cle-de-test');
        self::assertNotSame(substr($codes->coder(900001), 0, 4), substr($codes->coder(900002), 0, 4));
    }

    public function testUnCodeInventeOuDUneAutreCleEstRefuse(): void
    {
        $codes = new CodeId('cle-de-test');
        self::assertNull($codes->decoder('AAAAAAAAA'));
        self::assertNull($codes->decoder('4217'));
        self::assertNull((new CodeId('autre-cle'))->decoder($codes->coder(4217)));
        $code = $codes->coder(4217);
        $modifie = substr($code, 0, 8).('a' === $code[8] ? 'b' : 'a');
        self::assertNull($codes->decoder($modifie));
    }

    public function testLesNumerosNeSeConfondentPasAvecUnCode(): void
    {
        self::assertSame(4217, IdsChiffres::numero('4217'));
        self::assertSame(12, IdsChiffres::numero(12));
        self::assertNull(IdsChiffres::numero('0'));
        self::assertNull(IdsChiffres::numero('123456789'));
        self::assertNull(IdsChiffres::numero('nouveau'));
        self::assertFalse(IdsChiffres::routeConcernee('_wdt'));
        self::assertFalse(IdsChiffres::routeConcernee('api_commandes_get'));
        self::assertTrue(IdsChiffres::routeConcernee('app_commandes_show'));
    }
}
