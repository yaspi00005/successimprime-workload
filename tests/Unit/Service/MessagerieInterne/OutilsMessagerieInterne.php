<?php

namespace App\Tests\Unit\Service\MessagerieInterne;

use App\Service\MessagerieInterne\ReglesMessagerieInterne;
use App\Service\DossiersClients\ReglagesDossiers;
use App\Service\ProduitsClient\ControleFichierLie;
use App\Service\ProduitsClient\DossierClient;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Outils communs aux tests de la messagerie de l'équipe : dossier racine imposé, réglages fixes. */
final class OutilsMessagerieInterne
{
    public static function dossierClient(?string $racine): DossierClient
    {
        $reglages = (new \ReflectionClass(ReglagesDossiers::class))->newInstanceWithoutConstructor();
        $controle = (new \ReflectionClass(ControleFichierLie::class))->newInstanceWithoutConstructor();

        return new DossierClient($reglages, $controle, new ArrayAdapter(), null, $racine ?? '');
    }

    public static function regles(int $tailleMaxMo = 50, bool $directionLitGroupes = true): ReglesMessagerieInterne
    {
        return new class($tailleMaxMo, $directionLitGroupes) extends ReglesMessagerieInterne {
            public function __construct(private readonly int $mo, private readonly bool $lit)
            {
            }

            public function tailleMax(): int
            {
                return $this->mo * 1024 * 1024;
            }

            public function directionLitGroupes(): bool
            {
                return $this->lit;
            }
        };
    }

    public static function dossierTemporaire(): string
    {
        $d = sys_get_temp_dir().'/mi_test_'.bin2hex(random_bytes(6));
        mkdir($d, 0775, true);

        return $d;
    }

    public static function supprimer(string $dossier): void
    {
        if (!is_dir($dossier)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dossier);
    }
}
