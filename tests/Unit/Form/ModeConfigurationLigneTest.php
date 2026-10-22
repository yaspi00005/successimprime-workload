<?php

namespace App\Tests\Unit\Form;

use App\Form\ModeConfigurationLigne;
use PHPUnit\Framework\TestCase;

final class ModeConfigurationLigneTest extends TestCase
{
    public function testAncienneLigneAutomatiqueTraiteeEnManuel(): void
    {
        // Le radio caché d'une ancienne ligne revient coché « automatique ».
        self::assertSame('manuel', ModeConfigurationLigne::soumis(['modeConfiguration' => 'automatique']));
    }

    public function testSansModeEnvoyeManuel(): void
    {
        self::assertSame('manuel', ModeConfigurationLigne::soumis([]));
        self::assertSame('manuel', ModeConfigurationLigne::soumis(['modeConfiguration' => '']));
        self::assertSame('manuel', ModeConfigurationLigne::soumis(['modeConfiguration' => 'manuel']));
    }

    public function testAncienModeLibreGarde(): void
    {
        self::assertSame('libre', ModeConfigurationLigne::soumis(['modeConfiguration' => ' Libre ']));
    }

    public function testValeurBizarreManuel(): void
    {
        self::assertSame('manuel', ModeConfigurationLigne::soumis(['modeConfiguration' => ['libre']]));
        self::assertSame('manuel', ModeConfigurationLigne::soumis(['modeConfiguration' => 'n_importe_quoi']));
    }
}
