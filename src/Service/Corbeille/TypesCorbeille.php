<?php

namespace App\Service\Corbeille;

/**
 * Ce que la corbeille garde, comment elle nomme chaque fiche et qui la
 * voit (docs/corbeille.md). Tout est statique pour être testé sans base.
 *
 * - EXCLUES : tables techniques jamais copiées, même quand elles partent
 *   avec une fiche (journal, synchronisation, abonnements et appareils,
 *   codes de connexion, jetons, compteurs, la corbeille elle-même).
 * - RATTACHEES : éléments qui n'entrent dans la corbeille qu'avec leur
 *   fiche (lignes d'un document, finitions, photos d'un objet du site…).
 *   Retirés seuls, pendant une modification, ils font partie de la
 *   modification. Les tables de liaison sans entité sont toujours
 *   rattachées.
 * - SENSIBLES : colonnes jamais copiées (mots de passe, codes PIN, jetons
 *   et secrets) et ce qu'elles reçoivent à la restauration : vide, ou une
 *   valeur neuve tirée au hasard (l'ancien lien ou code ne marche plus).
 * - TYPES : nom en français, colonnes du titre et droit qu'il faut pour
 *   voir l'élément (en plus de l'accès à la corbeille). Une table absente
 *   est nommée d'après elle-même et réservée à la Direction.
 */
final class TypesCorbeille
{
    public const DIRECTION = 'ROLE_ADMIN';

    public const EXCLUES = [
        'corbeille',
        'journal_activite',
        'synchro_journal', 'synchro_etat', 'synchro_recu', 'synchro_echange', 'synchro_fichier',
        'abonnement_push', 'abonnement_push_client',
        'appareil_autorise', 'appareil_client', 'appareil_pointage',
        'acces_espace_client',
        'jeton_api',
        'compteur_ia_jour',
        'notifications', 'notification_client_lue', 'notification_client_envoyee', 'notification_client_differee',
        'rappel_paiement_envoye',
        'messenger_messages', 'doctrine_migration_versions', 'sessions', 'cache_items', 'rememberme_token',
    ];

    public const RATTACHEES = [
        'devis_details', 'devis_detail_finition',
        'commandes_details', 'commande_detail_finition',
        'produit_configuration_finition',
        'reglement_en_ligne_part',
        'bon_livraison_ligne',
        'achat_detail',
        'conversation_participants',
        'mouvement_fidelite',
        'tache',
        'objectif',
        'contact_client',
        'site_image',
        'reclamation_client_photo',
        'stock_reservation',
        'prix_historique',
        'consommation_encres',
        'journal_machine',
    ];

    /** Remplacement à la restauration : null (vide) ou « aleatoire » (valeur neuve). */
    public const SENSIBLES = [
        'user' => ['password' => 'aleatoire', 'pin' => null, 'invitation_empreinte' => null],
        'compte_client' => ['mot_de_passe' => null],
        'transaction_orange_money' => ['pay_token' => 'aleatoire', 'notif_empreinte' => 'aleatoire'],
        'point_pointage' => ['secret' => 'aleatoire'],
        'devis' => ['token_authenticite' => null],
        'factures' => ['token_authenticite' => null, 'code_suivi' => null],
        'etiquette' => ['token' => 'aleatoire'],
        'sondage' => ['jeton' => 'aleatoire'],
        'invitation_sondage' => ['jeton' => 'aleatoire'],
        'conversations' => ['jeton' => 'aleatoire'],
        'assistant_conversation' => ['jeton' => null],
        'commande_detail_fichier' => ['jeton_upload' => 'aleatoire'],
    ];

    /**
     * Colonnes de montant, de coût ou de salaire : masquées dans le détail
     * pour qui n'est pas la Direction.
     */
    public const MONTANTS = '/(montant|prix|total|solde|cout|marge|salaire|plafond|remise|acompte|tarif|valeur|credit|debit|frais|reste|paye|budget|chiffre|capital|amortissement)/i';

