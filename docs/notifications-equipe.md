# Notifications de l'équipe

Maquettes NT01 à NT05 de Yaya (`maquettes/alertes-notifications`). Une seule sorte de notification pour toute l'équipe, rangée en **quatre niveaux** : plus c'est grave, plus c'est visible. Une info ne dérange jamais comme une urgence.

## Les quatre niveaux

| Niveau | À l'écran | Son | Téléphone (PWA) | Exemples |
|---|---|---|---|---|
| **Critique** | bannière rouge en haut de toutes les pages, jusqu'à la résolution | oui, à l'arrivée | oui, même en heures calmes | machine en panne, internet coupé, caisse non clôturée à 19 h |
| **Urgent** | petite fenêtre en bas (8 s), revient toutes les 30 min tant que rien n'est fait | court | oui (pas en heures calmes) | client sans réponse, BAT en retard, facture échue, plafond de crédit, mention |
| **À faire** | petite fenêtre à l'arrivée, compteur de la cloche, liste | non (sauf acompte reçu) | oui (pas en heures calmes) | acompte à valider, BAT validé à lancer, devis à relancer, stock bas, message d'un client |
| **Info** | seulement la liste | non | non | commande prête, retard de pointage |

Les anciennes notifications (sans sorte) restent des infos : elles disparaissent une fois lues.

**Fenêtre bloquante**, très rare : seulement quand une action fait perdre de l'argent. Aujourd'hui, un seul cas : **confirmer ou lancer une commande d'un client qui dépasse son plafond de crédit** (règle de `ControleCreditClientService` : déjà dû sur les commandes précédentes > plafond). La commande est enregistrée mais reste « à confirmer », et la fenêtre propose :

- **Demander un acompte** (avec le droit d'encaisser) : ouvre la caisse sur le client et la commande ;
- **Accord direction** : la Direction reçoit une notification urgente « Accord demandé » avec **Décider** (donner l'accord ou refuser). Un administrateur donne l'accord tout de suite. L'accord vaut pour cette commande seulement (gardé comme notification résolue « accord ») ; la personne qui l'a demandé est prévenue.

Les montants de la fenêtre et de la demande ne s'affichent qu'avec le droit « Voir les paiements » (ROLE_PAIEMENT_VOIR) ; sans lui : « Salon Espoir dépasse son plafond de crédit ».

## Où les voir

- **Cloche** (en haut, toutes les pages) : compteur, liste par niveau, onglets Tout / Urgent / À faire / Mentions, « Tout marquer lu », « Voir toutes les notifications ».
- **Centre de notifications** (Accueil › Notifications, `/notifications`) : filtres (toutes, non lues, urgentes, à faire, mentions, reportées, historique), par domaine (atelier, finances, messages, commandes, stock, devis, équipe, système), bouton d'action sur chaque ligne, **Plus tard** (1 h, 3 h, demain 9 h, lundi après un samedi), résumé du jour (urgentes non traitées, traitées aujourd'hui, temps moyen de traitement), reportées, son coupé ou non pour soi.
- L'écran se met à jour **toutes les 30 secondes** (et au retour sur l'onglet). Mercure n'est pas installé : pas de temps réel au-delà.
- Le son se débloque au premier clic dans la page (règle des navigateurs) ; sinon un bouton « Activer le son des alertes » apparaît.

Une notification **disparaît d'elle-même quand l'action est faite** : la source ne la voit plus (acompte validé, client répondu, BAT envoyé, devis relancé, stock commandé, machine réparée, caisse clôturée, internet revenu), ou la décision est prise (plafond). Les événements sans source (mention, commande prête) sont traités en les ouvrant.

## Qui reçoit quoi

Chacun ne voit **que ses notifications** (une ligne par personne). Les destinataires sont choisis par la source (la personne assignée, le graphiste de la conception) ou par droit (ROLE_PREPRESSE, ROLE_PAIEMENT_ENCAISSER…), puis filtrés par les réglages de son rôle. Les montants (« Acompte reçu · 30 000 F ») ne s'affichent qu'à qui a le droit du montant (ROLE_PAIEMENT_VOIR pour les paiements et factures ; la Direction voit tout) ; les SMS et WhatsApp ne contiennent jamais de montant.

