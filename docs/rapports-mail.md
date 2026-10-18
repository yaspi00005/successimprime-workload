# Rapports du jour, de la semaine et du mois par e-mail

Construit le 18/10/2026 sur l'ERP local, à la demande de Yaya (16/10) : « Il faut aussi prévoir l'envoi des rapports hebdomadaires, journalier et mensuel par mail à l'administration ». Règle du propriétaire : « toute info sensible doit tenir compte des droits et rôles, tout le monde ne doit pas tout voir ». Chaque destinataire reçoit **sa** version : une partie qu'il n'a pas le droit de voir n'est ni calculée, ni écrite dans l'e-mail, ni dans le PDF joint.

## Les trois rapports

| Rapport | Part (par défaut) | Période | Comparé à |
|---|---|---|---|
| Du jour | chaque jour d'ouverture à 20 h | la journée (0 h – 24 h) | le même jour de la semaine d'avant (un samedi avec le samedi d'avant) |
| De la semaine | chaque lundi à 7 h | la semaine passée, du lundi au dimanche (semaine ISO) | la semaine d'avant |
| Du mois | le 1er à 7 h | le mois passé | le mois d'avant |

Les jours et heures se changent dans Réglages › Paramètres › tuile **Rapports par e-mail** (Direction) : chaque rapport oui ou non, l'heure, le jour de la semaine, le jour du mois (1 à 28), « seulement les jours d'ouverture » pour le rapport du jour (jours travaillés et jours fériés de la tuile Horaires), le PDF joint, et l'adresse de l'ERP dans l'atelier (pour le bouton « Ouvrir dans l'ERP »).

## Contenu

Dans l'ordre de l'e-mail, chaque partie seulement avec son droit :

| Partie | Ce qu'elle contient | Droit |
|---|---|---|
| À surveiller | commandes en retard, BAT sans réponse depuis 48 h, réclamations ouvertes depuis plus de 3 jours, devis à relancer, tâches en retard ; puis, avec les droits correspondants, CA sous l'objectif, taux d'encaissement, paiements à valider, créances échues, trésorerie sous le seuil, budget dépassé, marge basse, stock bas, absences | chaque alerte suit le droit de son chiffre |
| Activité | commandes, devis émis et signés, m² imprimés, nouveaux clients, livraisons remises et échecs, BAT validés et en attente, commandes en retard, devis à relancer, réclamations reçues et ouvertes, comparés à la période d'avant et aux objectifs du mois (au prorata des jours) | « Chiffre d'affaires global » (le droit du rapport) |
| Noms des clients | noms des nouveaux clients, objet et client des réclamations ouvertes, plus gros restes à payer | + « Clients » ; sans lui, des nombres seulement |
| Chiffre d'affaires | CA facturé, objectif de la période, avancement du mois et projection | + « Indicateurs commerciaux » |
| Encaissements, créances | encaissé par moyen de paiement (le paiement par solde ou par points ne compte pas), taux d'encaissement, paiements à valider, reste à encaisser, échues, plus de 60 jours | + « Paiements » |
| Dépenses et trésorerie | dépenses (salaires compris) par catégorie, part du budget (rapport du mois), soldes des comptes, seuil d'alerte | + « Trésorerie et soldes » |
| Marge | taux de marge brute du mois et du mois d'avant (rapport du mois seulement) | + « Marges et coûts de revient » |
| Équipe | présents le dernier jour, retards et absences non justifiées, avec les noms (une absence couverte par une demande acceptée est justifiée) | + « Administrateur RH » |
| Stock sous le seuil | les cinq articles les plus bas | Direction (le stock lui reste) |
| Vos tâches en retard | celles que la personne voit dans son planning | — |