    /**
     * @var array<string, array{0: string, 1: list<string>, 2: string}> table => [type, colonnes du titre, droit]
     */
    public const TYPES = [
        'clients' => ['Client', ['raison_sociale', 'prenom+nom', 'code'], 'ROLE_CLIENT'],
        'prospect' => ['Prospect', ['nom'], 'ROLE_CLIENT'],
        'contact_client' => ['Interlocuteur', ['nom'], 'ROLE_CLIENT'],
        'adresse_livraison' => ['Adresse de livraison', ['libelle', 'adresse'], 'ROLE_CLIENT'],
        'compte_client' => ['Accès à l\'espace client', ['nom', 'telephone'], 'ROLE_CLIENT'],
        'sondage' => ['Sondage', ['titre'], 'ROLE_CLIENT'],
        'reclamation_client' => ['Réclamation de client', ['numero', 'objet'], 'ROLE_CLIENT'],
        'message_client' => ['Message de client', ['texte'], 'ROLE_CLIENT'],
        'devis' => ['Devis', ['numero'], 'ROLE_DEVIS'],
        'commandes' => ['Commande', ['numero'], 'ROLE_COMMANDE'],
        'commande_detail_fichier' => ['Fichier du client', ['nom_original'], 'ROLE_COMMANDE'],
        'demande_devis' => ['Demande du site', ['numero', 'nom'], 'ROLE_DEVIS'],
        'factures' => ['Facture', ['numero'], 'ROLE_FACTURE'],
        'paiements' => ['Paiement', ['reference', 'numero_recu'], 'ROLE_PAIEMENT_VOIR'],
        'demande_conception' => ['Demande de conception', ['numero', 'titre'], 'ROLE_PREPRESSE'],
        'promotion' => ['Promotion', ['nom'], 'ROLE_PROMOTIONS'],
        'promotion_anniversaire_modele' => ['Modèle d\'anniversaire', ['nom'], 'ROLE_PROMOTIONS'],
        'decaissement_recurrent' => ['Charge récurrente', ['libelle'], 'ROLE_TRESORERIE_VOIR'],
        'depense' => ['Dépense', ['objet', 'beneficiaire'], 'ROLE_TRESORERIE_VOIR'],
        'mouvement_tresorerie' => ['Mouvement de trésorerie', ['libelle', 'reference'], 'ROLE_TRESORERIE_VOIR'],
        'site_page' => ['Page du site', ['titre'], 'ROLE_SITE_VITRINE'],
        'site_article' => ['Conseil du site', ['titre'], 'ROLE_SITE_VITRINE'],
        'site_annonce' => ['Annonce du site', ['texte'], 'ROLE_SITE_VITRINE'],
        'site_avis' => ['Avis client du site', ['nom_affiche'], 'ROLE_SITE_VITRINE'],
        'site_partenaire' => ['Partenaire du site', ['nom'], 'ROLE_SITE_VITRINE'],
        'site_realisation' => ['Réalisation du site', ['titre'], 'ROLE_SITE_VITRINE'],
        'site_image' => ['Image du site', ['texte_alternatif'], 'ROLE_SITE_VITRINE'],
        'produits' => ['Produit', ['nom'], self::DIRECTION],
        'produit_configuration' => ['Configuration de produit', ['description'], self::DIRECTION],
        'categorie_produit' => ['Catégorie de produits', ['nom'], self::DIRECTION],
        'supports' => ['Support', ['nom'], self::DIRECTION],
        'format' => ['Format', ['nom'], self::DIRECTION],
        'finition' => ['Finition', ['nom'], self::DIRECTION],
        'types_impression' => ['Type d\'impression', ['nom'], self::DIRECTION],
        'machines' => ['Machine', ['nom'], self::DIRECTION],
        'fournisseurs' => ['Fournisseur', ['nom'], self::DIRECTION],
        'achats' => ['Demande d\'achat', ['numero'], self::DIRECTION],
        'articles' => ['Article', ['designation', 'reference'], self::DIRECTION],
        'stock_sorties' => ['Consommable (sortie de stock)', ['reference_origine'], self::DIRECTION],
        'modele_message' => ['Modèle de message', ['nom'], self::DIRECTION],
        'regle_rappel_paiement' => ['Règle de rappel de paiement', ['nom'], self::DIRECTION],
        'regle_fidelite' => ['Règle de fidélité', ['note'], self::DIRECTION],
        'jour_ferie' => ['Jour férié', ['libelle', 'jour'], self::DIRECTION],
        'employes' => ['Employé', ['prenom+nom'], self::DIRECTION],
        'user' => ['Compte du personnel', ['username'], self::DIRECTION],
        'devis_details' => ['Ligne de devis', ['designation'], 'ROLE_DEVIS'],
        'commandes_details' => ['Ligne de commande', ['designation'], 'ROLE_COMMANDE'],
        'mouvement_fidelite' => ['Points de fidélité', ['motif', 'type'], self::DIRECTION],
        'commande_detail_finition' => ['Finition d\'une ligne de commande', ['nom_finition'], 'ROLE_COMMANDE'],
        'devis_detail_finition' => ['Finition d\'une ligne de devis', ['nom_finition'], 'ROLE_DEVIS'],
        'controle_pre_presse' => ['Contrôle du BAT', ['statut'], 'ROLE_PREPRESSE'],
    ];

