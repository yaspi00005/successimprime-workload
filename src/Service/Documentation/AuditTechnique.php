<?php

namespace App\Service\Documentation;

/**
 * Points d'audit du guide technique : un par contrôleur, par entité, par
 * route qui modifie des données ou montre des données sensibles, par
 * mécanisme de sécurité et par commande planifiée.
 *
 * L'identifiant de chaque point est stable d'une génération à l'autre
 * (« route:app_factures_show », « entite:App\Entity\Factures ») : les
 * réponses de l'audit sont rangées sous ces identifiants.
 *
 * Statique et pur : il lit le guide déjà construit.
 */
final class AuditTechnique
{
    /** Phrase à vérifier pour les mécanismes connus (nom court de la classe). */
    private const MECANISMES = [
        'EntetesSecurite' => 'Les en-têtes (CSP sans \'unsafe-eval\', X-Frame-Options SAMEORIGIN, nosniff, Referrer-Policy, Permissions-Policy, HSTS en https) sont ceux de docs/securite.md ; seules les pages de MesureAudience ouvrent la politique à Google.',
        'EntetesSecuriteSubscriber' => 'Chaque réponse (page, JSON, fichier, erreur) reçoit les en-têtes, au local comme en ligne, sans écraser un en-tête déjà posé par la page ; vérifier avec curl -sI sur le site en ligne.',
        'FichierEnvoye' => 'Le nom et l\'extension d\'un fichier envoyé sont choisis par le serveur d\'après son contenu (liste fermée), et un fichier servi part avec son type lu dans le contenu, nosniff, la politique sandbox et Cache-Control: private.',
        'FichiersPrives' => 'Chaque fichier privé est rangé sous var/uploads, hors de public/, et lu par un chemin qui ne peut pas sortir de son dossier ; aucun nouveau dossier privé sous public/uploads.',
        'FichiersPrivesVoter' => 'Chaque sorte de fichier privé (chat, prépresse, étiquettes, justificatifs, photos du personnel) ne s\'ouvre qu\'avec les droits de la page qui le montre, jamais pour un compte client ni un visiteur.',
        'AccesEnLigne' => 'En ligne (APP_EN_LIGNE=1), le personnel n\'entre que depuis un appareil autorisé, avec mot de passe puis code reçu, et la session se ferme après 15 minutes sans activité ; rien de cela au local.',
        'AccesEnLigneSubscriber' => 'Depuis un appareil non autorisé, la réponse est la même que le mot de passe soit juste ou faux, et l\'appareil est contrôlé avant le mot de passe.',
        'AideHorsLigneSubscriber' => 'Sur le serveur en ligne, les adresses du guide utilisateur (/aide…) répondent « introuvable ».',
        'ApiHorsLigneSubscriber' => 'Sur le serveur en ligne, /api/v1 répond « introuvable » : un jeton d\'API ne contourne pas l\'appareil autorisé et le code.',
        'AtelierHorsLigneSubscriber' => 'Sur le serveur en ligne, les écrans de l\'atelier de production n\'existent pas (introuvable).',
        'ProductionMachineSubscriber' => 'Les routes app_production_* ne s\'ouvrent que depuis l\'adresse IP d\'un poste de machine enregistré (Machines) ; l\'essayer depuis un autre poste, et vérifier que SYMFONY_TRUSTED_PROXIES ne laisse pas un visiteur choisir son adresse.',
        'LectureSeuleSubscriber' => 'Un rôle en mode « Voir » ne peut envoyer aucun POST ni ouvrir de page d\'écriture dans le module concerné (ModulesRoles::moduleEnLecture).',
        'LivreurSeul' => 'Un compte dont le seul droit est Livraisons n\'ouvre que les écrans de LivreurSeul::ROUTES et ses écrans personnels.',
        'LivreurSeulSubscriber' => 'Toute autre page tapée à la main par le livreur seul répond « accès refusé ».',
        'EspaceClientSubscriber' => 'Une session de l\'espace client ouverte avant « Déconnecter les autres appareils » ou par une personne suspendue est fermée, et un compte « Lecture · suivi » ne peut rien envoyer.',
        'DroitsEspace' => 'Un compte « Lecture · suivi » ne commande pas, ne valide ni BAT ni devis, ne paie pas et ne modifie rien : chaque page d\'écriture de l\'espace client est dans sa liste de refus.',
        'UserChecker' => 'Un compte du personnel désactivé ou à l\'accès fermé ne peut plus se connecter, ni par mot de passe, ni par jeton d\'API.',
        'EspaceClientChecker' => 'Un compte client fermé ou suspendu ne se connecte plus, y compris par le cookie « rester connecté ».',
        'UserAuthenticator' => 'La connexion du personnel vérifie le jeton CSRF (CsrfTokenBadge) ; vérifier qu\'elle donne le même message d\'erreur que l\'identifiant existe ou non.',
        'EspaceClientAuthenticator' => 'La connexion de l\'espace client (téléphone et mot de passe, ou code) vérifie le jeton CSRF et ne dit pas si le numéro est client.',
        'JetonApiAuthenticator' => 'Un jeton d\'API agit avec les droits du moment de la personne qui le porte, et un jeton révoqué ou expiré est refusé.',
        'CodeDeConnexion' => 'Le code à 6 chiffres n\'est gardé que par son empreinte, vaut 5 minutes et 5 essais, et chaque envoi compte dans les limites par destinataire et par adresse IP.',
        'EssaisCodeFacture' => 'Le suivi de facture par QR (/f/{code}) bloque une connexion après 20 codes faux par heure ; les bons codes ne comptent pas.',
        'EssaisCodePromo' => 'Les codes promo refusés comptent par compte et par connexion ; au-delà de la limite, aucun code n\'est plus essayé.',
        'RetourInterne' => 'Toute adresse de retour envoyée par un formulaire passe par RetourInterne::chemin() et ne peut pas renvoyer vers un autre site (//, /\\, adresse complète).',
        'JetonJson' => 'Chaque enregistrement envoyé en JSON par l\'administration lit son jeton par JetonJson::lire (en-tête X-CSRF-TOKEN ou _token) et répond 403 sans jeton.',
        'AuditSubscriber' => 'Chaque création, modification et suppression des entités suivies laisse une ligne dans le journal d\'activité, sans mot de passe ni secret dans les valeurs gardées.',
        'ForcePasswordChangeSubscriber' => 'Une personne qui doit changer son mot de passe (User::mustChangePassword) n\'ouvre que le changement de mot de passe et la déconnexion.',
        'ActiveUserSubscriber' => 'Un compte désactivé pendant sa session (User::isActif) voit sa session fermée à la requête suivante.',
        'LastUserCookieService' => 'Le cookie du dernier utilisateur du poste est signé avec APP_SECRET, HttpOnly et SameSite=Lax, et ne contient aucun mot de passe.',
        'Droits' => 'Chaque droit du catalogue est testé quelque part (is_granted, #[IsGranted] ou voteur) et aucun droit testé dans le code n\'est absent du catalogue.',
        'ModulesRoles' => 'Les niveaux Modifier / Voir / — de Réglages › Rôles et menus donnent exactement les droits du module, et les montants restent des droits à part.',
        'CorbeilleVoter' => 'Une entrée de la corbeille ne se voit, ne se restaure et ne se supprime qu\'avec la corbeille et le droit de la fiche d\'origine, et jamais sur le site en ligne.',
        'DossiersClientsVoter' => 'Les dossiers clients suivent la matrice « Qui voit quoi » et sont refusés sur le site en ligne.',
        'MessagerieVoter' => 'Une conversation client n\'est lue que par la Direction, la personne assignée, les collègues ajoutés et l\'agent de la commande ou du devis.',
        'MessagerieInterneVoter' => 'Seuls les membres lisent un groupe ou un message privé de l\'équipe ; la Direction ne lit jamais les messages privés des autres.',
        'PrimesVoter' => 'Les scores et primes d\'un agent ne sont vus que par l\'admin et son responsable, jamais par l\'agent lui-même dans l\'écran d\'équipe.',
        'LivreurVoter' => 'LIVREUR n\'est vrai que pour une personne qui a le droit Livraisons, pas pour la Direction.',
        'PersonnelVoter' => 'PERSONNEL n\'est vrai que pour une personne qui a une fiche employé (Mon espace, pointage).',
        'CommandeVoter' => 'COMMANDE_VOIR, COMMANDE_MODIFIER et COMMANDE_SUPPRIMER ne sont testés nulle part dans src ni templates, et MODIFIER demande ROLE_COMMERCIAL, absent du catalogue Droits : brancher le voteur ou le retirer.',
    ];

