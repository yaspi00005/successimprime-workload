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
        // Liens refaits à chaque enregistrement de leur fiche (nomenclature d'un produit, fournisseurs
        // d'un article, destinataires d'une campagne, notes d'une réponse reçue par la synchronisation).
        'produit_article_stock',
        'article_fournisseur',
        'campagne_destinataire',
        'note_article_sondage',
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
    public const MONTANTS = '/(montant|prix|total|solde|cout|marge|salaire|plafond|remise|acompte|tarif|valeur|credit|debit|frais|reste|paye|budget|chiffre|capital|amortissement|forfait|minimum_commande|surfacturation)/i';

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
        return self::TYPES[$table][0] ?? self::nomColonne($table);
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
        $titre = self::titre($table, $ligne);
        if (null === $titre) {
            // Clé à plusieurs colonnes (table de liaison) : « n° 8 · 900070 » plutôt que du JSON.
            $parties = str_starts_with($cle, '{') ? json_decode($cle, true) : null;
            $titre = 'n° '.(\is_array($parties) ? implode(' · ', array_map(static fn (mixed $v): string => \is_scalar($v) ? (string) $v : '?', $parties)) : $cle);
        }

        return self::type($table).' « '.$titre.' »';
    }

    /**
     * Le titre seul (« DEV-2026-0042 », « Awa Traoré »), coupé à 120
     * caractères, ou null si aucune colonne du titre n'est remplie.
     *
     * @param array<string, mixed> $ligne
     */
    public static function titre(string $table, array $ligne): ?string
    {
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

                return mb_strlen($titre) > 120 ? mb_substr($titre, 0, 119).'…' : $titre;
            }
        }

        return null;
    }

    /** Mots des noms de colonnes, avec leurs accents et leurs majuscules. */
    private const MOTS = [
        'a' => 'à', 'aun' => 'à un', 'apayer' => 'à payer', 'numero' => 'numéro', 'telephone' => 'téléphone', 'societe' => 'société',
        'categorie' => 'catégorie', 'quantite' => 'quantité', 'designation' => 'désignation', 'unite' => 'unité',
        'priorite' => 'priorité', 'reference' => 'référence', 'entree' => 'entrée', 'debut' => 'début', 'cree' => 'créé',
        'creee' => 'créée', 'modifie' => 'modifié', 'modifiee' => 'modifiée', 'emetteur' => 'émetteur', 'etat' => 'état',
        'delai' => 'délai', 'echeance' => 'échéance', 'recu' => 'reçu', 'prenom' => 'prénom', 'reglement' => 'règlement',
        'regle' => 'règle', 'reponse' => 'réponse', 'periode' => 'période', 'duree' => 'durée', 'cle' => 'clé',
        'genere' => 'généré', 'valide' => 'validé', 'validee' => 'validée', 'envoye' => 'envoyé', 'envoyee' => 'envoyée',
        'annule' => 'annulé', 'annulee' => 'annulée', 'livre' => 'livré', 'livree' => 'livrée', 'tache' => 'tâche',
        'evenement' => 'événement', 'electronique' => 'électronique', 'ttc' => 'TTC', 'ht' => 'HT', 'tva' => 'TVA',
        'sms' => 'SMS', 'url' => 'URL', 'ia' => 'IA', 'bat' => 'BAT', 'pao' => 'PAO', 'b2b' => 'B2B', 'nb' => 'nombre de',
        'libelle' => 'libellé', 'defaut' => 'défaut', 'cout' => 'coût', 'role' => 'rôle', 'activite' => 'activité',
        'operateur' => 'opérateur', 'beneficiaire' => 'bénéficiaire', 'methode' => 'méthode', 'reperes' => 'repères', 'repere' => 'repère',
        'detail' => 'détail', 'details' => 'détails', 'bareme' => 'barème', 'cloture' => 'clôture', 'fidelite' => 'fidélité',
        'tresorerie' => 'trésorerie', 'conge' => 'congé', 'employe' => 'employé', 'employes' => 'employés', 'controle' => 'contrôle',
        'pre' => 'pré', 'creation' => 'création', 'credit' => 'crédit', 'element' => 'élément', 'etiquette' => 'étiquette',
        'evaluation' => 'évaluation', 'donnees' => 'données', 'releve' => 'relevé', 'modele' => 'modèle', 'parametres' => 'paramètres',
        'reclamation' => 'réclamation', 'reclamations' => 'réclamations', 'reglage' => 'réglage', 'creance' => 'créance',
        'realisation' => 'réalisation', 'entrees' => 'entrées', 'reveil' => 'réveil', 'depense' => 'dépense', 'depenses' => 'dépenses',
        'whatsapp' => 'WhatsApp', 'orange' => 'Orange', 'money' => 'Money', 'precedent' => 'précédent', 'reservation' => 'réservation',
        'accorde' => 'accordé', 'affectee' => 'affectée', 'ajoute' => 'ajouté', 'ajoutes' => 'ajoutés', 'annee' => 'année',
        'annees' => 'années', 'lannee' => 'l\'année', 'annules' => 'annulés', 'applique' => 'appliqué', 'appreciation' => 'appréciation',
        'approuvee' => 'approuvée', 'apres' => 'après', 'arrivee' => 'arrivée', 'assigne' => 'assigné', 'associe' => 'associé',
        'autorise' => 'autorisé', 'bloque' => 'bloqué', 'capacite' => 'capacité', 'commandee' => 'commandée', 'commence' => 'commencé',
        'comptabilisee' => 'comptabilisée', 'coordonnees' => 'coordonnées', 'corrige' => 'corrigé', 'crees' => 'créés',
        'cumule' => 'cumulé', 'cumules' => 'cumulés', 'debute' => 'débuté', 'debutee' => 'débutée', 'decalage' => 'décalage',
        'decide' => 'décidé', 'decision' => 'décision', 'decoupe' => 'découpe', 'decouvert' => 'découvert', 'deja' => 'déjà',
        'demandee' => 'demandée', 'demarre' => 'démarré', 'depart' => 'départ', 'derniere' => 'dernière', 'desactivation' => 'désactivation',
        'desactive' => 'désactivé', 'difficulte' => 'difficulté', 'ecart' => 'écart', 'echec' => 'échec', 'ecrit' => 'écrit',
        'ecriture' => 'écriture', 'effectuees' => 'effectuées', 'emettrice' => 'émettrice', 'emission' => 'émission', 'encaisse' => 'encaissé',
        'entite' => 'entité', 'envoyes' => 'envoyés', 'epaisseur' => 'épaisseur', 'estime' => 'estimé', 'estimee' => 'estimée',
        'etape' => 'étape', 'etapes' => 'étapes', 'etiquettes' => 'étiquettes', 'evenements' => 'événements', 'execution' => 'exécution',
        'expediteur' => 'expéditeur', 'expire' => 'expiré', 'fige' => 'figé', 'frequence' => 'fréquence', 'imprime' => 'imprimé',
        'imprimes' => 'imprimés', 'importee' => 'importée', 'impose' => 'imposé', 'jusqua' => 'jusqu\'à', 'journee' => 'journée',
        'justifie' => 'justifié', 'levee' => 'levée', 'liberation' => 'libération', 'lie' => 'lié', 'localise' => 'localisé',
        'maj' => 'mise à jour', 'metres' => 'mètres', 'modeles' => 'modèles', 'necessaire' => 'nécessaire', 'operation' => 'opération',
        'oeillets' => 'œillets', 'payee' => 'payée', 'penalite' => 'pénalité', 'piece' => 'pièce', 'pieces' => 'pièces',
        'pointee' => 'pointée', 'portee' => 'portée', 'prefere' => 'préféré', 'preferences' => 'préférences', 'prefixe' => 'préfixe',
        'preparee' => 'préparée', 'preventif' => 'préventif', 'prevu' => 'prévu', 'prevue' => 'prévue', 'proprietaire' => 'propriétaire',
        'propose' => 'proposé', 'quantites' => 'quantités', 'reception' => 'réception', 'receptionnaire' => 'réceptionnaire',
        'recupere' => 'récupéré', 'recus' => 'reçus', 'reel' => 'réel', 'refuse' => 'refusé', 'reglages' => 'réglages',
        'remuneration' => 'rémunération', 'remplace' => 'remplacé', 'renouvele' => 'renouvelé', 'repondu' => 'répondu',
        'reponses' => 'réponses', 'reportee' => 'reportée', 'residuelle' => 'résiduelle', 'resolution' => 'résolution',
        'resultat' => 'résultat', 'resume' => 'résumé', 'retiree' => 'retirée', 'retires' => 'retirés', 'revoque' => 'révoqué',
        'roles' => 'rôles', 'securite' => 'sécurité', 'selectionnee' => 'sélectionnée', 'serie' => 'série', 'superieur' => 'supérieur',
        'systeme' => 'système', 'tetes' => 'têtes', 'theme' => 'thème', 'theorique' => 'théorique', 'termine' => 'terminé',
        'terminee' => 'terminée', 'tournee' => 'tournée', 'traite' => 'traité', 'traitee' => 'traitée', 'unites' => 'unités',
        'utilise' => 'utilisé', 'utilisee' => 'utilisée', 'validees' => 'validées', 'validite' => 'validité', 'verifie' => 'vérifié',
        'verifiee' => 'vérifiée', 'verification' => 'vérification', 'visiere' => 'visière', 'email' => 'e-mail',
        // Colonnes techniques en anglais : created_at « Créé le ».
        'created' => 'créé', 'updated' => 'modifié', 'at' => 'le', 'add' => 'ajout', 'update' => 'mise à jour',
    ];

    /** « date_devis » : « Date devis » ; « numero » : « Numéro ». */
    public static function nomColonne(string $colonne): string
    {
        $mots = array_map(static fn (string $m): string => self::MOTS[$m] ?? $m, explode('_', strtolower($colonne)));

        $nom = trim(implode(' ', $mots));

        return mb_strtoupper(mb_substr($nom, 0, 1)).mb_substr($nom, 1);
    }

    /**
     * Nom d'un champ qui pointe vers une autre fiche : « Client » pour
     * client_id, « Créé par » pour cree_par_id (deux liens vers le
     * personnel ne portent pas le même nom).
     */
    public static function nomLien(string $colonne, string $cible): string
    {
        $base = (string) preg_replace('/_id$/', '', $colonne);

        return \in_array($cible, [$base, $base.'s', $base.'x'], true) ? self::type($cible) : self::nomColonne($base);
    }

    /**
     * Valeur d'un champ, telle qu'on la lit : oui ou non, date en jj/mm/aaaa,
     * montant avec ses milliers séparés (70 000).
     *
     * @param ?string $type type Doctrine de la colonne (boolean, datetime_immutable…), s'il est connu
     */
    public static function valeurLisible(mixed $valeur, ?string $type = null, bool $montant = false): string
    {
        if (\is_bool($valeur) || ('boolean' === $type && \in_array((string) $valeur, ['0', '1'], true))) {
            return (bool) $valeur ? 'oui' : 'non';
        }
        $texte = \is_scalar($valeur) ? (string) $valeur : '';
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?)?$/', $texte, $m)) {
            $date = $m[3].'/'.$m[2].'/'.$m[1];

            return isset($m[4]) && '00:00' !== $m[4].':'.$m[5] ? $date.' à '.$m[4].':'.$m[5] : $date;
        }
        if ($montant && is_numeric($texte)) {
            $nombre = (float) $texte;
            $decimales = floor($nombre) === $nombre ? 0 : 2;

            return number_format($nombre, $decimales, ',', ' ');
        }

        return $texte;
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
