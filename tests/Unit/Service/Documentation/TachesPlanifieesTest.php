<?php

namespace App\Tests\Unit\Service\Documentation;

use App\Service\Documentation\ReglesAcces;
use App\Service\Documentation\TachesPlanifiees;
use PHPUnit\Framework\TestCase;

final class TachesPlanifieesTest extends TestCase
{
    public function testFrequences(): void
    {
        self::assertSame('toutes les 15 minutes', TachesPlanifiees::frequenceCron('*/15 * * * *'));
        self::assertSame('chaque jour à 03:30', TachesPlanifiees::frequenceCron('30 3 * * *'));
        self::assertSame('au démarrage du serveur', TachesPlanifiees::frequenceCron('@reboot'));
        self::assertSame('chaque jour à 06:00', TachesPlanifiees::frequenceSchtasks('/sc daily /st 06:00'));
        self::assertSame('toutes les 5 minutes', TachesPlanifiees::frequenceSchtasks('/sc minute /mo 5'));
        self::assertFalse(TachesPlanifiees::estExpressionCron('chaque jour'));
    }

    public function testCrontabDunBlocDeCode(): void
    {
        $md = "```\n* * * * * cd /chemin/du/projet && php bin/console app:synchro -q\n0 * * * * cd /srv && php bin/console app:sauvegarde --auto\n```";
        $releve = TachesPlanifiees::depuisCrontab($md, 'docs/x.md', 'serveur en ligne');
        self::assertSame('chaque minute', $releve['app:synchro'][0]['frequence']);
        self::assertStringContainsString('ERP local', $releve['app:synchro'][0]['ou']);
        self::assertSame('serveur en ligne', $releve['app:sauvegarde'][0]['ou']);
    }

    public function testScriptsWindows(): void
    {
        $scripts = [
            'installer_tache_x.bat' => "set SCRIPT_X=C:\\app\\scripts\\windows\\x.bat\nschtasks /create /tn \"X\" /tr \"\\\"%SCRIPT_X%\\\"\" /sc minute /mo 1 /f\n",
            'x.bat' => "\"C:\\php\\php.exe\" bin\\console app:synchro -q\n",
        ];
        $releve = TachesPlanifiees::depuisScriptsWindows($scripts);
        self::assertSame('chaque minute', $releve['app:synchro'][0]['frequence']);
    }

    public function testPhrasesEtFusion(): void
    {
        $releve = TachesPlanifiees::depuisPhrases('Lancer `app:devis:relances` chaque heure sur le local.', 'docs/devis.md');
        self::assertSame('chaque heure', $releve['app:devis:relances'][0]['frequence']);
        $fusion = TachesPlanifiees::fusionner([$releve, $releve]);
        self::assertCount(1, $fusion['app:devis:relances']);
    }

    public function testRegleDAccesPremiereQuiCorrespond(): void
    {
        $regles = [['path' => '^/admin', 'roles' => 'ROLE_ADMIN'], ['path' => '^/x', 'roles' => ['ROLE_A'], 'methods' => ['POST']], ['path' => '^/', 'roles' => 'ROLE_USER']];
        self::assertSame(3, ReglesAcces::regle($regles, '/x/1', 'GET')['rang'] ?? null);
        self::assertSame(['ROLE_A'], ReglesAcces::regle($regles, '/x/1', 'POST')['roles'] ?? null);
        $pareFeux = ['synchro' => ['pattern' => '^/api/synchro/', 'security' => false, 'stateless' => true], 'main' => []];
        self::assertSame(['nom' => 'synchro', 'sans_etat' => true, 'securite' => false], ReglesAcces::pareFeu($pareFeux, '/api/synchro/lot'));
        self::assertSame('main', ReglesAcces::pareFeu($pareFeux, '/devis')['nom'] ?? null);
    }
}