    /**
     * Fiches dont la suppression a eu un effet qu'une simple remise en base
     * ne referait pas : elles restent visibles dans la corbeille (et
     * peuvent être supprimées pour de bon), mais la restauration est
     * refusée avec cette explication.
     *
     * @var array<string, string>
     */
    public const NON_RESTAURABLES = [
        'stock_sorties' => 'le stock a été recrédité quand il a été retiré : s\'il a bien été utilisé, saisissez-le de nouveau',
    ];

    /**
     * Suppression qui n'a rien à garder : un fichier envoyé pendant la
     * saisie d'une commande puis retiré avant de l'enregistrer (il n'était
     * dans aucune ligne, et ses octets sont déjà effacés). Les fichiers
     * « Mes fichiers » du client ne sont jamais effacés ainsi : ils sont
     * cachés (Corbeille::masquer).
     *
     * @param array<string, mixed> $racine colonnes de la fiche supprimée
     */
    public static function ignoree(string $table, array $racine): bool
    {
        return 'commande_detail_fichier' === $table && null === ($racine['commande_detail_id'] ?? null);
    }

    public static function exclue(string $table): bool
    {
        return \in_array($table, self::EXCLUES, true);
    }

    public static function rattachee(string $table, bool $entite = true): bool
    {
        return !$entite || \in_array($table, self::RATTACHEES, true);
    }

    /** Nature de l'élément, en français. */
    public static function type(string $table): string
    {
        return self::TYPES[$table][0] ?? ucfirst(str_replace('_', ' ', $table));
    }

    /** Droit qu'il faut, en plus de l'accès à la corbeille, pour voir l'élément. */
    public static function droit(string $table): string
    {
        return self::TYPES[$table][2] ?? self::DIRECTION;
    }

    /**
     * « Devis DEV-2026-0042 » : le type et la première colonne du titre
     * remplie (« prenom+nom » : les deux, séparés d'une espace).
     *
     * @param array<string, mixed> $ligne
     */
    public static function libelle(string $table, array $ligne, string $cle): string
    {
        $titre = '';
        foreach (self::TYPES[$table][1] ?? ['nom', 'libelle', 'titre', 'numero'] as $colonnes) {
            $morceaux = [];
            foreach (explode('+', $colonnes) as $colonne) {
                $valeur = $ligne[$colonne] ?? null;
                if (\is_scalar($valeur) && '' !== trim((string) $valeur)) {
                    $morceaux[] = trim((string) $valeur);
                }
            }
            if ([] !== $morceaux) {
                $titre = implode(' ', $morceaux);
                break;
            }
        }
        $titre = '' === $titre ? 'n° '.$cle : $titre;
        $titre = mb_strlen($titre) > 120 ? mb_substr($titre, 0, 119).'…' : $titre;

        return self::type($table).' « '.$titre.' »';
    }

