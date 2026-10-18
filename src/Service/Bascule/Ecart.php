<?php

namespace App\Service\Bascule;

/**
 * Une différence entre la base de l'imprimerie et le schéma de la migration
 * de base (docs/migration-de-base.md), décrite sans objet Doctrine pour
 * pouvoir être classée et testée simplement.
 *
 * Une colonne est décrite par un tableau : type (nom Doctrine : integer,
 * string, decimal…), longueur, precision, echelle, obligatoire, defaut.
 * Un index par : colonnes, unique. Une clé étrangère par : colonnes,
 * parente, colonnesParentes.
 */
final class Ecart
{
    public const TABLE_AJOUTEE = 'table_ajoutee';
    public const TABLE_EN_TROP = 'table_en_trop';
    public const COLONNE_AJOUTEE = 'colonne_ajoutee';
    public const COLONNE_EN_TROP = 'colonne_en_trop';
    public const COLONNE_MODIFIEE = 'colonne_modifiee';
    public const INDEX_AJOUTE = 'index_ajoute';
    public const INDEX_RETIRE = 'index_retire';
    public const INDEX_RENOMME = 'index_renomme';
    public const CLE_AJOUTEE = 'cle_ajoutee';
    public const CLE_RETIREE = 'cle_retiree';
    /** Pas un écart de schéma : une conversion de données impossible à faire sans risque (ConversionsDonnees). */
    public const DONNEES = 'donnees';

    /**
     * @param array<string, mixed> $avant ce qu'il y a dans la base de l'imprimerie
     * @param array<string, mixed> $apres ce que la migration de base attend
     */
    public function __construct(
        public readonly string $genre,
        public readonly string $table,
        public readonly ?string $objet = null,
        public readonly array $avant = [],
        public readonly array $apres = [],
    ) {
    }

    /** « paiements.mode », « paiements » pour une table. */
    public function cible(): string
    {
        return $this->objet === null ? $this->table : $this->table.'.'.$this->objet;
    }
}
