<?php

namespace App\Service\Synchro;

use App\Entity\AssistantConversation;
use App\Entity\AssistantMessage;
use App\Entity\BonLivraison;
use App\Entity\BonLivraisonLigne;
use App\Entity\CategorieProduit;
use App\Entity\Clients;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandeDetailFichier;
use App\Entity\CompteFidelite;
use App\Entity\Conception;
use App\Entity\DemandeConception;
use App\Entity\DepotSoldeClient;
use App\Entity\CreationAtelier;
use App\Entity\DemandeDevis;
use App\Entity\ControlePrePresse;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetailFinition;
use App\Entity\DevisDetails;
use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\InvitationSondage;
use App\Entity\AgentMessagerie;
use App\Entity\ConversationClient;
use App\Entity\MessageClient;
use App\Entity\ModeleAtelier;
use App\Entity\MouvementCreditIa;
use App\Entity\MouvementFidelite;
use App\Entity\MouvementSoldeClient;
use App\Entity\NoteArticleSondage;
use App\Entity\ProduitClient;
use App\Entity\PromoReveil;
use App\Entity\Promotion;
use App\Entity\CodePromo;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\PropositionClient;
use App\Entity\ReclamationClient;
use App\Entity\ReclamationClientPhoto;
use App\Entity\ReponseSondage;
use App\Entity\Societe;
use App\Entity\Sondage;
use App\Entity\SupportConception;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Service\Parametres\CatalogueParametres;

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
 *  - ascendant  : créé en ligne, envoyé au local, qui ne renvoie rien
 *                 (demande d'un visiteur du site : le site ne la montre
 *                 plus, seule l'équipe la lit au local).
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
    public const ASCENDANT = 'ascendant';

    /**
     * Réglages (tuiles de Paramètres) dont le site en ligne a besoin, champ
     * par champ. Ils partent du local quand on les change, comme un geste ;
     * les autres champs et les autres tuiles ne quittent jamais le local.
     */
    public const REGLAGES_PUBLICS = [
        // Délais et majoration express : la nouvelle commande en ligne les montre et les applique ; corrections de BAT gratuites : suivi du BAT.
        'espace_client' => ['code_sms', 'commande_en_ligne', 'paiement_en_ligne', 'delai_standard', 'express', 'delai_express', 'majoration_express', 'heure_limite', 'corrections_bat_gratuites'],
        // Taux des frais Orange Money (tenus dans ParametresPaiement) : le paiement de l'espace client les applique.
        // Moyens acceptés, acompte par défaut, seuil et coordonnées du virement : « Factures et paiements » et choix « Acompte » de la commande en ligne.
        'paiements' => ['orange_money', 'tauxFondsSoutien', 'tauxFraisRetrait', 'especes', 'virement', 'virement_seuil', 'acompte', 'virement_coordonnees'],
        'taxes' => ['tva_par_defaut', 'taux_tva'],
        // Jours ouvrés et fériés : date « prête le… » et créneaux de livraison de la commande en ligne.
        'horaires' => ['jours', 'ouverture', 'fermeture', 'feries'],
        // Frais de « Livraison à Bamako » de la commande en ligne (un des paliers). Les objets restent au local.
        'saisie' => ['paliers_livraison', 'livraison_en_ligne'],
        'site_contact' => ['lien_plan'],
        // Le coût d'une image chez le fournisseur (cout_image) reste au local.
        'conception' => ['ia_active', 'prix_image', 'finalisation', 'packs', 'plafond_jour', 'limite_heure'],
        // Atelier 3D : les paliers et la seconde face servent au prix calculé en ligne (docs/atelier-3d.md).
        'atelier_3d' => ['palier1_quantite', 'palier1_remise', 'palier2_quantite', 'palier2_remise', 'face_en_plus', 'paliers_b2b'],
        // Pièces au m² : le site compte les pièces avec le même espacement (docs/saisie-devis-commandes.md).
        'pieces_m2' => ['espacement_mm'],
        // Corbeille : le site en ligne garde les suppressions de l'espace client aussi longtemps que le local.
        'corbeille' => ['duree'],
        // L'assistant tourne en ligne : tous ses réglages y servent (docs/assistant-client.md).
        // Messages (docs/messagerie-clients.md) : « Réponse en général sous 30 min » et réouverture pendant 7 jours.
        'messagerie' => ['premiere_minutes', 'reouverture_jours'],
        'assistant' => ['actif', 'message_accueil', 'limite_jour', 'whatsapp', 'delais', 'modele', 'effort', 'taux_usd', 'prix_entree', 'prix_sortie', 'prix_cache_lecture', 'prix_cache_ecriture'],
    ];

    /** Geste « réglages du local » (local → site) et « changement proposé » (admin en ligne → local). */
    public const ACTION_REGLAGES = 'reglages';
    public const ACTION_PROPOSITION = 'reglage_propose';

    /**
     * @return array<string, array{classe: class-string, sens: string, libelle: string, titre: list<string>, champs: list<string>, relations: array<string, string>, listes: array<string, string>, numero?: string, aLaCreation?: array<string, callable>, imposeAuLocal?: array<string, mixed>, pret?: callable, remontees?: array<string, ?array<string, list<string>>>, facultatives?: list<string>, uniqueSur?: list<string>, enfants?: array<string, array{classe: class-string, parent: string, champs: list<string>, relations: array<string, string>, filtres?: array<string, callable>}>}>
     */
    public static function types(): array
    {
        return [
            // Sociétés (docs/societes.md) : de quoi rattacher en ligne les entreprises et les documents.
            // RCCM, NIF et adresse de la société restent au local.
            'societe' => [
                'classe' => Societe::class, 'sens' => self::DESCENDANT, 'libelle' => 'Société', 'titre' => ['raisonSociale'],
                'champs' => ['code', 'raisonSociale', 'parDefaut', 'actif'],
                'relations' => [], 'listes' => [],
            ],
            // Coordonnées publiques (site vitrine, contact WhatsApp, numéro marchand Orange Money).
            // Banque, numérotation, logos et signature restent au local.
            'entreprise' => [
                'classe' => Entreprise::class, 'sens' => self::DESCENDANT, 'libelle' => 'Entreprise', 'titre' => ['code'],
                // conditionsPaiement : bloc « NB » des factures, que le PDF montre aussi en ligne.
                'champs' => ['code', 'raisonSociale', 'nomCommercial', 'mention', 'activite', 'adresse', 'telephone', 'email', 'siteWeb', 'rccm', 'nif', 'marchandOrangeMoney', 'couleurs', 'actif', 'ordre', 'conditionsPaiement'],
                'relations' => ['societe' => 'societe'], 'listes' => [],
            ],
            // Contenu du site vitrine (Réglages › Site vitrine), saisi au local seulement.
            // Les images d'abord : pages, réalisations, conseils, partenaires et équipe les citent.
            // Image réencodée (WebP, ou PNG, 300 Ko au plus), revérifiée à l'arrivée (ImageSite::verifier) ;
            // l'original envoyé reste au local.
            'site_image' => [
                'classe' => \App\Entity\ImageSite::class, 'sens' => self::DESCENDANT, 'libelle' => 'Image du site', 'titre' => ['texteAlternatif'],
                'champs' => ['contenu', 'type', 'largeur', 'hauteur', 'empreinte', 'usage', 'texteAlternatif', 'ordre', 'creeLe'],
                'relations' => [], 'listes' => [],
            ],
            'site_page' => [
                'classe' => \App\Entity\PageSite::class, 'sens' => self::DESCENDANT, 'libelle' => 'Page du site', 'titre' => ['titre'],
                'champs' => ['slug', 'titre', 'accroche', 'description', 'corps', 'publie', 'ordre', 'modifieLe'],
                'relations' => ['image' => 'site_image'], 'listes' => [],
            ],
            // Le nom du client ne part qu'avec son accord. La catégorie part par son identifiant
            // public (categorieRef) : la réalisation n'attend pas les catégories, chargées plus loin.
            'site_realisation' => [
                'classe' => \App\Entity\Realisation::class, 'sens' => self::DESCENDANT, 'libelle' => 'Réalisation', 'titre' => ['titre'],
                'champs' => ['titre', 'categorieRef', 'description', 'nomClient', 'accordClient', 'publie', 'dateRealisation'],
                'relations' => [], 'listes' => ['images' => 'site_image'],
                'filtres' => ['nomClient' => [\App\Entity\Realisation::class, 'nomClientPublic']],
            ],
            // Sans l'accord du client, ni le texte ni le nom de l'avis ne quittent le local.
            'site_avis' => [
                'classe' => \App\Entity\AvisClient::class, 'sens' => self::DESCENDANT, 'libelle' => 'Avis client', 'titre' => ['nomAffiche'],
                'champs' => ['nomAffiche', 'texte', 'note', 'source', 'dateAvis', 'accordClient', 'publie'],
                'relations' => [], 'listes' => [],
                'filtres' => ['nomAffiche' => [\App\Entity\AvisClient::class, 'textePublic'], 'texte' => [\App\Entity\AvisClient::class, 'textePublic']],
            ],
            'site_article' => [
                'classe' => \App\Entity\ArticleConseil::class, 'sens' => self::DESCENDANT, 'libelle' => 'Conseil', 'titre' => ['titre'],
                'champs' => ['titre', 'slug', 'rubrique', 'resume', 'corps', 'publieLe', 'publie', 'modifieLe'],
                'relations' => ['image' => 'site_image'], 'listes' => [],
            ],
            'site_partenaire' => [
                'classe' => \App\Entity\Partenaire::class, 'sens' => self::DESCENDANT, 'libelle' => 'Partenaire', 'titre' => ['nom'],
                'champs' => ['nom', 'lien', 'ordre', 'visible', 'accordObtenu'],
                'relations' => ['logo' => 'site_image'], 'listes' => [],
            ],
            // « Notre équipe » (page « Qui sommes-nous ? ») : nom affiché, poste, photo, ordre, visible,
            // accord, et pour le gérant son mot (« Le mot du gérant »). Sans l'accord de la personne, son
            // nom, son poste et son mot partent vides, et elle n'a pas de photo (ContenuSite). Rien d'autre
            // sur elle : elle n'est liée à aucune fiche du personnel.
            'site_equipe' => [
                'classe' => \App\Entity\MembreEquipe::class, 'sens' => self::DESCENDANT, 'libelle' => 'Personne de l\'équipe', 'titre' => ['nom'],
                'champs' => ['nom', 'poste', 'ordre', 'visible', 'accordPersonne', 'gerant', 'motGerant', 'aussiDansEquipe'],
                'relations' => ['photo' => 'site_image'], 'listes' => [],
                'filtres' => ['nom' => [\App\Entity\MembreEquipe::class, 'textePublic'], 'poste' => [\App\Entity\MembreEquipe::class, 'textePublic'], 'motGerant' => [\App\Entity\MembreEquipe::class, 'motPublic']],
            ],
            // « Nos actions sociales » : le site ne montre que les actions publiées avec un texte et une photo.
            'site_action_sociale' => [
                'classe' => \App\Entity\ActionSociale::class, 'sens' => self::DESCENDANT, 'libelle' => 'Action sociale', 'titre' => ['titre'],
                'champs' => ['titre', 'dateAction', 'lieu', 'texte', 'publie', 'ordre', 'modifieLe'],
                'relations' => [], 'listes' => ['images' => 'site_image'],
            ],
            // Règle de calcul des chiffres du site (ChiffresSite), référencement, lien « Nous soutenir » (SoutienSite).
            'site_reglage' => [
                'classe' => \App\Entity\ReglageSite::class, 'sens' => self::DESCENDANT, 'libelle' => 'Réglage du site', 'titre' => ['cle'],
                'champs' => ['cle', 'valeurs'],
                'relations' => [], 'listes' => [],
            ],
            // Bandes d'annonces du site public et de l'espace client (Réglages › Site vitrine › Annonces).
            'site_annonce' => [
                'classe' => \App\Entity\Annonce::class, 'sens' => self::DESCENDANT, 'libelle' => 'Annonce', 'titre' => ['texte'],
                'champs' => ['texte', 'lien', 'libelleLien', 'style', 'cible', 'debut', 'fin', 'publie', 'ordre', 'modifieLe'],
                'relations' => [], 'listes' => [],
            ],
            'categorie' => [
                'classe' => CategorieProduit::class, 'sens' => self::DESCENDANT, 'libelle' => 'Catégorie', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre'],
                'relations' => [], 'listes' => [],
            ],
            'type_impression' => [
                'classe' => TypesImpression::class, 'sens' => self::DESCENDANT, 'libelle' => 'Type d\'impression', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre', 'nomCommercial'],
                'relations' => [], 'listes' => [],
            ],
            'finition' => [
                'classe' => Finition::class, 'sens' => self::DESCENDANT, 'libelle' => 'Finition', 'titre' => ['nom'],
                // coutUnitaire est un coût de revient : il reste au local.
                // nomCommercial : aussi pour les finitions et les formats (Yaya, 18/10/2026).
                'champs' => ['nom', 'description', 'publie', 'ordre', 'nomCommercial'],
                'relations' => [], 'listes' => ['typesImpressions' => 'type_impression'],
            ],
            'format' => [
                'classe' => Format::class, 'sens' => self::DESCENDANT, 'libelle' => 'Format', 'titre' => ['nom'],
                'champs' => ['nom', 'largeur', 'hauteur', 'unite', 'description', 'publie', 'ordre', 'nomCommercial'],
                'relations' => [], 'listes' => ['typesImpressions' => 'type_impression'],
            ],
            'support' => [
                'classe' => Supports::class, 'sens' => self::DESCENDANT, 'libelle' => 'Support', 'titre' => ['nom'],
                'champs' => ['nom', 'description', 'publie', 'ordre', 'nomCommercial'],
                'relations' => [], 'listes' => ['typesImpressions' => 'type_impression', 'formats' => 'format', 'finitions' => 'finition'],
            ],
            'produit' => [
                'classe' => Produits::class, 'sens' => self::DESCENDANT, 'libelle' => 'Produit', 'titre' => ['nom'],
                // nomCommercial : « Autocollant » pour « Vinyle adhésif » (docs/noms-commerciaux.md), montré et cherché en ligne.
                'champs' => ['nom', 'description', 'publie', 'ordre', 'code', 'prixBase', 'prixB2B', 'personnalisable', 'actif', 'modeCalcul', 'nomCommercial'],
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
            // Atelier 3D (docs/atelier-3d.md) : articles proposés, réglés au local. Le prix reste celui de la configuration.
            'atelier_modele' => [
                'classe' => ModeleAtelier::class, 'sens' => self::DESCENDANT, 'libelle' => 'Article de l\'Atelier 3D', 'titre' => ['forme'],
                'champs' => ['forme', 'couleurs', 'tailles', 'zoneLargeurCm', 'zoneHauteurCm', 'actif', 'ordre'],
                'relations' => ['configuration' => 'configuration'], 'listes' => [],
            ],
            'client' => [
                'classe' => Clients::class, 'sens' => self::DEUX, 'libelle' => 'Client', 'titre' => ['raisonSociale', 'prenom', 'nom'],
                // observation (note interne), suiviPar (l'employé) et remiseHabituelle restent au local. Du
                // commercial qui suit le client, seul le nom affiché part (conseiller, carte « Votre conseiller(ère) »).
                'champs' => [
                    'code', 'raisonSociale', 'nom', 'prenom', 'telephone', 'telephone2', 'email', 'adresse', 'ville', 'commune', 'quartier',
                    'latitude', 'longitude', 'repere', 'nif', 'rccm', 'typeClient', 'typeCompte', 'plafondCredit', 'soldeCredit',
                    'createdAt', 'updatedAt', 'statut', 'recevoirSms', 'consentementMarketingLe', 'delaiPaiement', 'acompte',
                    'moyenPrefere', 'entiteFacturation', 'conseiller',
                ],
                'relations' => [], 'listes' => [], 'numero' => 'code',
                // E-mail donné par le titulaire dans son espace : pris seulement si la fiche du local n'en a pas.
                'remontees' => ['recevoirSms' => null, 'consentementMarketingLe' => null, 'email' => ['' => ['*']]],
            ],
            // Code d'accès à l'espace client donné par la boutique sur WhatsApp (docs/espace-client.md) :
            // seulement son empreinte et son échéance. Qui l'a donné reste au local ; son utilisation reste en ligne.
            'acces_espace' => [
                'classe' => \App\Entity\AccesEspaceClient::class, 'sens' => self::DESCENDANT, 'libelle' => 'Accès à l\'espace client', 'titre' => ['expireLe'],
                'champs' => ['empreinte', 'expireLe'],
                'relations' => ['client' => 'client'], 'listes' => [],
                // Un seul accès par client : recréé au local, il remplace celui du site.
                'uniqueSur' => ['client'],
            ],
            // Accès à l'espace client suspendu par la boutique (fiche client, docs/espace-client.md) : la fiche, le
            // numéro de la personne (vide : tout le compte), le début et la levée. Motif, nom et employés restent au local.
            'suspension_espace' => [
                'classe' => \App\Entity\SuspensionEspace::class, 'sens' => self::DESCENDANT, 'libelle' => 'Suspension de l\'espace client', 'titre' => ['telephone', 'suspenduLe'],
                'champs' => ['telephone', 'suspenduLe', 'leveeLe'],
                'relations' => ['client' => 'client'], 'listes' => [],
            ],
            // Promotions (docs/promotions.md) : ce que l'espace client montre à chacun des siennes. Le code promo
            // simple, la note interne et les auteurs restent au local ; une promotion à code simple ne paraît pas
            // en ligne. Elles viennent avant les commandes et les devis, qui citent leurs codes générés.
            'promotion' => [
                'classe' => Promotion::class, 'sens' => self::DESCENDANT, 'libelle' => 'Promotion', 'titre' => ['nom'],
                'champs' => [
                    'nom', 'avantage', 'valeur', 'debut', 'fin', 'segment', 'active', 'origine', 'quantiteMin', 'montantMin', 'utilisationsParClient',
                    // Avantage sur la commande, donné par un code généré (« Codes promo »).
                    'avantageCommande', 'valeurCommande', 'plafondCommande', 'minimumCommande', 'aCodes',
                ],
                'relations' => ['categorie' => 'categorie'], 'facultatives' => ['categorie'],
                'listes' => ['produits' => 'produit', 'clients' => 'client'],
                'filtres' => ['active' => [Promotion::class, 'activeEnLigne']],
            ],
            // Codes promo générés (docs/promotions.md, « Codes promo ») : pour vérifier en ligne le code que le
            // client tape. Jamais le code : son empreinte (HMAC-SHA256 avec SYNC_CLE), ses dates, ses limites et
            // ses utilisations comptées au local. Restent au local : le code, le lot, qui l'a créé ou désactivé.
            'code_promo' => [
                'classe' => CodePromo::class, 'sens' => self::DESCENDANT, 'libelle' => 'Code promo', 'titre' => ['type', 'expiration'],
                'champs' => ['empreinte', 'type', 'debut', 'expiration', 'utilisationsMax', 'utilisationsParClient', 'utilisations', 'active', 'creeLe'],
                'relations' => ['promotion' => 'promotion'], 'listes' => [],
                // Sans clé de synchronisation, pas d'empreinte (CodesPromo::empreinte) : rien à envoyer.
                'pret' => static fn (CodePromo $c): bool => '' !== $c->getEmpreinte(),
            ],
            'commande' => [
                'classe' => Commandes::class, 'sens' => self::DEUX, 'libelle' => 'Commande', 'titre' => ['numero'],
                // observation (note interne), agents et bonus de plafond restent au local.
                'champs' => [
                    'numero', 'dateCommande', 'dateLivraison', 'recupereParClient', 'modifieLe', 'remise', 'tva', 'totalHt', 'totalTtc',
                    'montantApayer', 'deleted', 'statut', 'etat', 'statutPaiement', 'resteAPayer', 'totalPaye', 'canal', 'modeLivraison', 'adresseLivraison',
                    // Compte d'entreprise (maquette 19) : qui a demandé, qui a validé.
                    'bonCommande', 'validationEntreprise', 'demandeePar', 'valideePar', 'motifRefusEntreprise',
                    // Solde d'ouverture (docs/import-donnees.md) : compté dans le solde de l'espace client, pas dans ses commandes.
                    'soldeOuverture',
                    // Code promo (docs/promotions.md) : le code tapé (en clair : c'est le client qui l'a tapé) et
                    // l'avantage, déjà dans les totaux. Le motif d'un code non retenu reste au local.
                    'codeSaisi', 'remiseCodePromo',
                    // Frais de livraison (déjà dans les totaux) et objet (docs/documents-livraisons.md).
                    'fraisLivraison', 'objet',
                    // Commande en ligne (docs/espace-client.md) : délai demandé (standard, express) et créneau de livraison.
                    'delaiProduction', 'creneauLivraison',
                ],
                // codePromo : le code retenu par la boutique, pour compter en ligne les utilisations du client.
                'relations' => ['clients' => 'client', 'societe' => 'societe', 'codePromo' => 'code_promo'], 'facultatives' => ['codePromo'], 'listes' => [], 'numero' => 'numero',
                // Seul l'import du local crée un solde d'ouverture : une commande venue du site n'en est jamais un.
                // Le code promo d'une commande passée en ligne n'est retenu que par la boutique, à la confirmation.
                // Les frais de livraison d'une commande passée en ligne viennent du réglage « Frais de livraison en ligne » :
                // contrôlés à l'arrivée (Synchro::verifierFraisEnLigne), la boutique peut les changer à la confirmation.
                'imposeAuLocal' => ['soldeOuverture' => false, 'codePromo' => null],
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
                    // Promotion de la ligne (docs/promotions.md) : son libellé et sa remise, déjà dans les totaux ;
                    // la part du code promo de la commande, aussi.
                    'libellePromotion', 'remisePromotion', 'montantPromotion', 'remiseCodePromo',
                    // Pièces au m² : l'espacement retenu, qui fait la surface facturée (docs/saisie-devis-commandes.md).
                    'espacementPiecesM2',
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
                    // Raison d'une retouche validée malgré une alerte, montrée au client avec le BAT (docs/prepresse-retouche.md).
                    'remarqueBat',
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
                // controleLe : « Fichier contrôlé » dans le suivi de la commande (docs/espace-client.md).
                'champs' => ['statut', 'batNecessaire', 'batValide', 'correctionNecessaire', 'creeLe', 'controleLe', 'valideLe'],
                'relations' => ['commandeDetail' => 'commande_ligne'], 'listes' => ['fichiers' => 'fichier'],
                'enfants' => ['conception' => [
                    'classe' => Conception::class, 'parent' => 'controle',
                    // Ni temps, ni erreurs, ni points ; du graphiste, seuls le prénom et le métier, dans l'historique public.
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
                    'codeSaisi', 'remiseCodePromo', 'fraisLivraison', 'objet',
                    // Personne du client à qui le devis s'adresse (« À l'attention de »).
                    'interlocuteur',
                    // Suivi du devis (docs/devis.md) : versions, validité, conditions, envoi, ouvertures, réponse.
                    // Les relances WhatsApp restent au local (elles partent d'ici).
                    'version', 'versions', 'validiteJours', 'dateValidite', 'delaiRealisation', 'acomptePourcentage',
                    'conditionSolde', 'conditionFichier', 'conditionGarantie', 'canauxEnvoi', 'envoyeLe', 'envoyePar',
                    'nombreVues', 'premiereVueLe', 'derniereVueLe', 'vuPar', 'reponseLe', 'reponduPar', 'motifRefus',
                    'demandeModification', 'demandeModificationLe',
                ],
                'relations' => ['clients' => 'client', 'commande' => 'commande', 'societe' => 'societe', 'codePromo' => 'code_promo'], 'facultatives' => ['codePromo'], 'listes' => [], 'numero' => 'numero',
                /*
                 * Décisions du client prises en ligne : il ouvre le devis (vu, nombre
                 * d'ouvertures), l'accepte (la commande créée en ligne remonte avec
                 * lui), le refuse avec un motif ou demande une modification. Le jeton
                 * du lien PDF peut naître en ligne : il est gardé.
                 */
                'remontees' => [
                    'statut' => [
                        Devis::STATUT_ENVOYE => [Devis::STATUT_VU, Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_CONVERTI],
                        Devis::STATUT_VU => [Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_CONVERTI],
                        Devis::STATUT_ACCEPTE => [Devis::STATUT_CONVERTI],
                    ],
                    'tokenAuthenticite' => ['' => ['*']],
                    'nombreVues' => null, 'premiereVueLe' => ['' => ['*']], 'derniereVueLe' => null, 'vuPar' => ['' => ['*']],
                    'reponseLe' => null, 'reponduPar' => null, 'motifRefus' => ['' => ['*']],
                    'demandeModification' => null, 'demandeModificationLe' => null,
                    'commande' => ['' => ['*']],
                ],
            ],
            'devis_ligne' => [
                'classe' => DevisDetails::class, 'sens' => self::DESCENDANT, 'libelle' => 'Ligne de devis', 'titre' => ['designation'],
                'champs' => [
                    'modeConfiguration', 'designation', 'largeur', 'longueur', 'surface', 'quantite', 'prixUnitaire', 'remise', 'tva', 'totalHt', 'totalTtc',
                    'profilCouleurs', 'resolution', 'grammage', 'epaisseur', 'rectoVerso', 'nombreFaces', 'laminage', 'oeillets', 'decoupe', 'pliage',
                    'emballage', 'typeLigne', 'modeSaisie', 'batValide', 'etat', 'modeCalcul',
                    'libellePromotion', 'remisePromotion', 'montantPromotion', 'remiseCodePromo',
                    'espacementPiecesM2',
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
                    'telephoneFacturation', 'emailFacturation', 'createdAt', 'updatedAt', 'fraisLivraison', 'objet',
                ],
                'relations' => ['commande' => 'commande', 'societe' => 'societe'], 'listes' => [],
                'remontees' => ['tokenAuthenticite' => ['' => ['*']]],
            ],
            'paiement' => [
                'classe' => Paiements::class, 'sens' => self::DEUX, 'libelle' => 'Paiement', 'titre' => ['montant'],
                // Compte de trésorerie, mouvement, justificatif (fichier) et notes restent au local.
                'champs' => [
                    'montant', 'mode', 'statut', 'date', 'dateValidation', 'dateAnnulation', 'reference', 'numeroCheque', 'banqueEmettrice', 'titulaireCheque',
                    'dateEmissionCheque', 'dateEncaissementPrevue', 'motifRejet', 'fraisRetraitInclus', 'fondsSoutienInclus', 'montantFraisRetrait', 'montantFondsSoutien',
                    // Numéro du reçu de la caisse (« R-000123 », de l'id du local) : le client le voit dans « Reçus » une fois validé.
                    'numeroRecu',
                ],
                'relations' => ['commande' => 'commande', 'facture' => 'facture', 'societe' => 'societe'], 'listes' => [],
                'filtres' => ['numeroRecu' => [Paiements::class, 'numeroRecuEnvoye']],
                'imposeAuLocal' => ['statut' => Paiements::STATUT_EN_ATTENTE, 'dateValidation' => null, 'dateAnnulation' => null, 'numeroRecu' => null],
            ],
            // Compte du client à la boutique (docs/finances.md, « Compte du client ») : le relevé de son solde
            // (Clients::soldeCredit, qui descend avec la fiche), tenu au local, lu en ligne par le payeur (« Mon
            // solde »). Le motif, l'employé, le paiement et le mouvement de trésorerie restent au local.
            'solde_mouvement' => [
                'classe' => MouvementSoldeClient::class, 'sens' => self::DESCENDANT, 'libelle' => 'Mouvement du solde client', 'titre' => ['type', 'montant'],
                'champs' => ['type', 'montant', 'soldeApres', 'mode', 'reference', 'creeLe'],
                'relations' => ['client' => 'client', 'commande' => 'commande'], 'listes' => [], 'facultatives' => ['commande'],
            ],
            // Dépôt Orange Money fait par le client depuis « Mon solde » : il monte « reçu » une fois l'argent
            // confirmé par Orange (jamais avant), la caisse le valide ou le refuse au local et la décision
            // redescend. Le motif du refus et l'employé restent au local.
            'solde_depot' => [
                'classe' => DepotSoldeClient::class, 'sens' => self::DEUX, 'libelle' => 'Dépôt sur le compte client', 'titre' => ['reference'],
                'champs' => ['auteur', 'montant', 'fondsSoutien', 'fraisRetrait', 'reference', 'txnid', 'statut', 'creeLe', 'recuLe', 'traiteLe'],
                'relations' => ['client' => 'client'], 'listes' => [],
                'pret' => static fn (DepotSoldeClient $d): bool => $d->estPret(),
                // Arrivé du site : toujours à valider par la caisse.
                'imposeAuLocal' => ['statut' => DepotSoldeClient::RECU, 'traiteLe' => null],
            ],
            // Rappels de paiement (docs/espace-client.md, « Factures et paiements ») : le client voit dans le détail
            // d'une facture les rappels envoyés et le prochain. D'une règle, seuls le délai, le type et l'état
            // partent ; son nom et son modèle de message restent au local (sur le site, une règle n'envoie rien).
            'regle_rappel' => [
                'classe' => \App\Entity\RegleRappelPaiement::class, 'sens' => self::DESCENDANT, 'libelle' => 'Règle de rappel de paiement', 'titre' => ['delaiJours'],
                'champs' => ['delaiJours', 'actif', 'type'],
                'relations' => [], 'listes' => [],
            ],
            'rappel_paiement' => [
                'classe' => \App\Entity\RappelPaiementEnvoye::class, 'sens' => self::DESCENDANT, 'libelle' => 'Rappel de paiement envoyé', 'titre' => ['dateEnvoi'],
                'champs' => ['dateEnvoi', 'canal'],
                'relations' => ['commande' => 'commande', 'regle' => 'regle_rappel'], 'listes' => [],
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
            // Messages entre le client et l'atelier (Messages de l'espace client, maquette EC01) : le client
            // écrit en ligne, l'atelier répond au local et la réponse redescend. Texte seulement. Le
            // traitement par l'équipe (traiteLe) et l'employé qui a répondu restent au local.
            // Qui répond aux clients (docs/messagerie-clients.md) : nom, métier et une vignette de sa photo
            // (96 px), pour la signature des réponses dans l'espace client. Le compte de l'employé reste au local.
            'agent_messagerie' => [
                'classe' => AgentMessagerie::class, 'sens' => self::DESCENDANT, 'libelle' => 'Agent de la messagerie', 'titre' => ['nom'],
                'champs' => ['nom', 'metier', 'photo', 'majLe'],
                'relations' => [], 'listes' => [],
            ],
            // Une conversation par commande, par devis ou « question générale » (maquettes MS01 à MS05) :
            // le client l'ouvre, la clôture, la rouvre et la note en ligne ; l'atelier propose la clôture
            // au local. Responsable, collègues ajoutés, chronomètre et notes internes restent au local.
            'conversation_client' => [
                'classe' => ConversationClient::class, 'sens' => self::DEUX, 'libelle' => 'Conversation avec le client', 'titre' => ['objet'],
                'champs' => [
                    'objet', 'sujet', 'statut', 'creeLe', 'dernierMessageLe', 'clotureProposeeLe', 'clotureProposeePar',
                    'clotureeLe', 'clotureePar', 'rouverteLe', 'avis', 'avisLe', 'luClientLe', 'luAtelierLe',
                ],
                'relations' => ['client' => 'client', 'commande' => 'commande', 'devis' => 'devis', 'precedente' => 'conversation_client'],
                'listes' => [], 'facultatives' => ['commande', 'devis', 'precedente'],
                // Ouverte en ligne : jamais déjà clôturée, notée ni proposée à la clôture.
                'imposeAuLocal' => [
                    'statut' => ConversationClient::OUVERTE, 'clotureProposeeLe' => null, 'clotureProposeePar' => null,
                    'clotureeLe' => null, 'clotureePar' => null, 'avis' => null, 'avisLe' => null, 'luAtelierLe' => null,
                ],
                // Décisions du client : « Oui, c'est résolu » / « J'ai encore une question », rouvrir, noter, lire.
                'remontees' => [
                    'statut' => [ConversationClient::OUVERTE => [ConversationClient::CLOTUREE], ConversationClient::CLOTUREE => [ConversationClient::OUVERTE]],
                    'clotureProposeeLe' => null, 'clotureProposeePar' => null, 'clotureeLe' => null, 'clotureePar' => null,
                    'rouverteLe' => null, 'avis' => null, 'avisLe' => null, 'luClientLe' => null, 'dernierMessageLe' => null,
                ],
            ],
            'message_client' => [
                'classe' => MessageClient::class, 'sens' => self::DEUX, 'libelle' => 'Message du client', 'titre' => ['auteur'],
                // delaiMinutes : « répondu en 12 min », affiché au client. La cible, le retard, la pénalité
                // et la personne à qui il est imputé restent au local.
                'champs' => ['sens', 'auteur', 'texte', 'creeLe', 'delaiMinutes'],
                'relations' => [
                    'client' => 'client', 'commande' => 'commande', 'conversation' => 'conversation_client',
                    'agent' => 'agent_messagerie', 'bat' => 'controle', 'facture' => 'facture',
                ],
                'listes' => [], 'facultatives' => ['commande', 'conversation', 'agent', 'bat', 'facture'],
                // Écrit en ligne : toujours un message du client, à traiter par l'équipe.
                'imposeAuLocal' => ['sens' => MessageClient::CLIENT, 'traiteLe' => null, 'delaiMinutes' => null, 'agent' => null, 'bat' => null, 'facture' => null],
            ],
            // Raison sociale ou NIF que la personne qui gère le compte demande de changer (Mon compte,
            // maquette EC04) : jamais appliqué en ligne ni à l'arrivée ; l'équipe l'applique ou le refuse
            // depuis la fiche client, la décision et la fiche redescendent. L'employé qui l'a traité reste au local.
            'proposition_client' => [
                'classe' => PropositionClient::class, 'sens' => self::DEUX, 'libelle' => 'Changement demandé par le client', 'titre' => ['auteur'],
                'champs' => ['champs', 'auteur', 'statut', 'creeLe', 'traiteLe', 'motif'],
                'relations' => ['client' => 'client'], 'listes' => [],
                'imposeAuLocal' => ['statut' => PropositionClient::EN_ATTENTE, 'traiteLe' => null, 'motif' => null],
            ],
            // Où en est la livraison, pour l'espace client (livreur en route, livrée, reportée) et ses notifications.
            // Livreur, réceptionnaire, adresse, photos, signature, position, encaissement, stock et notes restent au local,
            // comme la raison d'un échec et qui l'a noté : le client voit seulement « reportée » et la date.
            'livraison' => [
                'classe' => BonLivraison::class, 'sens' => self::DESCENDANT, 'libelle' => 'Bon de livraison', 'titre' => ['numero'],
                'champs' => ['numero', 'statut', 'creeLe', 'valideLe', 'prevuLe', 'creneau', 'chargeLe', 'enRouteLe', 'arriveLe', 'livreLe', 'echecLe', 'echecEnCours'],
                'relations' => ['commande' => 'commande'], 'listes' => [],
                'enfants' => ['lignes' => [
                    'classe' => BonLivraisonLigne::class, 'parent' => 'bonLivraison',
                    'champs' => ['designation', 'quantiteCommandee', 'quantiteLivree', 'unite'],
                    'relations' => ['commandeDetail' => 'commande_ligne'],
                ]],
            ],
            // Sondages (docs/sondages.md) : créés au local, les clients y répondent en ligne.
            // Le créateur et le nombre de destinataires restent au local.
            'sondage' => [
                'classe' => Sondage::class, 'sens' => self::DESCENDANT, 'libelle' => 'Sondage', 'titre' => ['titre'],
                'champs' => ['type', 'titre', 'introduction', 'statut', 'segment', 'anonymeAutorise', 'jeton', 'questions', 'ouvertLe', 'closLe', 'creeLe'],
                'relations' => [], 'listes' => [],
            ],
            // Invitation créée à la livraison : son jeton ouvre le sondage sans compte. Les dates d'envoi restent au local.
            'sondage_invitation' => [
                'classe' => InvitationSondage::class, 'sens' => self::DESCENDANT, 'libelle' => 'Invitation à un sondage', 'titre' => [],
                'champs' => ['jeton', 'creeLe'],
                'relations' => ['commande' => 'commande', 'client' => 'client'], 'listes' => [],
            ],
            // Réponse écrite en ligne, avec ses notes par article. Le thème, le statut de la
            // proposition et le suivi d'une mauvaise note (rappel, réclamation) restent au local.
            'sondage_reponse' => [
                'classe' => ReponseSondage::class, 'sens' => self::DEUX, 'libelle' => 'Réponse à un sondage', 'titre' => ['type'],
                'champs' => ['type', 'auteur', 'nom', 'telephone', 'canal', 'noteGlobale', 'nps', 'noteDelai', 'noteAccueil', 'proposition', 'reponses', 'creeLe'],
                'relations' => ['sondage' => 'sondage', 'commande' => 'commande', 'client' => 'client'], 'listes' => [],
                'enfants' => ['notes' => [
                    'classe' => NoteArticleSondage::class, 'parent' => 'reponse',
                    'champs' => ['designation', 'note'],
                    'relations' => ['ligne' => 'commande_ligne'],
                ]],
            ],
            // Fidélité (docs/fidelite.md) : le site montre au client son solde, sa progression et son relevé,
            // calculés au local. Valeurs propres, motifs, agents, lignes, paiements et montants restent au local.
            'fidelite_compte' => [
                'classe' => CompteFidelite::class, 'sens' => self::DESCENDANT, 'libelle' => 'Compte fidélité', 'titre' => ['solde'],
                'champs' => [
                    'exclu', 'solde', 'soldeF', 'm2Cumules', 'montantCumule', 'seuilM2Applique', 'seuilMontantApplique', 'pointsParM2Applique',
                    'tauxAutreApplique', 'multiplicateurJour', 'delaiHeures', 'pointsExpirant', 'expirationProchaine', 'majLe',
                ],
                'relations' => ['client' => 'client'], 'listes' => [],
                // Un seul compte par client : recréé au local, il remplace celui du site.
                'uniqueSur' => ['client'],
            ],
            'fidelite_mouvement' => [
                'classe' => MouvementFidelite::class, 'sens' => self::DESCENDANT, 'libelle' => 'Mouvement de points', 'titre' => ['type', 'points'],
                'champs' => ['type', 'points', 'date', 'expireLe', 'multiplicateur'],
                'relations' => ['client' => 'client', 'commande' => 'commande'], 'listes' => [], 'facultatives' => ['commande'],
                // Une ligne à 0 point (paiement tardif, article sans bonification) ne sert qu'à la progression, calculée au local.
                'pret' => static fn (MouvementFidelite $m): bool => 0 !== $m->getPoints(),
            ],
            'promo_reveil' => [
                'classe' => PromoReveil::class, 'sens' => self::DESCENDANT, 'libelle' => 'Promo de réveil', 'titre' => ['taux'],
                'champs' => ['taux', 'debut', 'fin', 'utiliseeLe', 'annuleeLe'],
                'relations' => ['client' => 'client', 'commande' => 'commande'], 'listes' => [], 'facultatives' => ['commande'],
            ],
            // Demande de devis ou message d'un visiteur du site (maquette 11) : monte au local, ne redescend pas.
            // Client, prospect, devis préparé et suivi par l'équipe restent au local.
            // Modèle validé dans l'Atelier 3D : écrit en ligne, il monte avec la commande du client ou la
            // demande de devis du visiteur (qui le vise : il vient donc avant elle). Ne redescend pas.
            'atelier_creation' => [
                'classe' => CreationAtelier::class, 'sens' => self::ASCENDANT, 'libelle' => 'Création de l\'Atelier 3D', 'titre' => ['numero', 'designation'],
                'champs' => ['numero', 'auteur', 'designation', 'forme', 'couleur', 'couleurVisiere', 'tailles', 'quantite', 'reglages', 'nombreCommandes', 'creeLe', 'derniereCommandeLe'],
                'relations' => [
                    'client' => 'client', 'configuration' => 'configuration', 'commande' => 'commande',
                    'renduDevant' => 'fichier', 'renduDos' => 'fichier', 'imageDevant' => 'fichier', 'imageDos' => 'fichier',
                ],
                // Un produit retiré du catalogue entre-temps ne bloque pas la création : la désignation reste.
                'facultatives' => ['configuration'],
                'listes' => [], 'numero' => 'numero',
                // Numéro posé juste après la création ; « Commander à nouveau » change la dernière commande.
                'remontees' => ['numero' => ['' => ['*']], 'commande' => null, 'nombreCommandes' => null, 'derniereCommandeLe' => null],
            ],
            'demande_devis' => [
                'classe' => DemandeDevis::class, 'sens' => self::ASCENDANT, 'libelle' => 'Demande du site', 'titre' => ['numero'],
                // quantiteM2 : surface donnée par le visiteur qui a compté en m² (pièces au m²).
                'champs' => ['numero', 'nature', 'nom', 'telephone', 'email', 'objet', 'designation', 'largeurCm', 'hauteurCm', 'quantite', 'quantiteM2', 'message', 'creeLe', 'accuseEnvoyeLe'],
                // Un produit retiré du catalogue entre-temps ne bloque pas la demande : la désignation reste.
                'relations' => ['configuration' => 'configuration', 'fichier' => 'fichier', 'creation' => 'atelier_creation'],
                'facultatives' => ['configuration'],
                'listes' => ['finitions' => 'finition'], 'numero' => 'numero',
                // Numéro et accusé de réception posés en ligne juste après la création.
                'remontees' => ['numero' => ['' => ['*']], 'accuseEnvoyeLe' => ['' => ['*']]],
            ],
            // Demande de conception (docs/demande-conception.md) : supports réglés au local, demandes
            // écrites en ligne. Le support ne dit rien des coûts : le forfait est le prix public.
            'support_conception' => [
                'classe' => SupportConception::class, 'sens' => self::DESCENDANT, 'libelle' => 'Support à concevoir', 'titre' => ['nom'],
                'champs' => ['code', 'nom', 'court', 'format', 'largeurCm', 'hauteurCm', 'forfait', 'acomptePourcent', 'delaiBatHeures', 'correctionsIncluses', 'couleur', 'actif', 'ordre'],
                'relations' => [], 'listes' => ['configurations' => 'configuration'],
            ],
            // Brouillon en ligne jusqu'à l'envoi (acompte reçu, visuel IA commandé) ; le suivi de l'atelier
            // redescend. Compte en ligne, étape du parcours et graphiste qui l'a prise restent de leur côté.
            'demande_conception' => [
                'classe' => DemandeConception::class, 'sens' => self::DEUX, 'libelle' => 'Demande de conception', 'titre' => ['numero', 'supportNom'],
                'champs' => [
                    'numero', 'auteur', 'supportNom', 'supportFormat', 'largeurCm', 'hauteurCm', 'forfait', 'acomptePourcent', 'correctionsIncluses',
                    'delaiBatHeures', 'titre', 'sousTitre', 'dateHeure', 'coordonnees', 'consignes', 'couleurs', 'styles', 'quantite', 'pourQuand',
                    'exempleLien', 'choix', 'statut', 'finalisationGraphiste', 'nombreImages', 'creeLe', 'envoyeeLe', 'priseEnChargeLe', 'termineeLe',
                ],
                'relations' => ['client' => 'client', 'support' => 'support_conception', 'commande' => 'commande', 'paiementAcompte' => 'paiement', 'imageChoisie' => 'fichier'],
                'facultatives' => ['support'],
                'listes' => ['elements' => 'fichier'], 'numero' => 'numero',
                'pret' => static fn (DemandeConception $d): bool => $d->estEnvoyee(),
                // Le suivi se décide au local.
                'imposeAuLocal' => ['priseEnChargeLe' => null, 'termineeLe' => null],
            ],
            // Relevé du crédit IA (achats, images, remboursements, rejets) : écrit en ligne, lu au local.
            'mouvement_credit_ia' => [
                'classe' => MouvementCreditIa::class, 'sens' => self::ASCENDANT, 'libelle' => 'Mouvement de crédit IA', 'titre' => ['type', 'montant'],
                'champs' => ['type', 'montant', 'images', 'libelle', 'auteur', 'etat', 'cle', 'creeLe'],
                'relations' => ['client' => 'client', 'commande' => 'commande', 'paiement' => 'paiement'], 'listes' => [],
                // Un achat payé au numéro marchand ne part qu'une fois acquis ; ensuite il ne change plus.
                'pret' => static fn (MouvementCreditIa $m): bool => $m->estAcquis(),
            ],
            // Conversations avec l'assistant du site et de l'espace client (docs/assistant-client.md) : montent
            // au local pour Clients › Assistant du site, ne redescendent pas. Le jeton du visiteur, le compte
            // connecté, les échanges exacts avec l'API et les boutons proposés restent en ligne.
            'assistant_conversation' => [
                'classe' => AssistantConversation::class, 'sens' => self::ASCENDANT, 'libelle' => 'Conversation de l\'assistant', 'titre' => ['numero'],
                'champs' => ['numero', 'contexte', 'interlocuteur', 'page', 'modele', 'debutLe', 'derniereLe', 'nombreQuestions', 'passageAgentLe'],
                'relations' => ['client' => 'client'], 'listes' => [], 'numero' => 'numero',
                // La conversation continue en ligne après sa création.
                'remontees' => ['numero' => ['' => ['*']], 'derniereLe' => null, 'nombreQuestions' => null, 'passageAgentLe' => null],
            ],
            'assistant_message' => [
                'classe' => AssistantMessage::class, 'sens' => self::ASCENDANT, 'libelle' => 'Message de l\'assistant', 'titre' => [],
                'champs' => ['role', 'texte', 'issue', 'outils', 'note', 'modele', 'jetonsEntree', 'jetonsSortie', 'jetonsCacheLecture', 'jetonsCacheEcriture', 'depenseUsd', 'depenseFcfa', 'creeLe'],
                'relations' => ['conversation' => 'assistant_conversation'], 'listes' => [],
                // Le pouce est donné après la réponse.
                'remontees' => ['note' => null],
            ],
            // Historique des envois du site en ligne (codes de connexion, notifications de l'espace client) :
            // monte au local pour Clients › Crédits SMS et e-mail, en lecture seule. Jamais le texte du message.
            'envoi_message' => [
                'classe' => \App\Entity\EnvoiMessage::class, 'sens' => self::ASCENDANT, 'libelle' => 'Envoi de SMS ou d\'e-mail', 'titre' => ['canal', 'type'],
                'champs' => ['canal', 'type', 'sujet', 'destinataire', 'statut', 'erreur', 'unites', 'envoyeLe', 'origine'],
                'relations' => [], 'listes' => [],
                'imposeAuLocal' => ['origine' => \App\Entity\EnvoiMessage::EN_LIGNE],
            ],
            // Alertes système nées en ligne (docs/alertes-systeme.md) : montent au local, où elles arrivent ouvertes
            // dans la cloche du personnel (l'e-mail est parti du site). Le compteur et la dernière date remontent ;
            // un compteur qui monte sur une alerte résolue au local la rouvre (AlerteSysteme::rouvrirSiRevenue).
            // Qui la prend en charge et la résolution restent de chaque côté.
            'alerte_systeme' => [
                'classe' => \App\Entity\AlerteSysteme::class, 'sens' => self::ASCENDANT, 'libelle' => 'Alerte système', 'titre' => ['reference', 'titre'],
                'champs' => ['reference', 'type', 'gravite', 'titre', 'detail', 'empreinte', 'origine', 'occurrences', 'premiereLe', 'derniereLe', 'emailEnvoyeLe'],
                'relations' => [], 'listes' => [],
                'imposeAuLocal' => ['origine' => \App\Entity\AlerteSysteme::EN_LIGNE, 'etat' => \App\Entity\AlerteSysteme::OUVERTE, 'aPrevenir' => true],
                'remontees' => ['occurrences' => null, 'derniereLe' => null, 'detail' => null, 'emailEnvoyeLe' => null],
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
     * Ce que le site reçoit d'une tuile : seulement les champs publics, avec
     * leur valeur par défaut si le local ne les a jamais changés. Un champ
     * tenu ailleurs (« source » paiement : taux des frais Orange Money)
     * part seulement si l'appelant a ajouté sa valeur (Synchro::noterReglages).
     *
     * @param array<string, mixed> $stockees valeurs enregistrées au local
     *
     * @return array<string, mixed>
     */
    public static function reglagesPublics(string $tuile, array $stockees): array
    {
        $champs = array_values(array_filter(
            CatalogueParametres::tuiles()[$tuile]['champs'] ?? [],
            static fn (array $c): bool => \in_array($c['cle'], self::REGLAGES_PUBLICS[$tuile] ?? [], true),
        ));
        $valeurs = CatalogueParametres::avecDefauts($champs, $stockees);
        foreach ($champs as $c) {
            if ('paiement' === ($c['source'] ?? null) && \array_key_exists($c['cle'], $stockees)) {
                $valeurs[$c['cle']] = $stockees[$c['cle']];
            }
        }

        return $valeurs;
    }

    /**
     * Valeurs reçues d'une tuile, séparées : celles de la table des réglages
     * (Reglage) et les taux tenus dans ParametresPaiement (« source » paiement).
     *
     * @param array<string, mixed> $valeurs
     *
     * @return array{0: array<string, mixed>, 1: array<string, float>}
     */
    public static function separerSourcePaiement(string $tuile, array $valeurs): array
    {
        $paiement = [];
        foreach (CatalogueParametres::tuiles()[$tuile]['champs'] ?? [] as $c) {
            if ('paiement' === ($c['source'] ?? null) && \array_key_exists($c['cle'], $valeurs)) {
                $paiement[$c['cle']] = (float) $valeurs[$c['cle']];
                unset($valeurs[$c['cle']]);
            }
        }

        return [$valeurs, $paiement];
    }

    /**
     * Ce côté-ci doit-il envoyer ce type ? Le local envoie tout, sauf ce
     * qui ne fait que monter ; le site n'envoie que les types à double
     * sens, montants ou qui ont des remontées.
     */
    public static function envoye(string $type, bool $enLigne): bool
    {
        return isset(self::types()[$type]) && ($enLigne ? self::remonte($type) : !self::ascendant($type));
    }

    /** Ce côté-ci accepte-t-il ce type venant de l'autre ? */
    public static function accepte(string $type, bool $enLigne): bool
    {
        return isset(self::types()[$type]) && ($enLigne ? !self::ascendant($type) : self::remonte($type));
    }

    /** Une fiche créée en ligne est-elle reprise au local ? */
    public static function creationAcceptee(string $type, bool $enLigne): bool
    {
        return $enLigne || \in_array(self::types()[$type]['sens'] ?? null, [self::DEUX, self::ASCENDANT], true);
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

        return \in_array($t['sens'], [self::DEUX, self::ASCENDANT], true) || ($t['remontees'] ?? []) !== [];
    }

    private static function ascendant(string $type): bool
    {
        return self::types()[$type]['sens'] === self::ASCENDANT;
    }

    /** Les champs dont un changement doit partir. */
    public static function champsSuivis(string $type): array
    {
        $t = self::types()[$type];

        return [...$t['champs'], ...array_keys($t['relations'])];
    }
}
