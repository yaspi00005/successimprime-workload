<?php

namespace App\Service\Bascule;

/**
 * Sonde du pire cas, pour une copie de la structure sans données (fichier
 * envoyé par la boutique avant la mise en production) : chaque table est
 * supposée remplie, avec des valeurs qui ne tiennent pas. Le classement
 * donne alors la liste de ce qui pourrait bloquer sur la vraie base.
 */
final class SondePessimiste implements SondeDonnees
{
    public function lignes(string $table): int
    {
        return 2;
    }

    public function renseignees(string $table, string $colonne): int
    {
        return 1;
    }

    public function longueurMax(string $table, string $colonne): int
    {
        return \PHP_INT_MAX;
    }

    public function doublons(string $table, array $colonnes, bool $sansIdentifiantsVides = false): int
    {
        return 1;
    }

    public function identifiantsVides(string $table, string $colonne): int
    {
        return 0;
    }

    public function orphelins(string $table, array $colonnes, string $parente, array $colonnesParentes): int
    {
        return 1;
    }

    public function valeurExiste(string $table, string $colonne, string $valeur): bool
    {
        return false;
    }
}