    /**
     * @param array<string, mixed> $guide
     *
     * @return list<array{id: string, type: string, module: string, titre: string, quoi_verifier: string, fichier: string}>
     */
    public static function points(array $guide): array
    {
        $points = [];
        $fichiersControleurs = array_column($guide['controleurs'], 'fichier', 'classe');
        $routesParNom = [];
        foreach ($guide['routes'] as $r) {
            $routesParNom[$r['nom']] = $r;
        }

        foreach ($guide['controleurs'] as $c) {
            $points[] = self::point('controleur:'.$c['classe'], 'controleur', $c['module'], ModulesTechniques::nomCourt($c['classe']), self::phraseControleur($c, $routesParNom), $c['fichier']);
        }
        foreach ($guide['entites'] as $e) {
            $points[] = self::point('entite:'.$e['classe'], 'entite', $e['module'], ModulesTechniques::nomCourt($e['classe']).' (table '.$e['table'].')', self::phraseEntite($e), $e['fichier']);
        }
        foreach ($guide['routes'] as $r) {
            if (!$r['modifie_donnees'] && [] === $r['sensibles']) {
                continue;
            }
            $classe = null === $r['controleur'] ? null : explode('::', $r['controleur'])[0];
            $points[] = self::point('route:'.$r['nom'], 'route', $r['module'], self::methodes($r).' '.$r['chemin'], self::phraseRoute($r), $fichiersControleurs[$classe] ?? 'config/routes.yaml');
        }
        foreach ($guide['securite']['mecanismes'] as $m) {
            $points[] = self::point('securite:'.$m['cle'], 'securite', 'securite', $m['titre'], self::phraseMecanisme($m), $m['fichier']);
        }
        $sansCsrf = $guide['securite']['routes_modifiant_sans_csrf'];
        $points[] = self::point('securite:routes-sans-csrf', 'securite', 'securite', 'Routes qui modifient des données sans jeton CSRF détecté', [] === $sansCsrf
            ? 'Aucune route qui modifie des données n\'a été trouvée sans jeton CSRF ; refaire la vérification après chaque nouvelle route.'
            : \sprintf('%d route(s) modifient des données sans jeton CSRF trouvé par l\'analyse (%s) : pour chacune, ajouter isCsrfTokenValid ou écrire pourquoi elle n\'en a pas besoin.', \count($sansCsrf), implode(', ', \array_slice(array_column($sansCsrf, 'nom'), 0, 12)).(\count($sansCsrf) > 12 ? '…' : '')), 'src/Controller');
        foreach ($guide['commandes'] as $c) {
            if (!$c['planifiee']) {
                continue;
            }
            $points[] = self::point('commande:'.$c['nom'], 'commande', $c['module'], $c['nom'], self::phraseCommande($c), $c['fichier']);
        }

        return $points;
    }

