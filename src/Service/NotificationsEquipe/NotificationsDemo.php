<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Notification;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Notifications de l'équipe dans la démonstration (app:demo,
 * docs/notifications-equipe.md) : les événements qu'aucune source ne
 * recrée (mention, commande prête, demande d'accord de plafond), une
 * notification reportée et un historique de notifications traitées.
 * Les alertes qui viennent des données (machine en panne, BAT en retard,
 * acompte à valider, stock bas…) sont créées par les sources elles-mêmes
 * à la première ouverture de l'ERP ou au passage de app:notifications.
 *
 * Identifiants à partir de 905001 (la démonstration des anciennes
 * notifications occupe 900001 à 900499) ; la purge de demo-purge.sql
 * (id >= 900000) les enlève. Empreintes « demo:… » : une vraie alerte
 * n'est jamais confondue avec elles.
 */
final class NotificationsDemo
{
    public const PREMIER_ID = 905001;

    /** Comptes de démonstration : direction, caisse, graphiste, machiniste, livreur. */
    private const KADIATOU = 900001;
    private const AMINATA = 900003;
    private const SEYDOU = 900006;
    private const OUMAR = 900008;
    private const BAKARY = 900010;

    public static function charger(Connection $connexion, ?\DateTimeImmutable $maintenant = null): int
    {
        $maintenant ??= new \DateTimeImmutable();
        $utilisateurs = array_map('intval', $connexion->fetchFirstColumn('SELECT id FROM `user` WHERE id IN (?)', [[self::KADIATOU, self::AMINATA, self::SEYDOU, self::OUMAR, self::BAKARY]], [ArrayParameterType::INTEGER]));
        $commandePrete = $connexion->fetchOne('SELECT id FROM commandes WHERE id >= 900000 ORDER BY id DESC LIMIT 1');
        $commandeBloquee = $connexion->fetchOne('SELECT id FROM commandes WHERE id >= 900000 AND statut = 0 ORDER BY id LIMIT 1');
        $numero = static fn (mixed $id): string => (string) ($connexion->fetchOne('SELECT numero FROM commandes WHERE id = ?', [$id]) ?: '#'.$id);

        $lignes = [];
        $il = static fn (string $duree): string => $maintenant->modify('-'.$duree)->format('Y-m-d H:i:s');
        // Urgent : une collègue cite Aminata dans une conversation.
        $lignes[] = ['destinataire_id' => self::AMINATA, 'type' => 'mention', 'niveau' => Notification::URGENT, 'message' => 'Kadiatou vous cite (Question générale)', 'detail' => '@aminata peux-tu rappeler ce client avant midi ?', 'action_libelle' => 'Ouvrir', 'route' => 'app_messages_clients_index', 'route_parametres' => '[]', 'empreinte' => 'demo:mention:1', 'date_creation' => $il('12 minutes')];
        if (false !== $commandePrete) {
            foreach ([self::AMINATA, self::BAKARY, self::KADIATOU] as $i => $u) {
                $lignes[] = ['destinataire_id' => $u, 'type' => 'commande_prete', 'niveau' => Notification::INFO, 'message' => 'Commande '.$numero($commandePrete).' prête', 'detail' => 'Production terminée : prête pour la suite.', 'action_libelle' => 'Voir', 'route' => 'app_commandes_show', 'route_parametres' => json_encode(['id' => (int) $commandePrete]), 'empreinte' => 'demo:commande_prete:'.$commandePrete, 'date_creation' => $il((25 + $i) .' minutes')];
            }
        }
        if (false !== $commandeBloquee) {
            // Urgent, direction : la caisse demande l'accord pour dépasser le plafond de crédit.
            $lignes[] = ['destinataire_id' => self::KADIATOU, 'type' => 'plafond_credit', 'niveau' => Notification::URGENT, 'message' => 'Accord demandé : plafond dépassé pour '.$numero($commandeBloquee), 'detail' => 'Demandé par Aminata pour lancer la commande.', 'action_libelle' => 'Décider', 'route' => 'app_plafond_decider', 'route_parametres' => json_encode(['id' => (int) $commandeBloquee, 'par' => self::AMINATA]), 'empreinte' => 'demo:plafond:commande:'.$commandeBloquee, 'montant' => 325000, 'droit_montant' => 'ROLE_PAIEMENT_VOIR', 'date_creation' => $il('8 minutes')];
        }
        // Reportée : Seydou a remis une mention à plus tard.
        $lignes[] = ['destinataire_id' => self::SEYDOU, 'type' => 'mention', 'niveau' => Notification::URGENT, 'message' => 'Kadiatou vous cite (Logo Pharmacie Djoliba)', 'detail' => 'Le client veut une version en bleu.', 'action_libelle' => 'Ouvrir', 'route' => 'app_messages_clients_index', 'route_parametres' => '[]', 'empreinte' => 'demo:mention:2', 'date_creation' => $il('2 hours'), 'reportee_jusqua' => $maintenant->modify('+3 hours')->format('Y-m-d H:i:s')];
        // Historique : notifications traitées (centre, onglet Historique, et temps moyen de traitement).
        foreach ([
            [self::KADIATOU, 'machine_panne', Notification::CRITIQUE, 'Machine en panne : Konica C6100', 'Arrêt signalé par Oumar.', '1 day', 95, Notification::RESOLUE_AUTO],
            [self::KADIATOU, 'acompte_recu', Notification::A_FAIRE, 'Acompte reçu · Salon Espoir', 'Orange Money · à valider', '5 hours', 14, Notification::RESOLUE_AUTO],
            [self::AMINATA, 'client_sans_reponse', Notification::URGENT, 'Client sans réponse depuis 35 min', 'Garage Faso', '3 hours', 9, Notification::RESOLUE_AUTO],
            [self::AMINATA, 'acompte_recu', Notification::A_FAIRE, 'Acompte reçu · Awa Diallo', 'Espèces · à valider', '6 hours', 4, Notification::RESOLUE_AUTO],
            [self::SEYDOU, 'bat_valide', Notification::A_FAIRE, 'BAT validé · Cartes de visite', 'À lancer en production', '1 day', 30, Notification::RESOLUE_AUTO],
            [self::OUMAR, 'machine_panne', Notification::CRITIQUE, 'Machine en panne : Konica C6100', 'Arrêt signalé par Oumar.', '1 day', 95, Notification::RESOLUE_AUTO],
            [self::BAKARY, 'commande_prete', Notification::INFO, 'Commande CMD-D00012 prête', 'Production terminée : prête pour la suite.', '2 days', 40, Notification::RESOLUE_LUE],
        ] as $i => [$u, $type, $niveau, $titre, $detail, $depuis, $minutes, $resolution]) {
            $creee = $maintenant->modify('-'.$depuis);
            $lignes[] = ['destinataire_id' => $u, 'type' => $type, 'niveau' => $niveau, 'message' => $titre, 'detail' => $detail, 'route' => null, 'route_parametres' => null, 'empreinte' => 'demo:historique:'.$i, 'date_creation' => $creee->format('Y-m-d H:i:s'), 'lue' => 1, 'resolue_le' => $creee->modify('+'.$minutes.' minutes')->format('Y-m-d H:i:s'), 'resolution' => $resolution];
        }

        $n = 0;
        foreach ($lignes as $ligne) {
            if (!\in_array($ligne['destinataire_id'], $utilisateurs, true)) {
                continue;
            }
            $connexion->insert('notifications', ['id' => self::PREMIER_ID + $n, 'lue' => 0, 'compteur' => 1, 'escalade' => 0, 'silencieuse' => 0] + $ligne);
            ++$n;
        }

        return $n;
    }
}