Règles anti-bruit :

- une même alerte n'est envoyée qu'une fois, puis regroupée (compteur « 3 fois ») ;
- chacun ne reçoit que ce qui concerne son rôle ;
- en heures calmes, seules les critiques dérangent (son, téléphone, petite fenêtre) ; les autres attendent dans la liste ;
- une alerte résolue disparaît toute seule ;
- une ancienne notification du même lien est marquée lue quand la nouvelle arrive (pas de doublon dans la cloche).

## Sources branchées

| Sorte | Niveau | Source | Destinataires |
|---|---|---|---|
| Machine en panne | critique | incident machine « arrêt » non résolu | droits « voir la production » et chef d'atelier (la Direction a tous les droits) |
| Internet coupé | critique | synchronisation en alerte (`Synchro::alerte`) | tous ; action réservée à la Direction |
| Caisse non clôturée | critique | après l'heure réglée (19 h), caisse avec paiements validés du jour sans clôture | Direction + titulaire de la caisse |
| Client sans réponse | urgent | conversation client en attente au-delà du délai (`DelaisReponse`) | agent assigné, sinon encaissement |
| BAT en retard | urgent | conception avec échéance BAT dépassée | graphiste, sinon prépresse |
| Facture échue | urgent | action de caisse « facture » à faire | agent de l'action |
| Plafond de crédit | urgent | demande d'accord (fenêtre bloquante) | Direction |
| Mention | urgent | note interne d'une conversation qui cite quelqu'un | la personne citée |
| BAT validé | à faire | contrôle prépresse BAT validé, pas encore envoyé | prépresse |
| Acompte reçu | à faire | paiement en attente de validation (60 jours) | encaissement |
| Message d'un client | à faire | conversation en attente, dans les délais | agent assigné, sinon encaissement |
| Devis à relancer | à faire | action de caisse « devis » à faire | agent de l'action |
| Stock bas | à faire | article « à commander » | Direction |
| Commande prête | info | fin de production (`ActionsOrdre`) | livraison, commercial |
| Retard de pointage | info | pointages du jour en retard | RH |

Les sources sont relues au plus une fois par minute pendant le polling et à chaque passage de `app:notifications`, **sur l'ERP local seulement** (le site en ligne n'a pas l'atelier).

Sources demandées mais absentes, non inventées :

- **Trésorière** : aucun rôle « Trésorière » n'existe dans les profils ; les alertes d'argent vont à l'encaissement et à la Direction.
- **Nouvelle commande en ligne**, **devis ouvert une 2e fois** (maquette NT03) : restent les anciennes notifications (info), sans bouton dédié.
- **Plafond « utilisé à 67 % »** (maquette NT01) : pas de seuil d'avertissement dans `ControleCreditClientService` ; seul le dépassement bloque.
- **Messagerie interne de l'équipe** : construite à part (branche messagerie interne) ; elle crée ses notifications par la méthode publique ci-dessous.

## Créer une notification depuis un autre module

```php
use App\Service\NotificationsEquipe\NotificationsEquipe;

// $equipe : service injecté (autowiring)
$equipe->notifier(
    'mention',                         // sorte (TypesNotification::TYPES) : fixe le niveau
    'Kadiatou vous écrit',             // titre (sans montant)
    [$utilisateur, 'ROLE_PREPRESSE'],  // personnes et/ou droits
    detail: 'Peux-tu regarder le BAT ?',
    action: 'Ouvrir',                  // libellé du bouton
    route: 'app_messages_clients_index',
    parametres: [],
    empreinte: 'mention:fil:42:7',     // même empreinte = regroupée
    montant: null,                     // affiché seulement avec $droitMontant
    droitMontant: null,
);
$em->flush(); // notifier() n'appelle pas flush()

// L'action est faite ailleurs : elle disparaît pour tout le monde.
$equipe->resoudre('mention:fil:42:7');
```

Une nouvelle sorte s'ajoute dans `TypesNotification::TYPES` (libellé, niveau, domaine, droits, icône) et, au besoin, dans `PAR_DEFAUT` des rôles.

## Réglages (Réglages › Paramètres › Notifications de l'équipe)

