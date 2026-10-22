<?php

namespace App\Service\Import;

/**
 * Relie l'en-tête du fichier aux colonnes d'un type : « Tél. », « telephone »
 * et « Téléphone » désignent la même colonne. L'ordre des colonnes est libre.
 */
final class Colonnes
{
    /**
     * @param list<string>                                                                                           $entetes
     * @param list<array{cle: string, libelle: string, alias: list<string>, obligatoire: bool, droit?: string}> $colonnes
     * @param callable(string): bool                                                                                 $droit
     *
     * @return array{index: array<string, int>, inconnues: list<string>, manquantes: list<string>, interdites: list<string>, doublees: list<string>}
     */
    public static function associer(array $entetes, array $colonnes, callable $droit): array
    {
        $parNom = [];
        foreach ($colonnes as $c) {
            foreach ([$c['libelle'], $c['cle'], ...$c['alias']] as $nom) {
                $parNom[Valeurs::cle($nom)] ??= $c;
            }
        }

        $index = [];
        $inconnues = [];
        $interdites = [];
        $doublees = [];
        foreach ($entetes as $i => $entete) {
            $cle = Valeurs::cle(preg_replace('/\(.*\)|\*/', '', $entete) ?? $entete);
            if ('' === $cle) {
                continue;
            }
            $colonne = $parNom[$cle] ?? $parNom[Valeurs::cle($entete)] ?? null;
            if (null === $colonne) {
                $inconnues[] = $entete;
                continue;
            }
            if (isset($colonne['droit']) && !$droit($colonne['droit'])) {
                // Colonne de coûts sans le droit de les voir : ni lue, ni montrée.
                $interdites[] = $entete;
                continue;
            }
            if (isset($index[$colonne['cle']])) {
                $doublees[] = $entete;
                continue;
            }
            $index[$colonne['cle']] = $i;
        }

        $manquantes = [];
        foreach ($colonnes as $c) {
            if ($c['obligatoire'] && !isset($index[$c['cle']]) && (!isset($c['droit']) || $droit($c['droit']))) {
                $manquantes[] = $c['libelle'];
            }
        }

        return ['index' => $index, 'inconnues' => $inconnues, 'manquantes' => $manquantes, 'interdites' => $interdites, 'doublees' => $doublees];
    }

    /**
     * Lignes du fichier avec leurs valeurs rangées par clé de colonne.
     *
     * @param list<array{numero: int, valeurs: list<string>}> $lignes
     * @param array<string, int>                              $index
     *
     * @return list<LigneImport>
     */
    public static function lignes(array $lignes, array $index): array
    {
        $resultat = [];
        foreach ($lignes as $l) {
            $valeurs = [];
            foreach ($index as $cle => $i) {
                $valeurs[$cle] = trim((string) ($l['valeurs'][$i] ?? ''));
            }
            if ('' === implode('', $valeurs)) {
                // Ligne remplie seulement dans des colonnes inconnues : rien à lire.
                continue;
            }
            $resultat[] = new LigneImport($l['numero'], $valeurs);
        }

        return $resultat;
    }
}
