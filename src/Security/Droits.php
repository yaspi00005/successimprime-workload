<?php

namespace App\Security;

/**
 * Catalogue des droits qu'on coche par role (Reglages, Roles et droits)
 * et par personne (fiche utilisateur), maquettes U02 et U03.
 *
 * Chaque droit est un role Symfony fin, celui que les controleurs et
 * les ecrans testent deja avec is_granted. Le stock, les achats, les
 * reglages et la gestion des utilisateurs restent a l'administrateur.
 */
final class Droits
{
    public const GROUPE_FONCTIONS = 'Fonctions';
    public const GROUPE_CHIFFRES = 'Statistiques et chiffres';
    public const GROUPE_LECTURE = 'Lecture seule';

    /**
     * Module en lecture seule (Reglages, Roles et menus, maquette R04) : la
     * personne ouvre les ecrans du module mais ne peut rien y changer.
     * Voir LectureSeuleSubscriber et ModulesRoles.
     */
    public const LECTURE_VENTES = 'ROLE_LECTURE_VENTES';
    public const LECTURE_CLIENTS = 'ROLE_LECTURE_CLIENTS';
    public const LECTURE_PAO = 'ROLE_LECTURE_PAO';

    /**
     * @return array<string, array{libelle: string, detail: string, groupe: string}>
     */
    public static function catalogue(): array
    {
        return [
            'ROLE_CLIENT' => ['libelle' => 'Clients', 'detail' => 'voir, créer, modifier', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_DEVIS' => ['libelle' => 'Devis', 'detail' => 'créer, envoyer, relancer', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_COMMANDE' => ['libelle' => 'Commandes', 'detail' => 'créer, modifier, statuts', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_FACTURE' => ['libelle' => 'Factures', 'detail' => 'émettre, imprimer', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_PAIEMENT_ENCAISSER' => ['libelle' => 'Encaissements', 'detail' => 'saisir un paiement', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_TRESORERIE_SAISIR' => ['libelle' => 'Dépenses et recettes', 'detail' => 'saisir en trésorerie', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_TRESORERIE_TRANSFERER' => ['libelle' => 'Virements entre comptes', 'detail' => 'caisse, banque, Orange Money', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_JOURNAL_CAISSE' => ['libelle' => 'Clôture de caisse', 'detail' => 'journal de caisse', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_PREPRESSE' => ['libelle' => 'Prépresse', 'detail' => 'contrôle des fichiers, BAT', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_PRODUCTION_VOIR' => ['libelle' => 'Production', 'detail' => 'voir le planning', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_PRODUCTION_GERER' => ['libelle' => 'Statuts de production', 'detail' => 'démarrer, terminer une tâche', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_ACTIONS_GERER' => ['libelle' => 'Actions commerciales de l\'équipe', 'detail' => 'journal, contrôle au hasard, lignes manuelles', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_RH' => ['libelle' => 'Administrateur RH', 'detail' => 'pointages, photos de pointage, corrections', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_LIVRAISON' => ['libelle' => 'Livraisons', 'detail' => 'tournée, remise au client', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_ENTRETIEN' => ['libelle' => 'Entretien des locaux', 'detail' => 'planning d\'entretien, validation par photo', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_SITE_VITRINE' => ['libelle' => 'Site vitrine', 'detail' => 'pages, réalisations, avis, conseils et partenaires du site', 'groupe' => self::GROUPE_FONCTIONS],
            'ROLE_STATS_PERSO' => ['libelle' => 'Ses propres statistiques', 'detail' => 'ventes et encaissements de la personne', 'groupe' => self::GROUPE_CHIFFRES],
            'ROLE_STATS_VENTES' => ['libelle' => 'Indicateurs commerciaux', 'detail' => 'devis envoyés, taux de transformation, panier moyen', 'groupe' => self::GROUPE_CHIFFRES],
            'ROLE_STATS_GLOBAL' => ['libelle' => 'Chiffre d\'affaires global', 'detail' => 'chiffres de l\'accueil, écrans Statistiques', 'groupe' => self::GROUPE_CHIFFRES],
            'ROLE_COUT_MARGE' => ['libelle' => 'Marges et coûts de revient', 'detail' => 'des commandes et des produits du client', 'groupe' => self::GROUPE_CHIFFRES],
            'ROLE_PAIEMENT_VOIR' => ['libelle' => 'Paiements', 'detail' => 'paiements des commandes, écran Caisse', 'groupe' => self::GROUPE_CHIFFRES],
            'ROLE_TRESORERIE_VOIR' => ['libelle' => 'Trésorerie et soldes', 'detail' => 'comptes, mouvements', 'groupe' => self::GROUPE_CHIFFRES],
            'ROLE_COMPTABILITE' => ['libelle' => 'Comptabilité et patrimoine', 'detail' => 'écritures, dotations aux amortissements, valeurs des biens', 'groupe' => self::GROUPE_CHIFFRES],
            self::LECTURE_VENTES => ['libelle' => 'Ventes et caisse en lecture seule', 'detail' => 'voir les devis, commandes et factures sans les modifier', 'groupe' => self::GROUPE_LECTURE],
            self::LECTURE_CLIENTS => ['libelle' => 'Clients en lecture seule', 'detail' => 'voir les fiches sans les modifier', 'groupe' => self::GROUPE_LECTURE],
            self::LECTURE_PAO => ['libelle' => 'Montage PAO en lecture seule', 'detail' => 'voir les fichiers et BAT sans les modifier', 'groupe' => self::GROUPE_LECTURE],
        ];
    }

    /**
     * @return array<string, array<string, array{libelle: string, detail: string, groupe: string}>>
     */
    public static function parGroupe(): array
    {
        $groupes = [];

        foreach (self::catalogue() as $role => $droit) {
            $groupes[$droit['groupe']][$role] = $droit;
        }

        return $groupes;
    }

    /**
     * Tous les droits sauf les restrictions (lecture seule) : ceux de
     * l'administrateur.
     *
     * @return list<string>
     */
    public static function sansRestriction(): array
    {
        return array_keys(array_filter(self::catalogue(), static fn (array $d): bool => self::GROUPE_LECTURE !== $d['groupe']));
    }

    /**
     * Garde les droits connus, sans doublon, dans l'ordre du catalogue.
     *
     * @param array<mixed> $droits
     *
     * @return list<string>
     */
    public static function filtrer(array $droits): array
    {
        return array_values(array_intersect(array_keys(self::catalogue()), $droits));
    }
}
