<?php

namespace App\Service\Synchro;

use App\Entity\BonLivraison;
use App\Entity\BonLivraisonLigne;
use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandeDetailFichier;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetailFinition;
use App\Entity\DevisDetails;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\ProduitClient;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\ReclamationClient;
use App\Entity\ReclamationClientPhoto;
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
 * « numero » : le champ de numéro d'une fiche créée en ligne reçoit le
 * préfixe W- (web) sur le site, pour ne jamais prendre un numéro du local.
 * « aLaCreation » : valeur fabriquée quand une copie est créée (champ
 * obligatoire qui ne part pas).
 * « imposeAuLocal » : valeurs forcées quand le local reçoit une création
 * faite en ligne (un paiement saisi en ligne reste provisoire).
 * « remontees » : les seuls changements faits en ligne sur une fiche du
 * local que le local applique, car ce sont des décisions du client (accepter
 * un devis envoyé, ses préférences SMS). Champ => null (toute valeur) ou
 * [valeur actuelle au local => valeurs permises, « * » pour toute valeur
 * non vide]. Le reste est un conflit.
 *
 * « enfants » : petites données sans identifiant public (finitions d'une
 * ligne, suivi du BAT) envoyées dans la donnée parente et mises à jour en
 * bloc. « filtres » : fonction qui réduit un champ avant l'envoi.
 *
 * L'ordre du tableau est l'ordre de chargement initial : une donnée
 * vient après celles qu'elle référence. « facultatives » : liens vers une
 * donnée chargée plus loin ; tant qu'elle n'est pas arrivée, le lien
 * reste vide au lieu de faire attendre la donnée.
 */
final class CatalogueSynchro
{
    public const DESCENDANT = 'descendant';
    public const DEUX = 'deux';