    /**
     * @return array{id: string, type: string, module: string, titre: string, quoi_verifier: string, fichier: string}
     */
    private static function point(string $id, string $type, string $module, string $titre, string $quoi, string $fichier): array
    {
        return ['id' => $id, 'type' => $type, 'module' => $module, 'titre' => $titre, 'quoi_verifier' => $quoi, 'fichier' => $fichier];
    }

    /**
     * Droits exigés par une route, en clair.
     *
     * @param array<string, mixed> $r
     */
    public static function droitsRoute(array $r): string
    {
        $morceaux = [];
        $regle = $r['acces']['access_control']['roles'] ?? [];
        if (\in_array('PUBLIC_ACCESS', $regle, true)) {
            $morceaux[] = 'sans compte (PUBLIC_ACCESS)';
        } elseif ([] !== $regle) {
            $morceaux[] = implode(' ou ', $regle).' (access_control)';
        }
        if ([] !== $r['acces']['is_granted']) {
            $morceaux[] = implode(' et ', $r['acces']['is_granted']).' (#[IsGranted])';
        }
        $refus = array_values(array_diff($r['acces']['refus_dans_le_code'], $r['acces']['is_granted']));
        if ([] !== $refus) {
            $morceaux[] = implode(', ', \array_slice($refus, 0, 4)).' (denyAccessUnlessGranted)';
        }
        if ('synchro' === $r['acces']['pare_feu']) {
            $morceaux[] = 'une requête signée par l\'agent de l\'ERP local';
        }

        return [] === $morceaux ? 'aucune règle trouvée' : implode(', puis ', $morceaux);
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function phraseRoute(array $r): string
    {
        $action = null === $r['controleur'] ? 'sans contrôleur' : ModulesTechniques::nomCourt($r['controleur']).'::'.(explode('::', $r['controleur'])[1] ?? '?');
        $phrase = \sprintf('%s %s (%s) n\'est ouverte qu\'avec %s', self::methodes($r), $r['chemin'], $action, self::droitsRoute($r));
        if ($r['modifie_donnees']) {
            $phrase .= $r['csrf']
                ? ' et vérifie le jeton CSRF ('.$r['csrf_detail'].')'
                : (null !== $r['csrf_detail'] ? ' ; jeton CSRF '.$r['csrf_detail'] : ' ; AUCUN jeton CSRF détecté : vérifier qu\'un autre site ne peut pas déclencher cette action');
        }
        $sortes = $r['sensibles'];
        $suites = [];
        if (\in_array('montants', $sortes, true)) {
            $suites[] = 'les montants payés et les restes dus ne s\'affichent qu\'avec ROLE_PAIEMENT_VOIR, les coûts et marges qu\'avec ROLE_COUT_MARGE';
        }
        if (\in_array('salaires', $sortes, true)) {
            $suites[] = 'salaires, bulletins et primes ne se voient qu\'avec ROLE_RH, par le responsable, ou par la personne elle-même';
        }
        if (\in_array('donnees_personnelles', $sortes, true)) {
            $suites[] = 'les téléphones, e-mails et fiches d\'une autre personne ou d\'un autre client ne sont pas accessibles en changeant le numéro dans l\'adresse';
        }
        if (\in_array('fichiers', $sortes, true)) {
            $suites[] = 'un fichier est servi après vérification des droits (FichierEnvoye::reponse), jamais depuis public/';
        }
        if ($r['public']) {
            $suites[] = 'route publique : un visiteur sans compte n\'en tire rien de plus que prévu (limite d\'essais, code ou jeton dans l\'adresse)';
        }

        return $phrase.([] === $suites ? '' : ' ; '.implode(' ; ', $suites)).'.';
    }

    /**
     * @param array<string, mixed>                $c
     * @param array<string, array<string, mixed>> $routesParNom
     */
    private static function phraseControleur(array $c, array $routesParNom): string
    {
        $court = ModulesTechniques::nomCourt($c['classe']);
        if ([] === $c['routes']) {
            return $court.' n\'a aucune route : vérifier qu\'il sert encore (classe de base, ou à supprimer).';
        }
        $droits = [];
        foreach ($c['routes'] as $nom) {
            $r = $routesParNom[$nom] ?? null;
            if (null === $r) {
                continue;
            }
            foreach (array_merge($r['acces']['access_control']['roles'] ?? [], $r['acces']['is_granted'], $r['acces']['refus_dans_le_code']) as $d) {
                $droits[$d] = true;
            }
        }
        $droits = array_keys($droits);
        $phrase = \sprintf('Chaque action de %s (%d route%s) vérifie %s', $court, \count($c['routes']), \count($c['routes']) > 1 ? 's' : '', [] === $droits ? 'au moins la connexion' : 'ses droits ('.implode(', ', \array_slice($droits, 0, 6)).(\count($droits) > 6 ? '…' : '').')');
        $n = $c['csrf']['routes_modifiant'];
        if ($n > 0) {
            $phrase .= [] === $c['csrf']['sans_jeton']
                ? \sprintf(' et le jeton CSRF sur les %d route%s qui modifient des données', $n, $n > 1 ? 's' : '')
                : \sprintf(' ; le jeton CSRF manque sur %s (sur %d routes qui modifient)', implode(', ', $c['csrf']['sans_jeton']), $n);
        }
        $sensibles = 0;
        foreach ($c['routes'] as $nom) {
            if ([] !== ($routesParNom[$nom]['sensibles'] ?? [])) {
                ++$sensibles;
            }
        }
        if ($sensibles > 0) {
            $phrase .= ' ; les montants, données personnelles et fichiers de ses pages ne se montrent qu\'aux droits prévus';
        }

        return $phrase.'.';
    }

    /**
     * @param array<string, mixed> $e
     */
    private static function phraseEntite(array $e): string
    {
        $suppressions = [];
        foreach ($e['relations'] as $rel) {
            foreach ($rel['colonnes_jointure'] ?? [] as $j) {
                $suppressions[] = $j['colonne'].' '.($j['onDelete'] ?? 'sans onDelete');
            }
        }
        $phrase = \sprintf('La table %s (%d colonnes, %d liens) : colonnes obligatoires et longueurs conformes à la base', $e['table'], \count($e['champs']), \count($e['relations']));
        if ([] !== $suppressions) {
            $phrase .= ', effet des suppressions voulu ('.implode(', ', \array_slice($suppressions, 0, 5)).(\count($suppressions) > 5 ? '…' : '').')';
        }
        if ([] !== $e['contraintes_uniques']) {
            $phrase .= ', unicité tenue ('.implode(', ', array_column($e['contraintes_uniques'], 'nom')).')';
        }
        $phrase .= null !== $e['synchronisee']
            ? \sprintf(' ; seuls les %d champs listés dans CatalogueSynchro (type %s, sens %s) partent vers le site en ligne', $e['synchronisee']['champs'], $e['synchronisee']['type'], $e['synchronisee']['sens'])
            : ' ; elle ne quitte pas sa machine (absente de CatalogueSynchro)';
        if ('' === $e['role']) {
            $phrase .= ' ; la classe n\'a pas de docblock qui dise son rôle';
        }

        return $phrase.'.';
    }

    /**
     * @param array{cle: string, titre: string, fichier: string, resume: string} $m
     */
    private static function phraseMecanisme(array $m): string
    {
        $cle = $m['cle'];
        if (str_starts_with($cle, 'classe:')) {
            $court = ModulesTechniques::nomCourt(substr($cle, 7));

            return self::MECANISMES[$court] ?? ('' !== $m['resume']
                ? 'Le code fait ce que dit son docblock (« '.AnalyseCode::couper($m['resume'], 220).' ») et un test de tests/Unit le couvre.'
                : 'Ce que fait '.$court.' est écrit dans son docblock et couvert par un test de tests/Unit.');
        }

        return match (true) {
            'config:session' === $cle => 'Le cookie de session reste HttpOnly, SameSite=Lax et Secure en https (cookie_secure: auto) : le vérifier dans le navigateur sur le site en ligne.',
            'config:mots-de-passe' === $cle => 'Les mots de passe du personnel et des clients sont hachés (algorithme « auto »), jamais gardés ni journalisés en clair.',
            'config:csrf' === $cle => 'Les formulaires Symfony et la connexion portent un jeton CSRF, et chaque action POST écrite à la main appelle isCsrfTokenValid.',
            'config:access-control' === $cle => 'L\'ordre des règles access_control est le bon (la première qui correspond gagne) et une adresse nouvelle sans règle tombe sur ^/ (ROLE_USER), jamais en accès public.',
            'config:role-hierarchy' === $cle => 'ROLE_ADMIN hérite bien de tous les droits du catalogue Droits, et aucun autre rôle n\'hérite d\'un droit par security.yaml sans le vouloir.',
            str_starts_with($cle, 'config:limite-connexion:') => 'Après 5 essais faux en 15 minutes, la connexion est refusée pour ce nom et cette adresse ; l\'essayer, et vérifier que l\'application voit la vraie adresse IP (SYMFONY_TRUSTED_PROXIES).',
            str_starts_with($cle, 'config:rester-connecte:') => 'Le cookie « rester connecté » est HttpOnly, SameSite=Lax, limité à son chemin, et annulé par « Déconnecter les autres appareils » (signature_properties).',
            str_starts_with($cle, 'limiteur:') => 'Le limiteur est consommé avant chaque essai par le code qui l\'utilise ('.$m['resume'].') et son dépassement donne un refus clair sans rien envoyer.',
            'fichier:uploads-htaccess' === $cle => 'public/uploads/.htaccess refuse PHP, HTML, SVG et JavaScript même avec une double extension, et la règle Nginx équivalente est posée sur le serveur.',
            default => 'Vérifier : '.$m['resume'],
        };
    }

    /**
     * @param array<string, mixed> $c
     */
    private static function phraseCommande(array $c): string
    {
        $quand = implode(' ; ', array_map(static fn (array $p): string => $p['frequence'].' ('.$p['ou'].')', \array_slice($c['planification'], 0, 3)));

        return \sprintf('%s tourne %s : elle fait ce qu\'annonce sa description (« %s »), ne fait rien quand il n\'y a rien à faire, refuse de tourner du mauvais côté (local ou en ligne) si elle ne le concerne pas, et son journal ne contient ni montant ni numéro de téléphone.', $c['nom'], $quand, AnalyseCode::couper((string) $c['description'], 160));
    }

    /**
     * @param array<string, mixed> $r
     */
    private static function methodes(array $r): string
    {
        return [] === $r['methodes'] ? 'ANY' : implode('|', $r['methodes']);
    }
}
