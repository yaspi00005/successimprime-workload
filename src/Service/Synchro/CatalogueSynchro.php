<?php

namespace App\Service\Synchro;

use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;

/**
 * Ce qui passe entre l'ERP local et l'application en ligne, et dans quel
 * sens (docs/synchronisation.md).
 *
 * Chaque champ est listé un par un : un champ absent ne quitte jamais sa
 * machine. Les coûts, marges, comptes, salaires et notes internes ne sont
 * donc jamais envoyés, même si une colonne est ajoutée plus tard.
 *
 * Sens :
 *  - descendant : l'ERP local est maître, le site reçoit (catalogue) ;
 *  - deux       : descendant, et les fiches CRÉÉES en ligne remontent.
 *                 Une modification faite en ligne d'une fiche du local
 *                 n'est pas appliquée : elle est notée comme conflit.
 *
 * L'ordre du tableau est l'ordre de chargement initial : une donnée
 * vient après celles qu'elle référence.
 */
final class CatalogueSynchro
{
    public const DESCENDANT = 'descendant';
    public const DEUX = 'deux';

    /**
     * @return array<string, array{classe: class-string, sens: string, libelle: string, titre: list<string>, champs: list<string>, relations: array<string, string>, listes: array<string, string>}>
     */
    public static function types(): array
    {
        return [
            'categorie' => [
                'classe' => CategorieProduit::class, 'sens' => self::DESCENDANT, 'libelle' => 'Catégorie', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre'],
                'relations' => [], 'listes' => [],
            ],
            'type_impression' => [
                'classe' => TypesImpression::class, 'sens' => self::DESCENDANT, 'libelle' => 'Type d\'impression', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre'],
                'relations' => [], 'listes' => [],
            ],
            'finition' => [
                'classe' => Finition::class, 'sens' => self::DESCENDANT, 'libelle' => 'Finition', 'titre' => ['nom'],
                // coutUnitaire est un coût de revient : il reste au local.
                'champs' => ['nom', 'description', 'publie', 'ordre'],
                'relations' => [], 'listes' => ['typesImpressions' => 'type_impression'],
            ],
            'format' => [
                'classe' => Format::class, 'sens' => self::DESCENDANT, 'libelle' => 'Format', 'titre' => ['nom'],
                'champs' => ['nom', 'largeur', 'hauteur', 'unite', 'description', 'publie', 'ordre'],
                'relations' => [], 'listes' => ['typesImpressions' => 'type_impression'],
            ],
            'support' => [
                'classe' => Supports::class, 'sens' => self::DESCENDANT, 'libelle' => 'Support', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre'],
                'relations' => [], 'listes' => ['typesImpressions' => 'type_impression', 'formats' => 'format', 'finitions' => 'finition'],
            ],
            'produit' => [
                'classe' => Produits::class, 'sens' => self::DESCENDANT, 'libelle' => 'Produit', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre', 'code', 'prixBase', 'prixB2B', 'personnalisable', 'actif', 'modeCalcul'],
                'relations' => ['categorieProduit' => 'categorie'],
                'listes' => ['typesImpressions' => 'type_impression', 'supports' => 'support', 'formats' => 'format', 'finitions' => 'finition'],
            ],
            'configuration' => [
                'classe' => ProduitConfiguration::class, 'sens' => self::DESCENDANT, 'libelle' => 'Configuration produit', 'titre' => ['description'],
                'champs' => ['active', 'ordre', 'description', 'prixBase', 'modeCalcul', 'modeDimension', 'largeurDefaut', 'longueurDefaut', 'quantiteMinimale', 'quantiteMaximale', 'prixB2B'],
                'relations' => ['produit' => 'produit', 'typeImpression' => 'type_impression', 'support' => 'support', 'format' => 'format'],
                'listes' => [],
            ],
            'client' => [
                'classe' => Clients::class, 'sens' => self::DEUX, 'libelle' => 'Client', 'titre' => ['raisonSociale', 'prenom', 'nom'],
                // observation (note interne), suiviPar et la remise habituelle restent au local.
                'champs' => [
                    'code', 'raisonSociale', 'nom', 'prenom', 'telephone', 'telephone2', 'email', 'adresse', 'ville', 'commune', 'quartier',
                    'latitude', 'longitude', 'repere', 'nif', 'rccm', 'typeClient', 'typeCompte', 'plafondCredit', 'soldeCredit',
                    'createdAt', 'updatedAt', 'statut', 'recevoirSms', 'consentementMarketingLe', 'delaiPaiement', 'acompte',
                    'moyenPrefere', 'entiteFacturation',
                ],
                'relations' => [], 'listes' => [],
            ],
        ];
    }

    /** Clé du type d'une entité, ou null si elle ne se synchronise pas. */
    public static function typeDe(object $entite): ?string
    {
        foreach (self::types() as $cle => $type) {
            if ($entite instanceof $type['classe']) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * Ce côté-ci doit-il envoyer ce type ? Le local envoie tout ; le site
     * n'envoie que les types à double sens.
     */
    public static function envoye(string $type, bool $enLigne): bool
    {
        $sens = self::types()[$type]['sens'] ?? null;

        return $sens !== null && (!$enLigne || $sens === self::DEUX);
    }

    /** Ce côté-ci accepte-t-il ce type venant de l'autre ? */
    public static function accepte(string $type, bool $enLigne): bool
    {
        $sens = self::types()[$type]['sens'] ?? null;

        return $sens !== null && ($enLigne || $sens === self::DEUX);
    }

    /** Les champs dont un changement doit partir. */
    public static function champsSuivis(string $type): array
    {
        $t = self::types()[$type];

        return [...$t['champs'], ...array_keys($t['relations'])];
    }
}
