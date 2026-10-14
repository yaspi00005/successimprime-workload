<?php

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le poste de contrôle des fichiers (/pre-presse) liste les fichiers des
 * clients : il est réservé à la prépresse. L'opérateur peut seulement
 * ouvrir un fichier depuis l'ordre de production de sa machine.
 */
final class AccesPrePresseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function chemins(): iterable
    {
        yield 'liste des travaux' => ['/pre-presse/', ['ROLE_PREPRESSE']];
        yield 'nouveaux fichiers' => ['/pre-presse/verifier-nouveaux', ['ROLE_PREPRESSE']];
        yield 'contrôle d\'un travail' => ['/pre-presse/travail/3', ['ROLE_PREPRESSE']];
        yield 'fichier traité' => ['/pre-presse/travail/3/fichier-traite', ['ROLE_PREPRESSE']];
        yield 'envoi par morceaux' => ['/pre-presse/fichier-traite/abc/morceaux/0', ['ROLE_PREPRESSE']];
        yield 'voir un fichier' => ['/pre-presse/fichier/12/visualiser', ['ROLE_PREPRESSE', 'ROLE_PRODUCTION_VOIR']];
        yield 'télécharger un fichier' => ['/pre-presse/fichier/12/telecharger', ['ROLE_PREPRESSE', 'ROLE_PRODUCTION_VOIR']];
    }

    /**
     * @param list<string> $attendus
     */
    #[DataProvider('chemins')]
    public function testLaPremiereRegleQuiCorrespondExigeLaPrepresse(string $chemin, array $attendus): void
    {
        self::assertSame($attendus, $this->rolesPour($chemin), $chemin);
    }

    /**
     * Comme le pare-feu : la première règle dont le motif correspond gagne.
     *
     * @return list<string>
     */
    private function rolesPour(string $chemin): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/security.yaml', Yaml::PARSE_CUSTOM_TAGS);

        foreach ($config['security']['access_control'] as $regle) {
            if (preg_match('{'.$regle['path'].'}', $chemin)) {
                return (array) $regle['roles'];
            }
        }

        self::fail('Aucune règle pour '.$chemin);
    }
}
