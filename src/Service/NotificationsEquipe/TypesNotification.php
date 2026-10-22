<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Notification;

/**
 * Sortes de notifications de l'équipe (maquettes NT01 à NT04,
 * docs/notifications-equipe.md) : niveau, domaine (filtre du centre),
 * droits de qui peut la recevoir, droit qui ouvre son bouton d'action,
 * icône, et réglages par défaut de chaque rôle (application, son,
 * WhatsApp, SMS, e-mail).
 *
 * Le niveau est fixe par sorte : la gravité ne se règle pas, seuls les
 * destinataires et les moyens se règlent (écran Réglages › Notifications).
 */
final class TypesNotification
{
    public const CANAUX = ['app' => 'Application', 'son' => 'Son', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'];

    /** Canaux payants (opérateur, fournisseur) : réservés au niveau critique. */
    public const CANAUX_PAYANTS = ['whatsapp', 'sms'];

    public const DOMAINES = [
        'atelier' => ['Atelier', 'fas fa-print'],
        'finances' => ['Finances', 'fas fa-wallet'],
        'messages' => ['Messages', 'far fa-comment-dots'],
        'commandes' => ['Commandes', 'fas fa-box'],
        'stock' => ['Stock', 'fas fa-cube'],
        'achats' => ['Achats', 'fas fa-truck'],
        'devis' => ['Devis', 'far fa-file-alt'],
        'equipe' => ['Équipe', 'far fa-clock'],
        'systeme' => ['Système', 'fas fa-wifi'],
    ];

    /**
     * code => libellé (réglages), niveau, domaine, droits pour la recevoir
     * (un seul suffit ; vide : tout le personnel), droit du bouton d'action
     * (null : celui de la route suffit), icône.
     *
     * @var array<string, array{libelle: string, niveau: string, domaine: string, droits: list<string>, droit_action: ?string, icone: string}>
     */
    public const TYPES = [
        'machine_panne' => ['libelle' => 'Machine en panne', 'niveau' => Notification::CRITIQUE, 'domaine' => 'atelier', 'droits' => ['ROLE_PRODUCTION_VOIR', 'ROLE_ATELIER_CHEF'], 'droit_action' => 'ROLE_PRODUCTION_VOIR', 'icone' => 'fas fa-print'],
        'synchro_coupee' => ['libelle' => 'Internet coupé / synchronisation', 'niveau' => Notification::CRITIQUE, 'domaine' => 'systeme', 'droits' => [], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-wifi'],
        'caisse_non_cloturee' => ['libelle' => 'Caisse non clôturée à l\'heure', 'niveau' => Notification::CRITIQUE, 'domaine' => 'finances', 'droits' => ['ROLE_JOURNAL_CAISSE'], 'droit_action' => 'ROLE_PAIEMENT_ENCAISSER', 'icone' => 'fas fa-cash-register'],
        'client_sans_reponse' => ['libelle' => 'Client sans réponse (délai dépassé)', 'niveau' => Notification::URGENT, 'domaine' => 'messages', 'droits' => ['ROLE_CLIENT', 'ROLE_COMMANDE', 'ROLE_DEVIS', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_FACTURE', 'ROLE_PREPRESSE'], 'droit_action' => null, 'icone' => 'far fa-comment-dots'],
        'bat_retard' => ['libelle' => 'BAT en retard', 'niveau' => Notification::URGENT, 'domaine' => 'atelier', 'droits' => ['ROLE_PREPRESSE'], 'droit_action' => 'ROLE_PREPRESSE', 'icone' => 'fas fa-pencil-ruler'],
        'facture_echue' => ['libelle' => 'Facture échue', 'niveau' => Notification::URGENT, 'domaine' => 'finances', 'droits' => ['ROLE_PAIEMENT_ENCAISSER'], 'droit_action' => 'ROLE_PAIEMENT_ENCAISSER', 'icone' => 'fas fa-file-invoice'],
        'plafond_credit' => ['libelle' => 'Plafond de crédit : accord demandé', 'niveau' => Notification::URGENT, 'domaine' => 'finances', 'droits' => ['ROLE_ADMIN'], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-hand-holding-usd'],
        'mention' => ['libelle' => 'Mention ou message privé', 'niveau' => Notification::URGENT, 'domaine' => 'messages', 'droits' => [], 'droit_action' => null, 'icone' => 'fas fa-at'],
        'bat_valide' => ['libelle' => 'BAT validé par le client', 'niveau' => Notification::A_FAIRE, 'domaine' => 'atelier', 'droits' => ['ROLE_PREPRESSE'], 'droit_action' => 'ROLE_PREPRESSE', 'icone' => 'fas fa-check-double'],
        'acompte_recu' => ['libelle' => 'Acompte ou paiement reçu à valider', 'niveau' => Notification::A_FAIRE, 'domaine' => 'finances', 'droits' => ['ROLE_PAIEMENT_ENCAISSER'], 'droit_action' => 'ROLE_PAIEMENT_ENCAISSER', 'icone' => 'fas fa-coins'],
        'message_client' => ['libelle' => 'Nouveau message d\'un client', 'niveau' => Notification::A_FAIRE, 'domaine' => 'messages', 'droits' => ['ROLE_CLIENT', 'ROLE_COMMANDE', 'ROLE_DEVIS', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_FACTURE', 'ROLE_PREPRESSE'], 'droit_action' => null, 'icone' => 'far fa-envelope'],
        'devis_relancer' => ['libelle' => 'Devis à relancer', 'niveau' => Notification::A_FAIRE, 'domaine' => 'devis', 'droits' => ['ROLE_PAIEMENT_ENCAISSER'], 'droit_action' => 'ROLE_PAIEMENT_ENCAISSER', 'icone' => 'far fa-file-alt'],
        'stock_bas' => ['libelle' => 'Stock sous le seuil', 'niveau' => Notification::A_FAIRE, 'domaine' => 'stock', 'droits' => ['ROLE_ADMIN'], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-cube'],
        // Achats (maquettes SU06 et SU07, docs/achats.md) : app:achats:alertes, pour la direction.
        'achat_stock_bas' => ['libelle' => 'Achats : article à commander (stock bas)', 'niveau' => Notification::A_FAIRE, 'domaine' => 'achats', 'droits' => ['ROLE_ADMIN'], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-box-open'],
        'achat_prix_hausse' => ['libelle' => 'Achats : prix fournisseur en hausse', 'niveau' => Notification::A_FAIRE, 'domaine' => 'achats', 'droits' => ['ROLE_ADMIN'], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-chart-line'],
        'achat_fournisseur_retard' => ['libelle' => 'Achats : fournisseur en retard', 'niveau' => Notification::A_FAIRE, 'domaine' => 'achats', 'droits' => ['ROLE_ADMIN'], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-truck'],
        'achat_commande_oubliee' => ['libelle' => 'Achats : commande oubliée', 'niveau' => Notification::A_FAIRE, 'domaine' => 'achats', 'droits' => ['ROLE_ADMIN'], 'droit_action' => 'ROLE_ADMIN', 'icone' => 'fas fa-redo-alt'],
        'commande_prete' => ['libelle' => 'Commande prête', 'niveau' => Notification::INFO, 'domaine' => 'commandes', 'droits' => ['ROLE_COMMANDE', 'ROLE_LIVRAISON'], 'droit_action' => null, 'icone' => 'fas fa-box'],
        'retard_pointage' => ['libelle' => 'Retard de pointage', 'niveau' => Notification::INFO, 'domaine' => 'equipe', 'droits' => ['ROLE_RH'], 'droit_action' => 'ROLE_RH', 'icone' => 'far fa-clock'],
    ];

    /** Rôle de la Direction : toujours dans les réglages, même absent de la table des rôles. */
    /** Événements sans source à surveiller : ouvrir la notification suffit à la traiter. */
    public const RESOLUS_A_L_OUVERTURE = ['mention', 'commande_prete'];

    public const DIRECTION = 'ROLE_ADMIN';

    /**
     * Ce que chaque rôle reçoit par défaut (application), d'après la
     * maquette NT04 et le métier du rôle. Un rôle créé à la main reçoit,
     * par défaut, ce que ses droits lui permettent.
     *
     * @var array<string, list<string>>
     */
    public const PAR_DEFAUT = [
        'ROLE_ADMIN' => ['machine_panne', 'synchro_coupee', 'caisse_non_cloturee', 'client_sans_reponse', 'bat_retard', 'facture_echue', 'plafond_credit', 'mention', 'bat_valide', 'acompte_recu', 'devis_relancer', 'stock_bas', 'achat_stock_bas', 'achat_prix_hausse', 'achat_fournisseur_retard', 'achat_commande_oubliee', 'commande_prete', 'retard_pointage'],
        'ROLE_CAISSE_COMMANDE' => ['synchro_coupee', 'caisse_non_cloturee', 'client_sans_reponse', 'facture_echue', 'mention', 'acompte_recu', 'message_client', 'devis_relancer', 'commande_prete'],
        'ROLE_GRAPHISTE' => ['synchro_coupee', 'client_sans_reponse', 'bat_retard', 'mention', 'bat_valide', 'message_client'],
        'ROLE_PRODUCTION' => ['machine_panne', 'synchro_coupee', 'mention'],
        'ROLE_LIVREUR' => ['mention', 'commande_prete'],
    ];

    /** Son par défaut : critiques, urgents, et l'acompte reçu (maquette NT04). */
    public const SON_PAR_DEFAUT = ['acompte_recu'];

    /** E-mail par défaut pour la Direction (maquette NT04). */
    public const EMAIL_DIRECTION_PAR_DEFAUT = ['machine_panne', 'facture_echue'];

    /** WhatsApp par défaut pour la Direction : les critiques qui arrêtent l'imprimerie (maquette NT04). */
    public const WHATSAPP_DIRECTION_PAR_DEFAUT = ['machine_panne', 'synchro_coupee'];

    /** @return array{libelle: string, niveau: string, domaine: string, droits: list<string>, droit_action: ?string, icone: string} */
    public static function type(string $code): array
    {
        return self::TYPES[$code] ?? ['libelle' => 'Notification', 'niveau' => Notification::INFO, 'domaine' => 'systeme', 'droits' => [], 'droit_action' => null, 'icone' => 'fas fa-bell'];
    }

    public static function existe(string $code): bool
    {
        return isset(self::TYPES[$code]);
    }

    public static function niveau(string $code): string
    {
        return self::type($code)['niveau'];
    }

    /** Un canal payant n'est permis que pour une sorte critique. */
    public static function canalPermis(string $code, string $canal): bool
    {
        return !\in_array($canal, self::CANAUX_PAYANTS, true) || Notification::CRITIQUE === self::niveau($code);
    }

    /**
     * Réglages par défaut d'un rôle pour une sorte.
     *
     * @param list<string> $droitsDuRole droits du rôle (pour un rôle créé à la main)
     *
     * @return array{app: bool, son: bool, whatsapp: bool, sms: bool, email: bool}
     */
    public static function defaut(string $role, string $code, array $droitsDuRole = []): array
    {
        $niveau = self::niveau($code);
        $recoit = isset(self::PAR_DEFAUT[$role])
            ? \in_array($code, self::PAR_DEFAUT[$role], true)
            : (self::TYPES[$code]['droits'] ?? []) === [] || [] !== array_intersect(self::TYPES[$code]['droits'] ?? [], $droitsDuRole);
        $direction = self::DIRECTION === $role;

        return [
            'app' => $recoit,
            'son' => $recoit && (\in_array($niveau, [Notification::CRITIQUE, Notification::URGENT], true) || \in_array($code, self::SON_PAR_DEFAUT, true)),
            'whatsapp' => $recoit && $direction && \in_array($code, self::WHATSAPP_DIRECTION_PAR_DEFAUT, true),
            // SMS payant : coupé par défaut, même pour les critiques (Yaya).
            'sms' => false,
            'email' => $recoit && $direction && \in_array($code, self::EMAIL_DIRECTION_PAR_DEFAUT, true),
        ];
    }
}