Les chiffres viennent des services des écrans (Objectifs, Vue d'ensemble, Budgets, Créances, Pointage, Planning, Rentabilité) : un rapport dit la même chose que les écrans. Les commandes en retard, devis à relancer, réclamations ouvertes, créances, trésorerie et stock sont la situation au moment de l'envoi.

Le sujet ne contient aucun montant (« Rapport du jour · samedi 17 octobre 2026 · Succès Imprim ») : il s'affiche dans les notifications des téléphones. Le texte d'aperçu des messageries non plus (nombre de commandes et de points à surveiller).

## Destinataires

- Tant que la Direction n'a rien choisi : les comptes actifs de la **Direction** qui ont une adresse e-mail sur leur fiche employé.
- Accueil › **Rapports par e-mail** › Destinataires (Direction seulement) : cocher les comptes qui reçoivent les rapports. Seuls les comptes actifs qui ont le droit « Chiffre d'affaires global » se cochent ; les autres sont repliés sous la liste. Le serveur refuse un compte sans ce droit même si on force le formulaire. « Revenir à la direction » remet le choix par défaut. Le changement est gardé dans le journal des Réglages.
- Au moment de chaque envoi, le droit et l'adresse sont relus : un compte désactivé ou qui a perdu le droit ne reçoit plus rien, même s'il est coché.
- L'adresse est celle de la fiche employé. Un destinataire coché sans adresse apparaît « Sans adresse e-mail » dans l'historique.

## Écran Accueil › Rapports par e-mail

Ouvert avec le droit « Chiffre d'affaires global » (ERP local seulement) :

- Les trois rapports : quand ils partent, prochain envoi, dernier envoi et son résultat.
- **Aperçu** : exactement l'e-mail que la personne reçoit (même modèle, mêmes droits), pour aujourd'hui, la semaine passée ou le mois passé, et les périodes d'avant (deux ans). La partie texte de l'e-mail et le **PDF joint** sont à côté. La Direction peut voir la version d'un autre destinataire (« Version de… ») ; pour les autres, seulement la leur.
- **M'envoyer un test** : sa propre version, à sa propre adresse, avec le PDF si coché. L'essai est noté dans l'historique mais ne compte pas comme l'envoi de la période. Au plus 5 essais par heure et par personne.
- **Historique des envois** (Direction) : chaque envoi, sa période, qui l'a lancé (tâche planifiée, à la main, essai), et pour chaque destinataire son résultat (envoyé, échec avec la raison, sans adresse). « Renvoyer aux destinataires en échec » ne renvoie qu'à ceux-là. Hors Direction : seulement ses propres lignes.

Le contenu des e-mails n'est pas gardé : seulement qui l'a reçu, quand, et l'erreur s'il n'est pas parti. Une erreur ne montre jamais un identifiant ou un mot de passe de la messagerie.

## Envoi : tâche planifiée

Tâche `app:rapports-mail`, à lancer **chaque quart d'heure** sur l'ERP local (elle a les données ; sur le serveur en ligne elle refuse de tourner) :

- Chaque rapport part à son heure, **une seule fois** par période : la ligne de l'historique (clé unique « jour:2026-10-17 », « semaine:2026-W42 », « mois:2026-10 ») est posée avant le premier e-mail ; une seconde tâche lancée en même temps s'arrête.
- Ordinateur éteint à l'heure prévue : le rapport part au redémarrage s'il n'est pas trop vieux (rapport du jour jusqu'à 2 jours après, de la semaine 3 jours, du mois 7 jours). Jamais une pile de vieux rapports.
- Destinataires en échec (réseau coupé) : repris par la tâche 30 minutes après, trois fois au plus, sans renvoyer à ceux qui l'ont reçu.
- La tâche prend l'heure de Bamako, comme les pages, quel que soit le réglage de PHP.
- Jamais de SMS ni de WhatsApp.

```
php bin/console app:rapports-mail                                  # chaque rapport à son heure, une fois par période
php bin/console app:rapports-mail --dry-run                        # destinataires, version de chacun, ce qui partirait ; rien n'est envoyé
php bin/console app:rapports-mail jour --periode=2026-10-17        # une période terminée précise, tout de suite
php bin/console app:rapports-mail semaine --periode=2026-W42 --reprendre   # renvoie aux seuls destinataires en échec
```

