<?php

namespace App\Service\Bascule;

/**
 * Une conversion de données : une ancienne mise à jour qui changeait le
 * sens des données existantes (pas seulement le schéma), que la base de l'imprimerie
 * n'a pas reçue et que la bascule rejoue (docs/migration-de-base.md,
 * « Conversions de données »).
 *
 * - A_FAIRE : rejouée par --corriger, après la sauvegarde ;
 * - BLOQUANT : impossible à rejouer sans risque, la correction refuse ;
 * - A_REVOIR : copie de la structure sans données, décidé sur la vraie base.
 *
 * AVANT : avant le changement de schéma (la colonne a encore l'ancien sens) ;
 * APRES : après, quand la colonne ajoutée existe.
 */
final class Conversion
{
    public const A_FAIRE = 'a_faire';
    public const BLOQUANT = 'bloquant';
    public const A_REVOIR = 'a_revoir';

    public const AVANT = 'avant';
    public const APRES = 'apres';

    public const REMISE = 'remise';
    public const VENDABLE = 'vendable';
    public const CONSOMMABLE = 'consommable';
    public const TYPE_COMPTE = 'type_compte';
    public const AMORTISSEMENT = 'amortissement';

    public function __construct(
        public readonly string $code,
        public readonly string $table,
        public readonly string $colonne,
        public readonly string $etat,
        /** Explication en français : des nombres de lignes, jamais une valeur ni un nom. */
        public readonly string $message,
        public readonly string $moment = self::AVANT,
        public readonly int $lignes = 0,
    ) {
    }

    /** « commandes_details.remise » */
    public function cible(): string
    {
        return $this->table.'.'.$this->colonne;
    }

    public function aFaire(): bool
    {
        return self::A_FAIRE === $this->etat;
    }
}
