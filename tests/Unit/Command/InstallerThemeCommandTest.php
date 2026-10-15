<?php

namespace App\Tests\Unit\Command;

use App\Command\InstallerThemeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le thème demandait ses polices à Google : l'installation retire ces
 * « @import », les polices viennent de public/vendor (docs/securite.md).
 */
final class InstallerThemeCommandTest extends TestCase
{
    /** Début du style.css du thème Workload, tel qu'il est livré. */
    private const DEBUT_THEME = <<<'CSS'
        @import url("https://fonts.googleapis.com/css2?family=Nunito+Sans:ital,wght@0,200;0,300;0,400;0,600;0,700;0,800;0,900;1,200;1,300;1,400;1,600;1,700;1,800;1,900&display=swap");
        @import url("https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700,800");
        @import url("https://fonts.googleapis.com/css?family=Open+Sans:400,600,700");
        @import url("https://fonts.googleapis.com/css?family=Montserrat:400,500,600,700");
        @import url("https://fonts.googleapis.com/css?family=Roboto:400,500,700");
        @import url("https://fonts.googleapis.com/css?family=Nunito:400,600,700");
        @import url("./../icons/simple-line-icons/css/simple-line-icons.css");
        @import url(./../vendor/animate/animate.min.css);
        :root {
          --primary: #0F4590; }

        CSS;

    public function testRetireLesPolicesGoogleEtGardeLesFichiersDuTheme(): void
    {
        $css = InstallerThemeCommand::sansImportsExternes(self::DEBUT_THEME, $retires);

        self::assertSame(6, $retires);
        self::assertSame("@import url(\"./../icons/simple-line-icons/css/simple-line-icons.css\");\n@import url(./../vendor/animate/animate.min.css);\n:root {\n  --primary: #0F4590; }\n", $css);
        self::assertSame(0, InstallerThemeCommand::importsExternes($css));
        self::assertSame(6, InstallerThemeCommand::importsExternes(self::DEBUT_THEME));
    }

    public function testAutresEcrituresDUnImportExterne(): void
    {
        // CSS compressé sur une ligne, sans guillemets, sans « https: », avec un média.
        $css = '@import url(//fonts.googleapis.com/css?family=Roboto);@import "https://cdn.example.org/a.css" screen;@import url(local.css);.a{color:red}';

        self::assertSame('@import url(local.css);.a{color:red}', InstallerThemeCommand::sansImportsExternes($css, $retires));
        self::assertSame(2, $retires);
        self::assertSame(0, InstallerThemeCommand::importsExternes('@import url("../icons/a.css"); .b { background: url("https://exemple.org/x.png"); }'));
    }

    public function testCorrigeUnThemeDejaInstalle(): void
    {
        $dossier = sys_get_temp_dir().'/si-theme-'.bin2hex(random_bytes(4));
        $fs = new Filesystem();
        $fs->dumpFile($dossier.'/css/style.css', self::DEBUT_THEME);
        try {
            $testeur = new CommandTester(new InstallerThemeCommand($dossier));

            self::assertSame(Command::SUCCESS, $testeur->execute(['--polices-locales' => true]));
            self::assertStringContainsString('6 lignes', $testeur->getDisplay());
            self::assertStringNotContainsString('googleapis', (string) file_get_contents($dossier.'/css/style.css'));
            self::assertStringContainsString('simple-line-icons', (string) file_get_contents($dossier.'/css/style.css'));

            // Une seconde fois : rien à faire. Sans dossier ni option : erreur.
            self::assertSame(Command::SUCCESS, $testeur->execute(['--polices-locales' => true]));
            self::assertStringContainsString('ne demande déjà plus rien', $testeur->getDisplay());
            self::assertSame(Command::FAILURE, $testeur->execute([]));
        } finally {
            $fs->remove($dossier);
        }
    }
}