- Windows (ERP local) : lancer une fois, en administrateur, `scripts\windows\installer_tache_rapports_mail.bat` (tâche « SuccessImprim_RapportsMail » toutes les 15 minutes, journal dans `var\log\rapports_mail.log`).
- Linux ou Mac : `*/15 * * * * cd /chemin/du/projet && php bin/console app:rapports-mail -q >> var/log/rapports-mail.log 2>&1`.

## Messagerie (MAILER_DSN)

Les e-mails partent par Symfony Mailer avec `MAILER_DSN` et `MAILER_FROM_ADDRESS`, dans le `.env.local` **de l'ERP local** (jamais dans le dépôt, un message ou une capture d'écran) :

```
MAILER_DSN=smtps://contact%40succesimprim.com:MOT_DE_PASSE_DE_LA_BOITE@mail.succesimprim.com:465
MAILER_FROM_ADDRESS=contact@succesimprim.com
```

Même boîte que celle du site en ligne ([docs/deploiement.md, « Boîte e-mail LWS »](deploiement.md#boîte-e-mail-lws-mailer_dsn)), même règle pour écrire `@` en `%40`. Le nom affiché « Succès Imprim » s'ajoute tout seul. L'ordinateur de l'atelier doit pouvoir joindre le serveur de la boîte (port 465).

- Sans l'une des deux variables, ou avec `null://` en production : la tâche ne fait rien (« Envoi d'e-mails pas réglé »), l'écran le dit en haut.
- Le `.env` du dépôt met `MAILER_DSN=null://null` : en développement et dans les tests, les e-mails « partent » sans jamais arriver nulle part. L'écran affiche « Transport de test ».

Après avoir réglé la boîte : `php bin/console cache:clear`, puis « M'envoyer un test » sur l'aperçu.

## Le lien vers l'ERP

Le bouton « Ouvrir dans l'ERP » mène à l'aperçu de la période, à l'adresse réglée dans la tuile (par exemple `http://192.168.1.10/successImprim/public`). Il ne fonctionne que sur le réseau de l'atelier : le pied de l'e-mail le dit. Sans adresse réglée, les envois automatiques n'ont pas de bouton (un essai prend l'adresse d'où il a été demandé).

## Rapport de la semaine (notification)

Le rapport de la semaine de [docs/statistiques.md](statistiques.md) (notification dans l'application le lundi à 8 h, avec la version des agents) reste tel quel. Sa case « Aussi par e-mail » (désactivée par défaut) enverrait un second e-mail le lundi à la Direction : la laisser décochée quand les rapports par e-mail sont actifs.

## Sur le serveur en ligne

L'écran, l'aperçu, le PDF et l'essai n'existent que sur l'ERP local : en ligne, ces adresses répondent 404 (`StatistiquesHorsLigneSubscriber`) et la tâche refuse de tourner. La table `envoi_rapport` et la tuile ne sont pas synchronisées.

## Vérification

- Tests : `tests/Unit/Service/Rapports` (bornes des périodes, heure d'envoi et rattrapage, une seule fois par période, parties selon les droits, modèles HTML et texte sans montant ni nom sans le droit, erreurs sans secret) et `tests/Unit/Controller/RapportsMailControllerTest.php`.
- Recette des accès (`app:recette:acces`, docs/recette-par-role.md) : seule la Direction choisit les destinataires et renvoie un rapport ; sans le droit, pas d'essai ; hors Direction, l'aperçu ne montre jamais la version d'un autre ; un compte qui n'a que le droit du rapport ne voit ni montant, ni nom de client, ni partie réservée.

## Limites connues

- Les objectifs de la période sont ceux du mois au prorata des jours (un samedi vaut 1/31 de l'objectif d'octobre).
- Un rapport du jour ne compare qu'au même jour de la semaine d'avant, pas à la moyenne des jours.
- Les présences comptent un jour travaillé selon le planning de chacun (tuile Pointage) ; une absence non saisie comme demande est « non justifiée ».
