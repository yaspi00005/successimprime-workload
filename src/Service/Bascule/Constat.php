<?php

namespace App\Service\Bascule;

/**
 * Un écart classé : ce que la correction ferait et si c'est sans risque.
 *
 * - AJOUT : la base reçoit ce qui lui manque, rien d'existant ne change ;
 * - SANS_PERTE : quelque chose change, mais les données ont été lues et
 *   rien ne peut se perdre (table vide, colonne plus large…) ;
 * - BLOQUANT : une donnée pourrait se perdre ou il faut choisir une valeur :
 *   la correction refuse de démarrer.
 */
final class Constat
{
    public const AJOUT = 'ajout';
    public const SANS_PERTE = 'sans_perte';
    public const BLOQUANT = 'bloquant';

    /** Lignes qui reçoivent la valeur de reprise : celles sans valeur. */
    public const SANS_VALEUR = 'sans_valeur';

    /** Identifiants publics jamais tirés (NULL ou seize octets à zéro). */
    public const IDENTIFIANT_VIDE = 'identifiant_vide';

    /**
     * @param ?string $reprise        expression SQL donnée aux lignes existantes
     *                                (colonne obligatoire ajoutée, identifiant vide)
     * @param string  $repriseLignes  SANS_VALEUR ou IDENTIFIANT_VIDE
     * @param bool    $lienNonVerifie lien créé sans contrôler les lignes orphelines
     *                                déjà présentes (option --garder-orphelins)
     */
    public function __construct(
        public readonly Ecart $ecart,
        public readonly string $niveau,
        public readonly string $message,
        public readonly ?string $reprise = null,
        public readonly string $repriseLignes = self::SANS_VALEUR,
        public readonly bool $lienNonVerifie = false,
    ) {
    }

    public function estBloquant(): bool
    {
        return self::BLOQUANT === $this->niveau;
    }
}
