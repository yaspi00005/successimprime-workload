<?php

namespace App\Service\Http;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Nombre entier lu dans une requête. Depuis Symfony 8, InputBag::getInt
 * répond 400 (« Input value "bat" cannot be converted to "int" ») dès que le
 * champ est vide : une liste « Joindre un BAT… » laissée sur son premier
 * choix suffisait à faire échouer l'envoi. Ici, vide ou illisible donne la
 * valeur par défaut, comme avant.
 */
final class Entree
{
    public static function entier(ParameterBag $donnees, string $cle, int $defaut = 0): int
    {
        $valeur = $donnees->all()[$cle] ?? null;
        if (\is_int($valeur)) {
            return $valeur;
        }
        if (!\is_scalar($valeur)) {
            return $defaut;
        }
        $entier = filter_var(trim((string) $valeur), \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE);

        return $entier ?? $defaut;
    }
}