Écran `/reglages/notifications`, Direction seulement, **modifiable sur l'ERP local** (en ligne : lecture seule, comme les tuiles de Paramètres).

- **Matrice sorte × rôle** (un onglet par rôle de l'ERP) : Application, Son, WhatsApp, SMS, E-mail. Sans « Application », rien ne part pour ce rôle.
- **Heures calmes** : par défaut du lundi au samedi de 19 h à 7 h 30, le dimanche toute la journée.
- **Remontée** : urgent non traité après 30 min → responsable du rôle (choisi par rôle ; sinon la Direction), puis Direction après 60 min ; critique non traité après 1 h → Direction, par l'application et par WhatsApp si la case WhatsApp de la Direction est cochée. 0 : pas de remontée. Une notification reportée n'est pas remontée ; le délai repart à la fin du report.
- **Rappel** des urgentes : toutes les 30 min (téléphone).
- **Heure de clôture de caisse** : 19 h.

Chacun peut couper le son pour lui-même (centre de notifications).

## SMS, WhatsApp, e-mail : coûts

- **SMS** (Orange, `OrangeSmsService`) : payant, décompté des crédits SMS (`CreditsMessages`). **Coupé par défaut** pour toutes les sortes ; on ne peut le cocher que pour une sorte critique.
- **WhatsApp** (`WhatsAppService`, API Meta, modèle approuvé obligatoire) : payant par conversation. Coché par défaut seulement pour la Direction, sur « Machine en panne » et « Internet coupé ». Seulement pour les critiques. Il faut `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID` et **`WHATSAPP_TEMPLATE_ALERTE_EQUIPE`** (modèle « Utility » à faire approuver par Meta, deux variables : titre, détail). Sans eux, l'écran affiche « WhatsApp automatique : clé Meta non réglée, rien ne part. »
- **E-mail** : gratuit ; `MAILER_DSN` et `MAILER_FROM_ADDRESS`, adresse de la fiche employé. Coché par défaut pour la Direction sur « Machine en panne » et « Facture échue ».
- **Plafond journalier** des envois payants (SMS + WhatsApp) : 10 par jour par défaut, réglable.
- Les envois partent à la fin de la requête ou de la commande, et sont tracés dans le journal des envois (type « Alerte de l'équipe »).

## Téléphone (maquette NT05)

- Employés : la notification part sur le téléphone par la PWA (`EnvoiPush`, abonnement existant), avec « ⚠ » pour le critique, regroupée par empreinte. Pas d'envoi pour l'info ni, hors critique, en heures calmes.
- Clients : l'espace client a déjà son fil (commande prête, BAT à valider, paiement reçu, message, devis, facture ; docs/espace-client.md). Les **promotions** de la maquette NT05 ne sont pas dans ce fil : non ajoutées ici.

## Tâche planifiée

`app:notifications` relit les sources, résout ce qui est réglé, fait les remontées et les rappels. Sur l'ERP local, **chaque minute**, avec la synchronisation (`scripts\windows\synchro.bat`, déjà planifiée par `installer_tache_synchro.bat`). Sous Linux ou Mac :

```
* * * * * cd /chemin/du/projet && php bin/console app:notifications -q >> var/log/notifications.log 2>&1
```

Sur le site en ligne, elle refuse de tourner : ne pas l'ajouter au cron du serveur.

## Démonstration

`app:demo` ajoute (identifiants à partir de 905001) une mention urgente pour demo.aminata, une demande d'accord de plafond pour demo.kadiatou, des « commande prête » (info), une mention reportée pour demo.seydou et un historique de notifications traitées. Les alertes qui viennent des données (machine en panne, BAT en retard, acomptes, stock bas, devis et factures à relancer) naissent à la première ouverture de l'ERP ou au passage de `app:notifications`.

## Limites

- Polling toutes les 30 s, pas de temps réel.
- Les sources sont relues sur l'ERP local seulement ; en ligne, la cloche montre les notifications déjà présentes dans la base du site.
- L'escalade « au responsable du rôle » demande de choisir ce responsable dans les réglages ; sinon elle va à la Direction.
- Le son dépend du navigateur (déblocage au premier clic).
