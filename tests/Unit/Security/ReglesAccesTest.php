<?php

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Règles d'accès de config/packages/security.yaml (access_control) : la
 * première qui correspond à l'adresse s'applique, comme dans Symfony. Les
 * adresses viennent des contrôleurs : une règle écrite pour une autre
 * adresse laisse l'écran à tout compte connecté (repéré par la recette des
 * accès, php bin/console app:recette:acces). La prépresse a son propre
 * test (AccesPrePresseTest).
 */
final class ReglesAccesTest extends TestCase
{
    /** @var list<array{path: string, roles: string|list<string>}>|null */
    private static ?array $regles = null;

    public function testReglagesDeLAtelierALaDirection(): void
    {
        foreach (['/types/impression', '/types/impression/3/ajax', '/types/impression/mass-action', '/lot/etiquette', '/lot/etiquette/generer', '/lot/etiquette/4', '/lot/etiquette/4/telecharger'] as $chemin) {
            self::assertSame(['ROLE_ADMIN'], self::roles($chemin), $chemin);
        }
        // La page de suivi d'une étiquette (QR code) garde sa règle.
        self::assertSame(['ROLE_USER'], self::roles('/lot/etiquette/suivi/'.str_repeat('ab', 32)));
    }

    public function testReglesInchangeesAutour(): void
    {
        self::assertSame(['ROLE_PRODUCTION_VOIR'], self::roles('/production/12'));
        self::assertSame(['ROLE_PREPRESSE'], self::roles('/production/prepresse'));
        self::assertSame(['ROLE_PREPRESSE'], self::roles('/pre-presse/'));
        self::assertSame(['ROLE_PRODUCTION_GERER'], self::roles('/consommables'));
        self::assertSame(['ROLE_ADMIN'], self::roles('/produit-configurations'));
        self::assertSame(['ROLE_USER'], self::roles('/planning'));
    }

    /** @return list<string> rôles de la première règle qui correspond */
    private static function roles(string $chemin): array
    {
        if (null === self::$regles) {
            $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/security.yaml', Yaml::PARSE_CUSTOM_TAGS);
            self::$regles = $config['security']['access_control'];
        }
        foreach (self::$regles as $regle) {
            if (preg_match('{'.$regle['path'].'}', $chemin)) {
                return (array) $regle['roles'];
            }
        }
        self::fail('Aucune règle pour '.$chemin);
    }
}
