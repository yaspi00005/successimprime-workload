<?php

namespace App\Service\Documentation;

use Symfony\Component\Routing\Route;

/**
 * Règles de config/packages/security.yaml appliquées à une adresse, comme
 * le fait Symfony : le premier pare-feu dont le motif correspond, la
 * première règle access_control dont le chemin (et la méthode) correspond.
 *
 * Statique et pur : on lui donne la configuration lue dans le fichier.
 */
final class ReglesAcces
{
    /**
     * @param list<array<string, mixed>> $regles access_control de security.yaml
     *
     * @return array{chemin: string, roles: list<string>, methodes: list<string>, rang: int}|null
     */
    public static function regle(array $regles, string $chemin, string $methode = 'GET'): ?array
    {
        foreach ($regles as $rang => $regle) {
            $motif = (string) ($regle['path'] ?? '');
            $methodes = array_map('strtoupper', (array) ($regle['methods'] ?? []));
            if ([] !== $methodes && !\in_array(strtoupper($methode), $methodes, true)) {
                continue;
            }
            if ('' !== $motif && 1 !== @preg_match('{'.$motif.'}', $chemin)) {
                continue;
            }

            return ['chemin' => $motif, 'roles' => array_values(array_map('strval', (array) ($regle['roles'] ?? []))), 'methodes' => $methodes, 'rang' => (int) $rang + 1];
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $pareFeux firewalls de security.yaml
     *
     * @return array{nom: string, sans_etat: bool, securite: bool}|null
     */
    public static function pareFeu(array $pareFeux, string $chemin): ?array
    {
        foreach ($pareFeux as $nom => $config) {
            $motif = $config['pattern'] ?? null;
            if (null !== $motif && 1 !== @preg_match('{'.$motif.'}', $chemin)) {
                continue;
            }

            return ['nom' => (string) $nom, 'sans_etat' => (bool) ($config['stateless'] ?? false), 'securite' => false !== ($config['security'] ?? true)];
        }

        return null;
    }

    /**
     * Une adresse concrète pour une route : chaque {variable} remplacée par
     * une valeur qui respecte sa contrainte (pour trouver la règle d'accès).
     */
    public static function cheminExemple(Route $route): string
    {
        $chemin = $route->getPath();
        $candidats = ['1', '123', 'abc', 'a1b2c3', str_repeat('a', 24), str_repeat('a', 32), str_repeat('a', 48), str_repeat('a', 64), 'ABCD-23', 'SI'.str_repeat('A', 18), '2026', 'x.pdf', 'c', 'pdf'];

        return (string) preg_replace_callback('/\{!?([A-Za-z0-9_]+)(?:<([^>]*)>)?(?:\?[^}]*)?\}/', static function (array $m) use ($route, $candidats): string {
            $exigence = ('' !== ($m[2] ?? '')) ? $m[2] : $route->getRequirement($m[1]);
            $defaut = $route->getDefault($m[1]);
            if (null === $exigence || '' === $exigence) {
                return \is_scalar($defaut) && '' !== (string) $defaut ? (string) $defaut : '1';
            }
            foreach ($candidats as $c) {
                if (1 === @preg_match('{^(?:'.$exigence.')$}', $c)) {
                    return $c;
                }
            }
            // Une liste fermée (« a|b|c ») : sa première valeur.
            if (preg_match('/^[A-Za-z0-9_.-]+(\|[A-Za-z0-9_.-]+)*$/', $exigence)) {
                return explode('|', $exigence)[0];
            }

            return '1';
        }, $chemin);
    }
}