    /**
     * Colonne à ne pas copier : null si elle se copie, sinon le
     * remplacement à la restauration (null ou « aleatoire »).
     *
     * @return array{0: bool, 1: ?string}
     */
    public static function sensible(string $table, string $colonne): array
    {
        if (!isset(self::SENSIBLES[$table]) || !\array_key_exists($colonne, self::SENSIBLES[$table])) {
            return [false, null];
        }

        return [true, self::SENSIBLES[$table][$colonne]];
    }

    public static function estMontant(string $colonne): bool
    {
        return 1 === preg_match(self::MONTANTS, $colonne);
    }

    /**
     * Fichiers rangés sur le disque pour cette ligne (chemins relatifs au
     * projet), retirés à la suppression définitive. Un fichier encore
     * utilisé par une autre ligne reste (Corbeille::purger le vérifie).
     *
     * @param array<string, mixed> $ligne
     *
     * @return list<array{chemin: string, colonne: ?string}>
     */
    public static function fichiers(string $table, array $ligne): array
    {
        $nom = static function (string $colonne) use ($ligne): ?string {
            $valeur = $ligne[$colonne] ?? null;
            if (!\is_string($valeur)) {
                return null;
            }
            $base = basename(trim($valeur));

            return '' === $base || '.' === $base || '..' === $base ? null : $base;
        };

        return match ($table) {
            'commande_detail_fichier' => null === ($n = $nom('nom_stockage')) ? [] : [
                ['chemin' => 'var/uploads/commandes/'.$n, 'colonne' => 'nom_stockage'],
                ['chemin' => 'var/uploads/commandes/apercus/'.$n, 'colonne' => 'nom_stockage'],
            ],
            'image_ia' => null === ($n = $nom('fichier')) || !is_numeric($ligne['client_id'] ?? null) ? [] : [
                ['chemin' => 'var/uploads/ia/'.(int) $ligne['client_id'].'/'.$n, 'colonne' => 'fichier'],
            ],
            'messages_chat' => null === ($n = $nom('piece_jointe_fichier')) ? [] : [['chemin' => 'var/uploads/chat/'.$n, 'colonne' => 'piece_jointe_fichier']],
            'reclamations' => null === ($n = $nom('justificatif')) ? [] : [['chemin' => 'var/uploads/reclamations/'.$n, 'colonne' => 'justificatif']],
            'depense' => \is_string($ligne['justificatif'] ?? null) && 1 === preg_match('#^\d{4}/\d{2}/[0-9a-f]{32}\.(jpg|png|webp|pdf)$#', $ligne['justificatif'])
                ? [['chemin' => 'var/uploads/depenses/'.$ligne['justificatif'], 'colonne' => 'justificatif']] : [],
            // L'original de l'image du site, rangé sous son identifiant public (les tailles publiques sont refaites depuis la base).
            'site_image' => null === ($uuid = self::uuid($ligne['public_id'] ?? null)) ? [] : [['chemin' => 'var/uploads/site/originaux/'.$uuid.'.*', 'colonne' => null]],
            default => [],
        };
    }

    /** Identifiant public (16 octets en base, ou déjà en texte) en clair. */
    public static function uuid(mixed $valeur): ?string
    {
        if (\is_array($valeur) && isset($valeur[Instantane::BINAIRE])) {
            $valeur = base64_decode((string) $valeur[Instantane::BINAIRE], true);
        }
        if (\is_string($valeur) && 16 === \strlen($valeur)) {
            $hex = bin2hex($valeur);

            return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
        }
        if (\is_string($valeur) && 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $valeur)) {
            return strtolower($valeur);
        }

        return null;
    }
}