    /**
     * @return array<string, array{classe: class-string, sens: string, libelle: string, titre: list<string>, champs: list<string>, relations: array<string, string>, listes: array<string, string>, numero?: string, aLaCreation?: array<string, callable>, imposeAuLocal?: array<string, mixed>, pret?: callable, remontees?: array<string, ?array<string, list<string>>>, facultatives?: list<string>, enfants?: array<string, array{classe: class-string, parent: string, champs: list<string>, relations: array<string, string>, filtres?: array<string, callable>}>}>
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
                // Finitions proposées avec leur prix de vente : le client commande en ligne avec les mêmes.
                'enfants' => ['configurationFinitions' => [
                    'classe' => ProduitConfigurationFinition::class, 'parent' => 'produitConfiguration',
                    'champs' => ['obligatoire', 'selectionneeParDefaut', 'payante', 'active', 'prix', 'modeCalcul', 'quantiteMinimale', 'quantiteMaximale', 'ordre', 'description'],
                    'relations' => ['finition' => 'finition'],
                ]],
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
                'relations' => [], 'listes' => [], 'numero' => 'code',
                'remontees' => ['recevoirSms' => null, 'consentementMarketingLe' => null],
            ],
            'commande' => [
                'classe' => Commandes::class, 'sens' => self::DEUX, 'libelle' => 'Commande', 'titre' => ['numero'],
                // observation (note interne), agents et bonus de plafond restent au local.
                'champs' => [
                    'numero', 'dateCommande', 'dateLivraison', 'recupereParClient', 'modifieLe', 'remise', 'tva', 'totalHt', 'totalTtc',
                    'montantApayer', 'deleted', 'statut', 'etat', 'statutPaiement', 'resteAPayer', 'totalPaye', 'canal', 'modeLivraison', 'adresseLivraison',
                    // Compte d'entreprise (maquette 19) : qui a demandé, qui a validé.
                    'bonCommande', 'validationEntreprise', 'demandeePar', 'valideePar', 'motifRefusEntreprise',
                ],
                'relations' => ['clients' => 'client'], 'listes' => [], 'numero' => 'numero',
                // Décision du valideur de l'entreprise, prise en ligne.
                'remontees' => [
                    'validationEntreprise' => [Commandes::VALIDATION_A_FAIRE => [Commandes::VALIDATION_ACCEPTEE, Commandes::VALIDATION_REFUSEE]],
                    'valideePar' => ['' => ['*']],
                    'motifRefusEntreprise' => ['' => ['*']],
                ],
            ],
            'commande_ligne' => [
                'classe' => CommandesDetails::class, 'sens' => self::DEUX, 'libelle' => 'Ligne de commande', 'titre' => ['designation'],
                // coutRevient, temps, machine, rebuts et notes d'atelier restent au local.
                'champs' => [
                    'quantiteDemandee', 'uniteDemandee', 'modeConfiguration', 'designation', 'largeur', 'longueur', 'surface', 'quantite', 'prixUnitaire',
                    'remise', 'tva', 'totalHt', 'totalTtc', 'profilCouleurs', 'resolution', 'grammage', 'epaisseur', 'rectoVerso', 'nombreFaces', 'laminage',
                    'oeillets', 'decoupe', 'pliage', 'emballage', 'batValide', 'etat', 'statutProduction', 'productionDebuteLe', 'productionTermineeLe',
                    'quantiteLivree', 'modeSaisie', 'modeCalcul', 'typeLigne', 'demandeClient', 'prePresseNecessaire',
                ],
                // produitClient : ligne commandée depuis « Mes produits » (le produit du client vient après les BAT).
                'relations' => ['commande' => 'commande', 'produit' => 'produit', 'produitConfiguration' => 'configuration', 'typeImpression' => 'type_impression', 'support' => 'support', 'format' => 'format', 'produitClient' => 'produit_client'],
                'facultatives' => ['produitClient'],
                'listes' => [],
                'enfants' => ['finitions' => self::finitionsDeLigne(CommandeDetailFinition::class, 'commandeDetail')],
            ],
            'fichier' => [
                'classe' => CommandeDetailFichier::class, 'sens' => self::DEUX, 'libelle' => 'Fichier', 'titre' => ['nomOriginal'],
                // Les octets ne partent que pour les BAT (FichiersSynchro) ; chemin, notes et montage restent au local.
                // Les fichiers envoyés par le client en ligne remontent (fiche ici, octets par FichiersSynchro::recuperer).
                'champs' => [
                    'nomOriginal', 'nomStockage', 'typeMime', 'taille', 'statut', 'origine', 'etat', 'actif', 'version', 'face', 'designation',
                    'creeLe', 'termineLe', 'morceauxRecus', 'nombreMorceaux', 'logo', 'analyse', 'empreinte',
                ],
                'relations' => ['commandeDetail' => 'commande_ligne', 'client' => 'client'], 'listes' => [],
                // Le jeton d'envoi ne part pas : la copie en reçoit un neuf.
                'aLaCreation' => ['jetonUpload' => static fn (): string => bin2hex(random_bytes(32))],
                // Un envoi pas fini ne part pas encore.
                'pret' => static fn (CommandeDetailFichier $f): bool => $f->estUploadTermine(),
                // En ligne, le client retire son fichier, le met dans une commande, et le contrôle est refait à la taille commandée.
                'remontees' => ['actif' => ['1' => ['0']], 'analyse' => null, 'commandeDetail' => ['' => ['*']]],
            ],
            'controle' => [
                'classe' => ControlePrePresse::class, 'sens' => self::DESCENDANT, 'libelle' => 'BAT', 'titre' => ['statut'],
                // Seul l'état du BAT part : les contrôles, anomalies et noms de l'atelier restent au local.
                'champs' => ['statut', 'batNecessaire', 'batValide', 'correctionNecessaire', 'creeLe', 'valideLe'],
                'relations' => ['commandeDetail' => 'commande_ligne'], 'listes' => ['fichiers' => 'fichier'],
                'enfants' => ['conception' => [
                    'classe' => Conception::class, 'parent' => 'controle',
                    // Ni graphiste, ni temps, ni erreurs, ni points.
                    'champs' => ['creeLe', 'versions', 'premierBatLe', 'dernierBatLe', 'valideLe', 'evenements'],
                    'relations' => [],
                    'filtres' => ['evenements' => [Conception::class, 'evenementsPublics']],
                ]],
            ],
            // « Mes produits » (docs/mes-produits.md) : produits déjà validés que le client recommande en ligne.
            // Machine, coût de revient et ligne d'origine restent au local.
            'produit_client' => [
                'classe' => ProduitClient::class, 'sens' => self::DESCENDANT, 'libelle' => 'Produit du client', 'titre' => ['reference', 'nom'],
                'champs' => ['reference', 'nom', 'largeur', 'hauteur', 'uniteVente', 'prixUnitaire', 'quantiteMinimum', 'tailleLot', 'posesParFeuille', 'arrondiSuperieurImpose', 'actif', 'creeLe'],
                'relations' => ['client' => 'client', 'produit' => 'produit', 'support' => 'support', 'fichier' => 'fichier', 'batValide' => 'controle'],
                'listes' => ['finitions' => 'finition'],
            ],
            'devis' => [
                'classe' => Devis::class, 'sens' => self::DESCENDANT, 'libelle' => 'Devis', 'titre' => ['numero'],
                'champs' => [
                    'numero', 'emetteur', 'dateDevis', 'remise', 'tva', 'totalHt', 'totalTtc', 'montantApayer', 'deleted', 'statut', 'etat',
                    'tokenAuthenticite', 'facturerAUnTiers', 'nomFacturation', 'adresseFacturation', 'telephoneFacturation', 'emailFacturation',
                ],
                'relations' => ['clients' => 'client', 'commande' => 'commande'], 'listes' => [], 'numero' => 'numero',
                // Le jeton du lien PDF peut naître en ligne (le client ouvre son devis) : il est gardé.
                'remontees' => ['statut' => [Devis::STATUT_ENVOYE => [Devis::STATUT_ACCEPTE]], 'tokenAuthenticite' => ['' => ['*']]],
            ],
            'devis_ligne' => [
                'classe' => DevisDetails::class, 'sens' => self::DESCENDANT, 'libelle' => 'Ligne de devis', 'titre' => ['designation'],
                'champs' => [
                    'modeConfiguration', 'designation', 'largeur', 'longueur', 'surface', 'quantite', 'prixUnitaire', 'remise', 'tva', 'totalHt', 'totalTtc',
                    'profilCouleurs', 'resolution', 'grammage', 'epaisseur', 'rectoVerso', 'nombreFaces', 'laminage', 'oeillets', 'decoupe', 'pliage',
                    'emballage', 'typeLigne', 'modeSaisie', 'batValide', 'etat', 'modeCalcul',
                ],
                'relations' => ['devis' => 'devis', 'produit' => 'produit', 'produitConfiguration' => 'configuration', 'typeImpression' => 'type_impression', 'support' => 'support', 'format' => 'format'],
                'listes' => [],
                'enfants' => ['finitions' => self::finitionsDeLigne(DevisDetailFinition::class, 'devisDetail')],
            ],
            'facture' => [
                'classe' => Factures::class, 'sens' => self::DESCENDANT, 'libelle' => 'Facture', 'titre' => ['numero'],
                // Comptabilisation, majoration et fichier PDF du serveur restent au local.
                'champs' => [
                    'numero', 'typeDocument', 'emetteur', 'dateFacture', 'dateEcheance', 'totalHt', 'remise', 'tva', 'montantTva', 'totalTtc', 'montantPaye',
                    'resteAPayer', 'statutPaiement', 'etat', 'tokenAuthenticite', 'codeSuivi', 'facturerAUnTiers', 'nomFacturation', 'adresseFacturation',
                    'telephoneFacturation', 'emailFacturation', 'createdAt', 'updatedAt',
                ],
                'relations' => ['commande' => 'commande'], 'listes' => [],
                'remontees' => ['tokenAuthenticite' => ['' => ['*']]],
            ],
            'paiement' => [
                'classe' => Paiements::class, 'sens' => self::DEUX, 'libelle' => 'Paiement', 'titre' => ['montant'],
                // Compte de trésorerie, mouvement, justificatif (fichier) et notes restent au local.
                'champs' => [
                    'montant', 'mode', 'statut', 'date', 'dateValidation', 'dateAnnulation', 'reference', 'numeroCheque', 'banqueEmettrice', 'titulaireCheque',
                    'dateEmissionCheque', 'dateEncaissementPrevue', 'motifRejet', 'fraisRetraitInclus', 'fondsSoutienInclus', 'montantFraisRetrait', 'montantFondsSoutien',
                ],
                'relations' => ['commande' => 'commande', 'facture' => 'facture'], 'listes' => [],
                'imposeAuLocal' => ['statut' => Paiements::STATUT_EN_ATTENTE, 'dateValidation' => null, 'dateAnnulation' => null],
            ],
            // Réclamation écrite par le client en ligne (maquette 18) ; la réponse de l'atelier redescend.
            'reclamation' => [
                'classe' => ReclamationClient::class, 'sens' => self::DEUX, 'libelle' => 'Réclamation', 'titre' => ['numero', 'objet'],
                'champs' => ['numero', 'auteur', 'motif', 'objet', 'description', 'statut', 'reponse', 'reponduPar', 'reponduLe', 'closeLe', 'creeLe'],
                'relations' => ['client' => 'client', 'commande' => 'commande'], 'listes' => [], 'numero' => 'numero',
                'imposeAuLocal' => ['statut' => ReclamationClient::OUVERTE, 'reponse' => null, 'reponduPar' => null, 'reponduLe' => null, 'closeLe' => null],
            ],
            'reclamation_photo' => [
                'classe' => ReclamationClientPhoto::class, 'sens' => self::DEUX, 'libelle' => 'Photo de réclamation', 'titre' => [],
                'champs' => ['contenu', 'type', 'largeur', 'hauteur'],
                'relations' => ['reclamation' => 'reclamation'], 'listes' => [],
            ],
            // Où en est la livraison, pour l'espace client (livreur en route, livrée) et ses notifications.
            // Livreur, réceptionnaire, adresse, photos, signature, position, encaissement, stock et notes restent au local.
            'livraison' => [
                'classe' => BonLivraison::class, 'sens' => self::DESCENDANT, 'libelle' => 'Bon de livraison', 'titre' => ['numero'],
                'champs' => ['numero', 'statut', 'creeLe', 'valideLe', 'prevuLe', 'creneau', 'chargeLe', 'enRouteLe', 'arriveLe', 'livreLe'],
                'relations' => ['commande' => 'commande'], 'listes' => [],
                'enfants' => ['lignes' => [
                    'classe' => BonLivraisonLigne::class, 'parent' => 'bonLivraison',
                    'champs' => ['designation', 'quantiteCommandee', 'quantiteLivree', 'unite'],
                    'relations' => ['commandeDetail' => 'commande_ligne'],
                ]],
            ],
        ];
    }

    /**
     * Pour une donnée « enfant » (finition d'une ligne) : la ligne à renvoyer.
     */
    public static function parentDe(object $entite): ?object
    {
        $enfant = self::enfantDe($entite);
        if ($enfant === null) {
            return null;
        }
        $getter = 'get'.ucfirst($enfant['parent']);

        return $entite->$getter();
    }

