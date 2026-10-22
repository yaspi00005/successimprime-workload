# Sécurité

La page de référence est docs/securite.md : elle dit ce que l'application fait, comment le revérifier et les règles pour le code nouveau. Ce chapitre range les mécanismes dans l'ordre d'une requête et renvoie aux listes du guide (`securite`), où chaque mécanisme a sa fiche et son point d'audit.

## Les mécanismes, dans l'ordre d'une requête

| Étape | Mécanisme | Où | Dans le guide |
|---|---|---|---|
| Arrivée | pare-feux `synchro`, `api`, `espace`, `main` | `config/packages/security.yaml` | `securite.pare_feux`, `routes[].acces.pare_feu` |
| Connexion du personnel | identifiant et mot de passe, code PIN, appareil autorisé et code de sécurité en ligne | `UserAuthenticator`, `UserChecker`, `AccesEnLigneSubscriber`, `CodeDeConnexion` | `securite.mecanismes` |
| Connexion d'un client | téléphone et code, ou mot de passe ; « rester connecté » (`SI_ESPACE_SOUVENIR`, 30 jours, chemin `/espace`) | `EspaceClientAuthenticator`, `EspaceClientChecker` | `securite.pare_feux` (nom `espace`) |
| Connexion d'un programme | jeton `Bearer`, ERP local seulement | `JetonApiAuthenticator`, `ApiHorsLigneSubscriber` | `securite.pare_feux` (nom `api`) |
| Synchronisation | signature HMAC-SHA256 avec `SYNC_CLE`, 5 minutes au plus | `src/Controller/Api` et `src/Service/Synchro` | `routes[].acces.note` des routes `/api/synchro/` |
| Essais limités | `login_throttling` des pare-feux, limiteurs de `rate_limiter.yaml` pour chaque code qu'un inconnu peut taper | `config/packages/rate_limiter.yaml` | `securite.limiteurs` (avec `utilise_par`) |
| Mots de passe | hachage automatique de Symfony | `security.yaml` (`password_hashers`) | `securite.mots_de_passe` |
| Règles d'accès | `access_control` (première règle qui correspond), `#[IsGranted]`, `denyAccessUnlessGranted()`, voteurs | `security.yaml`, contrôleurs, `src/Security/*Voter.php` | `routes[].acces`, `securite.access_control`, `droits.voteurs` |
| Restrictions par requête | lecture seule, livreur seul, mot de passe à changer, compte inactif, poste de machine, espace client en lecture | `src/EventSubscriber` | `securite.mecanismes` |
| Écrans absents en ligne | les `…HorsLigneSubscriber` répondent « introuvable » quand `APP_EN_LIGNE=1` | `src/EventSubscriber` | `securite.separation_en_ligne_local` |
| Jeton CSRF | `isCsrfTokenValid()`, formulaires Symfony, `JetonJson::lire()` pour le JSON (en-tête `X-CSRF-TOKEN`) | contrôleurs | `routes[].csrf`, `securite.csrf_par_controleur`, `securite.routes_modifiant_sans_csrf` |
| Adresses de retour | seulement une page du site | `RetourInterne::chemin()` | `securite.mecanismes` |
| Fichiers | privés sous `var/uploads`, servis après contrôle des droits, type lu dans le contenu | `FichiersPrives`, `FichierEnvoye`, `FichiersPrivesVoter` | chapitre « Fichiers » |
| Réponse | en-têtes de sécurité sur toutes les réponses (CSP, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS en https, `X-Powered-By` retiré) | `EntetesSecuriteSubscriber`, `EntetesSecurite` | `securite.en_tetes` |
| Cookies | session `Secure` en https, `HttpOnly`, `SameSite=Lax` ; cookie du dernier utilisateur signé | `framework.yaml`, `LastUserCookieService` | `securite.cookie_session` |
| Trace | journal d'activité de chaque enregistrement | `AuditSubscriber` | `securite.ecouteurs_doctrine` |
| Suppressions | confirmation dans la page, formulaire POST avec jeton, corbeille de 30 jours sans les secrets (`TypesCorbeille::SENSIBLES`) | docs/corbeille.md | `securite.mecanismes` |

## Comment le guide repère le jeton CSRF

Pour chaque route qui modifie des données (`modifie_donnees`), `csrf` dit si un jeton est vérifié et `csrf_detail` dit où :

- « formulaire Symfony » : l'action crée un formulaire (`createForm…`), qui porte son jeton ;
- « dans l'action » : `isCsrfTokenValid()` ou `JetonJson::lire()` dans le corps de la méthode ;
- « méthode verifierJeton() » (par exemple) : dans une méthode de la même classe que l'action appelle (jusqu'à trois niveaux) ;
- « contrôle de l'en-tête Origin » : formulaire public sans session qui compare l'origine à l'adresse du site ;
- « authentificateur UserAuthenticator » (ou `EspaceClientAuthenticator`) : la page de connexion, dont le jeton est vérifié par l'authentificateur (`CsrfTokenBadge`) ;
- « sans objet : pare-feu sans session » (`synchro`, `api`) : signature ou jeton d'API, le jeton CSRF n'y a pas de sens.

