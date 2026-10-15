<?php

namespace App\Service\Bascule;

/**
 * Ce que le classement des écarts a besoin de savoir sur les données de la
 * boutique. Seulement des nombres : jamais une valeur n'en sort.
 */
interface SondeDonnees
{
    public function lignes(string $table): int;

    /** Lignes où la colonne n'est pas NULL. */
    public function renseignees(string $table, string $colonne): int;

    /** Plus grande longueur en caractères des valeurs de la colonne. */
    public function longueurMax(string $table, string $colonne): int;

    /**
     * Valeurs répétées (groupes de plus d'une ligne) sur ces colonnes, les
     * lignes avec un NULL mises à part comme le fait un index unique.
     * $sansIdentifiantsVides met aussi à part les identifiants à zéro.
     *
     * @param list<string> $colonnes
     */
    public function doublons(string $table, array $colonnes, bool $sansIdentifiantsVides = false): int;

    /** Identifiants binaires jamais tirés : NULL ou uniquement des octets à zéro. */
    public function identifiantsVides(string $table, string $colonne): int;

    /**
     * Lignes qui pointent vers une ligne absente de la table parente.
     *
     * @param list<string> $colonnes
     * @param list<string> $colonnesParentes
     */
    public function orphelins(string $table, array $colonnes, string $parente, array $colonnesParentes): int;

    /** La table parente a-t-elle une ligne dont la colonne vaut cette valeur ? */
    public function valeurExiste(string $table, string $colonne, string $valeur): bool;
}