    /** Définition « enfants » dont relève cette donnée, ou null. */
    public static function enfantDe(object $entite): ?array
    {
        foreach (self::types() as $type) {
            foreach ($type['enfants'] ?? [] as $enfant) {
                if ($entite instanceof $enfant['classe']) {
                    return $enfant;
                }
            }
        }

        return null;
    }

    /** Finition choisie sur une ligne : nom, prix appliqué et montant (pas de coût). */
    private static function finitionsDeLigne(string $classe, string $parent): array
    {
        return [
            'classe' => $classe, 'parent' => $parent,
            'champs' => ['nomFinition', 'obligatoire', 'prixApplique', 'modeCalcul', 'quantite', 'montant'],
            'relations' => ['finition' => 'finition'],
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
     * n'envoie que les types à double sens ou qui ont des remontées.
     */
    public static function envoye(string $type, bool $enLigne): bool
    {
        return isset(self::types()[$type]) && (!$enLigne || self::remonte($type));
    }

    /** Ce côté-ci accepte-t-il ce type venant de l'autre ? */
    public static function accepte(string $type, bool $enLigne): bool
    {
        return isset(self::types()[$type]) && ($enLigne || self::remonte($type));
    }

    /** Une fiche créée en ligne est-elle reprise au local ? */
    public static function creationAcceptee(string $type, bool $enLigne): bool
    {
        return $enLigne || (self::types()[$type]['sens'] ?? null) === self::DEUX;
    }

    /**
     * Le changement d'un champ fait en ligne est-il une remontée permise ?
     * Valeurs comparées sous leur forme envoyée (chaîne).
     */
    public static function remonteePermise(string $type, string $champ, ?string $auLocal, ?string $enLigne): bool
    {
        $regles = self::types()[$type]['remontees'] ?? [];
        if (!\array_key_exists($champ, $regles)) {
            return false;
        }
        if ($regles[$champ] === null) {
            return true;
        }

        $permises = $regles[$champ][(string) $auLocal] ?? [];

        return \in_array($enLigne, $permises, true) || ($enLigne !== null && $enLigne !== '' && \in_array('*', $permises, true));
    }

    private static function remonte(string $type): bool
    {
        $t = self::types()[$type];

        return $t['sens'] === self::DEUX || ($t['remontees'] ?? []) !== [];
    }

    /** Les champs dont un changement doit partir. */
    public static function champsSuivis(string $type): array
    {
        $t = self::types()[$type];

        return [...$t['champs'], ...array_keys($t['relations'])];
    }
}