Une route est comptée comme modifiant des données si elle accepte `POST`, `PUT`, `PATCH` ou `DELETE`, ou si elle accepte toutes les méthodes et que son corps écrit (`flush`, `persist`, `remove`, `handleRequest`…). Une action qui ne fait que renvoyer vers une autre page ou répondre (« inerte ») n'est pas comptée. C'est une lecture du texte, pas une preuve : l'auditeur relit les cas signalés.

## Risques connus et acceptés

Ce sont des décisions écrites, pas des oublis :

- **`'unsafe-inline'`** reste permis dans la politique de contenu pour les scripts et les styles écrits dans les pages (beaucoup de pages en ont). `'unsafe-eval'` ne l'est pas.
- **Mesure d'audience Google** (gtag.js) sur les pages publiques du site en ligne qui portent la balise, et sur elles seules (`MesureAudience::PAGES`), décision de Yaya du 15/10/2026.
- **`public/uploads/site` et `public/uploads/tv`** restent publics : ces images sont faites pour être vues de tous.
- **Fonds de carte OpenStreetMap** en `img-src` (des images, aucun code).
- **Routes qui modifient sans jeton CSRF**, signalées par le guide (`securite.routes_modifiant_sans_csrf`) :
  - `app_assistant_public_historique`, `app_assistant_public_message`, `app_assistant_public_note` : l'assistant du site public, sans compte ni session ; la conversation se retrouve par le jeton gardé dans le navigateur, les appels sont limités par adresse et par conversation, et ses outils ne lisent que le catalogue et les informations publiques ;
  - `app_orange_money_notification` : appel d'Orange vers le site, de serveur à serveur ; il n'est cru que s'il porte le jeton reçu à l'ouverture du paiement, et le paiement créé reste « en attente » ;
  - `app_planning_echeance_apercu` : un aperçu des prochaines dates d'une échéance en cours de saisie, calculé en mémoire, sans rien enregistrer.
- **`CommandeVoter`** n'est appelé nulle part et teste un rôle `ROLE_COMMERCIAL` absent de `Droits` : du code mort à retirer ou à brancher, sans effet sur l'accès aujourd'hui.
- **Les limites par adresse** supposent que l'application voit la vraie adresse du visiteur : sur un serveur derrière un relais, `SYMFONY_TRUSTED_PROXIES` doit être réglé (docs/securite.md, « Sur le serveur »).

## Liste de contrôle de l'auditeur

1. Lancer les tests de sécurité : `php vendor/bin/phpunit tests/Unit/Security` (en-têtes, sans CDN, fichiers envoyés, voteurs, droits de l'espace, livreur seul, accès en ligne, règles d'accès…).
2. Lancer la recette des accès dans les conditions du site : `APP_EN_LIGNE=1 APP_DEBUG=0 php bin/console app:recette:acces`.
3. Vérifier les dépendances : `composer audit`.
4. Sur le site en ligne, rejouer les `curl` de docs/securite.md (« Revérifier ») : six en-têtes, pas de `X-Powered-By`, 403 ou 404 sur `/.env`, `/.git/HEAD`, `/_profiler`, `/api/v1`…, http renvoyé vers https.
5. Dans le guide, lire `securite.routes_modifiant_sans_csrf` : chaque route doit avoir sa raison ci-dessus. Une nouvelle route dans cette liste est un défaut jusqu'à preuve du contraire.
6. Filtrer `routes` sur `public: true` et `modifie_donnees: true` : chaque route doit avoir une limite (`securite.limiteurs[].utilise_par`) ou une autre protection.
7. Filtrer `routes` sur `sensibles` non vide : chaque route doit avoir un droit (`acces.access_control`, `acces.is_granted`, `acces.refus_dans_le_code`) adapté à ce qu'elle montre (montants, salaires, données personnelles, fichiers).
8. Vérifier que `.env.local` n'est pas dans le dépôt, qu'il est en droits 600 sur le serveur, que `APP_ENV=prod` et que `APP_SECRET` est propre à chaque machine. Les clés ne s'envoient jamais (docs/mise-en-production.md).
9. Vérifier qu'aucun fichier envoyé n'est sous `public/uploads` en dehors de `site` et `tv`, et que `app:fichiers:deplacer` est passé.
10. Reprendre les points `securite:…` de `audit` un par un (chapitre « Comment auditer avec ce guide »).
