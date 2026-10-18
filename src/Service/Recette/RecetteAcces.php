<?php

namespace App\Service\Recette;

use App\Controller\TourneeController;
use App\Entity\BonLivraison;
use App\Entity\BulletinPaie;
use App\Entity\Clients;
use App\Entity\DemandeEmploye;
use App\Entity\Pointage;
use App\Entity\Reclamation;
use App\Entity\User;
use App\EventSubscriber\LectureSeuleSubscriber;
use App\Security\Droits;
use App\Security\LivreurSeul;
use App\Security\ModulesRoles;
use App\Service\Guide\CatalogueGuide;
use App\Twig\NavigationExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Uid\AbstractUid;

/**
 * Recette des accès du personnel (jalon J7, docs/recette-par-role.md) :
 * chaque compte de démonstration demande chaque page de l'admin, et la
 * réponse est comparée à ce que disent les droits (security.yaml,
 * #[IsGranted], matrice « Rôles et menus »). Lancée par app:recette:acces
 * sur l'ERP local.
 */
final class RecetteAcces
{
    /** Comptes toujours essayés (docs/recette-par-role.md) ; les autres seulement s'ils ont des droits différents. */
    public const COMPTES_RECETTE = ['demo.kadiatou', 'demo.aminata', 'demo.fanta', 'demo.seydou', 'demo.oumar', 'demo.bakary'];

    /** Pages jamais demandées : elles ferment la session ou changent de personne. */
    public const ROUTES_EXCLUES = ['app_logout', 'app_lockscreen', 'app_switch_user_login', 'app_login'];

    /**
     * Écarts avec la matrice des menus relus et voulus : une page ouverte
     * à un rôle qui n'a pas l'onglet de son module. Route (ou début de nom)
     * => raison. Ils restent dans le bilan comme remarques.
     */
    public const ECARTS_VOULUS = [
        'app_mon_espace_' => 'Mon espace est ouvert à tout le personnel (ses propres données).',
        'app_pointer_' => 'Le pointage QR et photo est ouvert à tout le personnel (ses propres pointages).',
        'app_planning_' => 'Le planning est ouvert à tout le personnel (onglet sans droit).',
        'app_user_change_password' => 'Chacun change son propre mot de passe.',
        'app_brouillon_' => 'Brouillon de saisie gardé dans sa propre session (BrouillonSaisieController).',
        'app_consommables_' => 'Consommables retirés du stock par la production (security.yaml : ROLE_PRODUCTION_GERER).',
        'app_reclamation_index' => 'Demandes de remboursement : chacun voit et fait les siennes (ReclamationController).',
        'app_reclamation_new' => 'Demandes de remboursement : chacun voit et fait les siennes (ReclamationController).',
        'app_immobilisations_registre' => 'Registre des biens confiés : chacun voit les siens, sans montant (DroitsPatrimoinePrimesTest).',
        'app_immobilisations_releve' => 'Relevés de compteurs des machines saisis par la production (autoriserReleve : ROLE_PRODUCTION_GERER).',
        'app_immobilisations_releves' => 'Relevés de compteurs des machines saisis par la production (autoriserReleve : ROLE_PRODUCTION_GERER).',
        'app_fichier_dossier_partage' => 'Dossier partagé des fichiers : à la prépresse (denyAccessUnlessGranted ROLE_PREPRESSE), rangé sous Grille tarifaire.',
        'app_mouvement_tresorerie_new' => 'Entrée, sortie ou virement saisi par la caisse sans « Trésorerie et soldes » (ROLE_TRESORERIE_SAISIR ou TRANSFERER) : aucun solde affiché, retour à la caisse (MouvementTresorerieController::new).',
    ];

    /**
     * Pages où un montant est voulu pour qui les ouvre avec l'un de ces
     * droits (aucun : pour tous) : route (ou début) => [droits, raison].
     */
    public const MONTANTS_VOULUS = [
        'app_mon_espace_' => [[], 'ses propres bulletins et primes'],
        'app_mes_statistiques' => [['ROLE_STATS_PERSO'], 'ses propres ventes et encaissements'],
        'app_mes_statistiques_export' => [['ROLE_STATS_PERSO'], 'ses propres ventes et encaissements'],
        'app_rapport_semaine' => [['ROLE_STATS_PERSO', 'ROLE_STATS_GLOBAL'], 'ses propres chiffres de la semaine'],
        'app_rapport_semaine_pdf' => [['ROLE_STATS_PERSO', 'ROLE_STATS_GLOBAL'], 'ses propres chiffres de la semaine'],
        'app_planning_annee' => [['ROLE_TRESORERIE_VOIR'], 'budget des échéances, montré avec le droit trésorerie'],
        'app_immobilisations_' => [['ROLE_COMPTABILITE'], 'valeurs des immobilisations, pour la comptabilité'],
        'app_reclamation_' => [[], 'ses propres demandes de remboursement (celles des autres : trésorerie ou caisse)'],
        'app_livraisons_tournee' => [['ROLE_LIVRAISON'], 'reste à encaisser à la livraison, sur ses propres livraisons (le bureau : avec le droit Paiements)'],
        'app_livraisons_remise' => [['ROLE_LIVRAISON'], 'reste à encaisser à la remise de sa propre livraison'],
    ];

    /**
     * Montants qui ressemblent à un paiement mais n'en sont pas, d'après ce
     * qui les précède : motif => raison.
     */
    public const CONTEXTES_VOULUS = [
        '/\bpoints = [\d \x{00A0}\x{202F}.]+ F$/u' => 'valeur des points fidélité du client',
        '/Acompte demandé ?\d+ % · [\d \x{00A0}\x{202F}.]+ F$/u' => 'acompte demandé sur le devis (un prix, pas un paiement reçu)',
        '/forfait de conception Acompte [\d \x{00A0}\x{202F}.]+ F$/u' => 'acompte demandé pour la conception (un prix)',
        '/(?:Payée|Impayée|Paiement partiel) [\d \x{00A0}\x{202F}.]+ F(?:CFA| CFA)?$/u' => 'total de la commande à côté de son état de paiement (le payé et le reste demandent le droit Paiements)',
        '/\bCMD-[A-Z0-9-]+ \d{2}\/\d{2}\/\d{4}(?: \d{2}:\d{2})? [\d \x{00A0}\x{202F}.]+ F(?:CFA| CFA)?$/u' => 'total d\'une commande dans une liste (numéro, date, montant TTC)',
    ];

    /**
     * Pages que le livreur seul ne doit jamais ouvrir (décision de Yaya du
     * 15/10/2026 : il ne voit que ses livraisons et sa tournée). L'accueil
     * le renvoie sur sa tournée.
     */
    public const REFUSEES_AU_LIVREUR = [
        'app_clients_index', 'app_commandes_index', 'app_ventes_index', 'app_statistiques_index', 'app_mes_statistiques',
        'app_recherche', 'app_chat_index', 'app_planning_index', 'app_livraisons_index', 'app_bons_livraison_index',
        'app_livraisons_a_retirer', 'app_livraisons_planning', 'app_livraisons_suivi', 'app_reclamation_index',
    ];

    private ?UsernamePasswordToken $jeton = null;

    /** Adresse d'un poste de machine de la démonstration : la production ne s'ouvre que depuis l'une d'elles. */
    private string|false|null $posteMachine = null;

    public function __construct(
        private readonly NavigateurInterne $navigateur,
        private readonly ParametresRoute $parametres,
        private readonly DroitsRoute $droits,
        private readonly RouterInterface $routeur,
        private readonly EntityManagerInterface $em,
        private readonly AccessDecisionManagerInterface $decisions,
        private readonly TokenStorageInterface $jetons,
    ) {
    }

    /**
     * La démonstration est-elle chargée ? Au local : comptes demo.* et
     * clients DEMO- ; en ligne (les comptes du personnel n'y passent pas) :
     * clients DEMO- avec leurs comptes de l'espace client.
     */
    public function demoPresente(bool $enLigne = false): bool
    {
        $connexion = $this->em->getConnection();
        $clients = (int) $connexion->fetchOne("SELECT COUNT(*) FROM clients WHERE code LIKE 'DEMO-%'");
        $comptes = $enLigne
            ? (int) $connexion->fetchOne("SELECT COUNT(*) FROM compte_client k JOIN clients c ON c.id = k.client_id WHERE c.code LIKE 'DEMO-%'")
            : (int) $connexion->fetchOne("SELECT COUNT(*) FROM `user` WHERE username LIKE 'demo.%'");

        return $comptes > 0 && $clients > 0;
    }

    /**
     * Comptes essayés : ceux de la recette, plus un compte par jeu de droits
     * différent (rôle créé dans Réglages, droits ajoutés ou retirés).
     *
     * @param list<string> $seulement identifiants demandés (vide : tous)
     *
     * @return list<User>
     */
    public function comptes(array $seulement = []): array
    {
        /** @var list<User> $tous */
        $tous = $this->em->getRepository(User::class)->createQueryBuilder('u')
            ->where("u.username LIKE 'demo.%'")->andWhere('u.actif = true')->orderBy('u.id')->getQuery()->getResult();
        $retenus = [];
        $signatures = [];
        foreach ($tous as $u) {
            if (!$u->isEnabled() || [] === array_diff($u->getRoles(), ['ROLE_USER'])) {
                continue; // sans accès à l'admin (tablette, invitation, parti)
            }
            if ([] !== $seulement) {
                if (\in_array($u->getUserIdentifier(), $seulement, true)) {
                    $retenus[] = $u;
                }
                continue;
            }
            $roles = $u->getRoles();
            sort($roles);
            $signature = implode(',', $roles).'|'.($u->getProfil()?->getMenu() ?? '');
            if (\in_array($u->getUserIdentifier(), self::COMPTES_RECETTE, true) || !isset($signatures[$signature])) {
                $retenus[] = $u;
                $signatures[$signature] = true;
            }
        }

        return $retenus;
    }

    /**
     * Pages de l'admin à demander : routes GET, hors site public, espace
     * client, API et outils de développement, avec leurs paramètres.
     *
     * @return list<array{route: string, chemin: string, definition: Route}>
     */
    public function pages(BilanRecette $bilan): array
    {
        $pages = [];
        foreach ($this->routeur->getRouteCollection() as $nom => $route) {
            if (!self::estPageAdmin($nom, $route) || \in_array($nom, self::ROUTES_EXCLUES, true)) {
                continue;
            }
            $jeux = $this->parametres->remplir($nom, $route);
            if (\is_string($jeux)) {
                $bilan->ignorees[$nom] = $jeux;
                continue;
            }
            foreach ($jeux as $parametres) {
                try {
                    $chemin = $this->routeur->generate($nom, $parametres);
                } catch (\Throwable $e) {
                    $bilan->ignorees[$nom] = 'adresse impossible à construire : '.$e->getMessage();
                    continue;
                }
                $pages[] = ['route' => $nom, 'chemin' => $chemin, 'definition' => $route];
            }
        }

        return $pages;
    }

    public static function estPageAdmin(string $nom, Route $route): bool
    {
        $methodes = $route->getMethods();
        if ([] !== $methodes && !\in_array('GET', $methodes, true)) {
            return false;
        }
        if (str_starts_with($nom, '_') || str_starts_with($nom, 'api_')) {
            return false;
        }
        $chemin = $route->getPath();

        // Site public, espace client, liens publics signés : vérifiés à part (visiteur, client).
        return !preg_match('#^/(espace|api/|site|nos-produits|calculateur|atelier-3d|realisations|equipements|guide-fichiers|a-propos|nos-engagements|faq|suivre-ma-commande|contact|conseils|demander-un-devis|sitemap\.xml|robots\.txt|assistant/|avis/|acces/|tablette|f/|ecran-accueil|om/|appareil/autoriser)#', $chemin)
            && !preg_match('#/pdf-public/|/verifier/\{token\}|/suivi/\{token\}#', $chemin)
            && '/' !== $chemin;
    }

    /**
     * @param list<User>                                 $comptes
     * @param callable(string): void|null                $suivi   appelé à chaque compte
     */
    public function verifier(array $comptes, ?callable $suivi = null): BilanRecette
    {
        $bilan = new BilanRecette();
        $this->navigateur->commencer();
        try {
            $pages = $this->pages($bilan);
            foreach ($pages as $p) {
                $bilan->pages[$p['chemin']] = ['route' => $p['route'], 'onglet' => DroitsRoute::ongletDe($p['route'])['libelle'] ?? null];
            }
            foreach ($comptes as $compte) {
                $suivi && $suivi($compte->getUserIdentifier());
                $this->verifierCompte($compte, $pages, $bilan);
                $this->verifierObjetsDesAutres($compte, $bilan);
                $this->verifierCompteClient($compte, $bilan);
            }
            $suivi && $suivi('lecture seule');
            try {
                $this->verifierLectureSeule($comptes, $bilan);
            } catch (\Throwable $e) {
                // Le bilan des comptes reste écrit ; le contrôle est noté en échec.
                $bilan->controler('Mode « Voir » : contrôle interrompu', false, $e::class.' : '.$e->getMessage());
            }
        } finally {
            $this->navigateur->terminer();
        }

        return $bilan;
    }

    /**
     * @param list<array{route: string, chemin: string, definition: Route}> $pages
     */
    private function verifierCompte(User $compte, array $pages, BilanRecette $bilan): void
    {
        $identifiant = $compte->getUserIdentifier();
        $roles = $compte->getRoles();
        $this->jeton = new UsernamePasswordToken($compte, 'main', $roles);
        $bilan->comptes[$identifiant] = ['libelle' => $compte->getProfil()?->getNom() ?? implode(', ', $compte->getRoles()), 'droits' => self::droitsLisibles($roles)];
        $session = $this->navigateur->session($compte);
        $rh = $this->accorde('ROLE_RH');
        $etats = [];
        $redirections = [];
        $chemins = [];

        foreach ($pages as $p) {
            $reponse = $this->navigateur->get($p['chemin'], $session, [], false, self::depuisUnPoste($p['route']) ? $this->posteMachine() : null);
            $etat = $reponse->etat();
            $bilan->matrice[$p['chemin']][$identifiant] = $etat;
            $etats[$p['route']] ??= $etat;
            $redirections[$p['route']] ??= $reponse->redirection;
            $chemins[$p['route']] ??= $p['chemin'];
            $exigences = $this->droits->exigences($p['definition'], $p['route'], $p['chemin']);

            // Guide : une fiche ou une capture suit les droits de l'écran décrit (CatalogueGuide).
            $guide = null === $exigences['guide'] ? null : $this->guidePermis($exigences['guide'], $roles);

            if (Reponse::ERREUR === $etat) {
                $bilan->constater(BilanRecette::ERREUR, 'erreur', $p['route'], $p['chemin'], $identifiant, (string) $reponse->erreur);
                continue;
            }
            if (Reponse::OUVERT !== $etat) {
                if (true === $guide && \in_array($etat, [Reponse::REFUSE, Reponse::CONNEXION], true)) {
                    $bilan->constater(BilanRecette::A_EXAMINER, 'guide_refuse', $p['route'], $p['chemin'], $identifiant, \sprintf('le guide répond « %s » alors que la personne a les droits de l\'écran décrit', $etat));
                }
                continue;
            }
            if ($reponse->ecritures > 0) {
                $bilan->constater(BilanRecette::INFO, 'get_ecrit', $p['route'], $p['chemin'], $identifiant, \sprintf('la page enregistre %d changement(s) à l\'ouverture (annulés par la recette)', $reponse->ecritures));
            }
            $declare = $this->auMoinsUn($exigences['regle']) && $this->tous($exigences['attributs']) && false !== $guide;
            if (!$declare) {
                $bilan->constater(BilanRecette::FUITE, 'sans_droit', $p['route'], $p['chemin'], $identifiant, 'page ouverte alors que les droits déclarés la refusent ('.implode(', ', [...$exigences['regle'], ...$exigences['attributs'], ...self::droitsDuGuide($exigences['guide'] ?? [])]).')');
            } elseif (null !== $exigences['menu'] && [] !== $exigences['menu'] && !$this->auMoinsUn($exigences['menu'])) {
                $voulu = self::raison(self::ECARTS_VOULUS, $p['route']);
                $bilan->constater(null === $voulu ? BilanRecette::A_EXAMINER : BilanRecette::INFO, 'hors_menu', $p['route'], $p['chemin'], $identifiant, null === $voulu
                    ? \sprintf('page ouverte sans le droit de son onglet « %s » (%s) ; protégée seulement par %s', $exigences['onglet'], implode(' ou ', $exigences['menu']), implode(', ', [...$exigences['regle'], ...$exigences['attributs']]) ?: 'la connexion')
                    : $voulu);
            }

            if ($reponse->estHtml()) {
                $this->verifierContenu($compte, $p, $reponse, $rh, $bilan);
            }
        }

        $livreurSeul = LivreurSeul::est($roles);
        if ($livreurSeul) {
            $this->verifierLivreurSeul($identifiant, $session, $etats, $chemins, $bilan);
        }

        // Entrées du menu et onglets que la personne voit : ils doivent s'ouvrir.
        foreach (NavigationExtension::ONGLETS as $onglets) {
            foreach ($onglets as $onglet) {
                if (!$this->auMoinsUn((array) $onglet['role'], true) || !isset($etats[$onglet['route']])) {
                    continue;
                }
                // Le menu du livreur seul n'a que sa tournée et ses livraisons (NavigationExtension).
                if ($livreurSeul && !\in_array($onglet['route'], LivreurSeul::MENU, true)) {
                    continue;
                }
                $etat = $etats[$onglet['route']];
                // Une entrée qui mène ailleurs (première fiche, écran du responsable) : la page d'arrivée doit s'ouvrir.
                if (Reponse::REDIRIGE === $etat && '' !== $redirections[$onglet['route']]) {
                    $arrivee = (string) parse_url($redirections[$onglet['route']], \PHP_URL_PATH);
                    $suite = $this->navigateur->get($arrivee.(($q = parse_url($redirections[$onglet['route']], \PHP_URL_QUERY)) ? '?'.$q : ''), $session);
                    $etat = Reponse::OUVERT === $suite->etat() ? Reponse::OUVERT : $suite->etat().' (après '.$arrivee.')';
                }
                if (Reponse::OUVERT !== $etat) {
                    $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::A_EXAMINER, 'menu_refuse', $onglet['route'], $this->routeur->generate($onglet['route'], $onglet['parametres'] ?? []), $identifiant, \sprintf('l\'onglet « %s » est dans son menu mais la page répond « %s »', $onglet['libelle'], $etat));
                }
            }
        }
    }

    /**
     * @param array{route: string, chemin: string, definition: Route} $p
     */
    private function verifierContenu(User $compte, array $p, Reponse $reponse, bool $rh, BilanRecette $bilan): void
    {
        $identifiant = $compte->getUserIdentifier();
        $voulu = self::raison(self::MONTANTS_VOULUS, $p['route']);
        if (null === $voulu || !$this->auMoinsUn($voulu[0])) {
            $interdits = array_values(array_filter(ContenuSensible::montantsInterdits($reponse->texte(), $compte->getRoles()), static fn (array $m): bool => null === self::contexteVoulu($m['contexte'])));
            if ([] !== $interdits) {
                $exemples = array_map(static fn (array $m): string => '« …'.$m['contexte'].' » ('.$m['categorie'].')', \array_slice($interdits, 0, 3));
                $bilan->constater(BilanRecette::A_EXAMINER, 'montant', $p['route'], $p['chemin'], $identifiant, \sprintf('%d montant(s) sans le droit qui va avec : %s', \count($interdits), implode(' ; ', $exemples)));
            }
        }
        if (!$rh) {
            $photos = ContenuSensible::photosPointage($reponse->contenu);
            if ([] !== $photos) {
                $autres = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pointage WHERE id IN ('.implode(',', $photos).') AND utilisateur_id <> ?', [$compte->getId()]);
                if ($autres > 0) {
                    $bilan->constater(BilanRecette::FUITE, 'photo_pointage', $p['route'], $p['chemin'], $identifiant, \sprintf('%d photo(s) de pointage d\'autres personnes sans le droit RH', $autres));
                }
            }
        }
    }

    /**
     * Livreur seul (LivreurSeul) : aucune page ouverte hors de sa tournée,
     * de ses livraisons et de ce que tout le personnel a pour lui-même ;
     * l'accueil le renvoie sur sa tournée.
     *
     * @param array<string, string> $etats
     * @param array<string, string> $chemins
     */
    private function verifierLivreurSeul(string $identifiant, MockArraySessionStorage $session, array $etats, array $chemins, BilanRecette $bilan): void
    {
        $hors = [];
        foreach ($etats as $route => $etat) {
            if (Reponse::OUVERT === $etat && !LivreurSeul::routePermise($route)) {
                $hors[] = $route;
                $bilan->constater(BilanRecette::FUITE, 'livreur_seul', $route, $chemins[$route], $identifiant, 'page ouverte au livreur, hors de sa tournée et de ses livraisons');
            }
        }
        $bilan->controler(\sprintf('%s : livreur seul, aucune page hors de sa tournée, de ses livraisons et de son espace', $identifiant), [] === $hors, [] === $hors ? \sprintf('%d pages demandées', \count($etats)) : implode(', ', $hors));

        $ouvertes = [];
        foreach (self::REFUSEES_AU_LIVREUR as $route) {
            if (isset($etats[$route]) && !\in_array($etats[$route], [Reponse::REFUSE, Reponse::INTROUVABLE, Reponse::CONNEXION], true)) {
                $ouvertes[] = $route.' → '.$etats[$route];
            }
        }
        $essayees = \count(array_intersect(self::REFUSEES_AU_LIVREUR, array_keys($etats)));
        $bilan->controler(\sprintf('%s : tableau de bord, clients, commandes, statistiques, recherche, messagerie, listes des livraisons et suivi refusés', $identifiant), [] === $ouvertes, [] === $ouvertes ? \sprintf('%d pages refusées', $essayees) : implode(', ', $ouvertes));

        // L'accueil (« / », hors de la liste des pages de l'admin) le renvoie sur sa tournée.
        $reponse = $this->navigateur->get($this->routeur->generate('app_home'), $session);
        $arrivee = (string) parse_url($reponse->redirection, \PHP_URL_PATH);
        $bilan->controler(\sprintf('%s : l\'accueil mène à sa tournée', $identifiant), Reponse::REDIRIGE === $reponse->etat() && $arrivee === $this->routeur->generate('app_livraisons_tournee'), '/ → '.$reponse->etat().' '.$arrivee);
    }

    /**
     * Fiches d'une autre personne demandées par leur numéro : bulletin,
     * justificatif, photo de pointage, livraison d'un autre livreur.
     */
    private function verifierObjetsDesAutres(User $compte, BilanRecette $bilan): void
    {
        $identifiant = $compte->getUserIdentifier();
        $session = $this->navigateur->session($compte);
        $essais = [];
        $bulletin = $this->autre(BulletinPaie::class, 'utilisateur', $compte, "e.etat <> 'brouillon'");
        if (null !== $bulletin) {
            $essais[] = ['app_mon_espace_bulletin', ['id' => $bulletin], 'bulletin de paie d\'une autre personne'];
        }
        if (!$this->accorde('ROLE_RH')) {
            $demande = $this->autre(DemandeEmploye::class, 'utilisateur', $compte);
            if (null !== $demande) {
                $essais[] = ['app_mon_espace_justificatif', ['id' => $demande], 'justificatif d\'une autre personne'];
            }
            $pointage = $this->autre(Pointage::class, 'utilisateur', $compte);
            if (null !== $pointage) {
                $essais[] = ['app_pointer_voir_photo', ['id' => $pointage, 'sens' => 'arrivee'], 'photo de pointage d\'une autre personne'];
            }
        }
        if (!$this->accorde('ROLE_ADMIN') && !$this->accorde('ROLE_TRESORERIE_SAISIR') && !$this->accorde('ROLE_CAISSE_COMMANDE')) {
            $reclamation = $this->autre(Reclamation::class, 'agent', $compte);
            if (null !== $reclamation) {
                $essais[] = ['app_reclamation_show', ['id' => $reclamation], 'demande de remboursement d\'une autre personne'];
            }
        }
        if ($this->accorde('ROLE_LIVRAISON') && !$this->accorde('ROLE_ADMIN') && !$this->accorde('ROLE_COMMANDE')) {
            $bon = $this->autre(BonLivraison::class, 'livreur', $compte, 'e.livreur IS NOT NULL');
            if (null !== $bon) {
                $essais[] = ['app_livraisons_remise', ['id' => $bon], 'remise d\'une livraison confiée à un autre livreur'];
                $essais[] = ['app_livraisons_bl_preuve', ['id' => $bon], 'photo ou signature d\'une livraison d\'un autre livreur'];
            }
            $this->verifierLivraisonsDesAutres($compte, $bilan);
        }
        foreach ($essais as [$route, $parametres, $libelle]) {
            if (null === $this->routeur->getRouteCollection()->get($route)) {
                continue;
            }
            $chemin = $this->routeur->generate($route, $parametres);
            $etat = $this->navigateur->get($chemin, $session)->etat();
            $ok = \in_array($etat, [Reponse::REFUSE, Reponse::INTROUVABLE, Reponse::CONNEXION], true);
            $bilan->controler(\sprintf('%s : %s', $identifiant, $libelle), $ok, $chemin.' → '.$etat);
            if (!$ok) {
                $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::FUITE, 'objet_autrui', $route, $chemin, $identifiant, $libelle.' : la page répond « '.$etat.' »');
            }
        }
    }

    /**
     * Compte du client à la boutique (docs/finances.md, « Compte du
     * client ») : l'onglet « Compte » de la fiche (solde, relevé) seulement
     * avec Paiements ou Encaissements ; un dépôt avec Encaissements ;
     * l'argent rendu seulement avec « Rendre l'argent du compte d'un
     * client » en plus. Les envois sont arrêtés avant le contrôleur : rien
     * n'est fait, seul le passage des droits est regardé.
     */
    private function verifierCompteClient(User $compte, BilanRecette $bilan): void
    {
        $client = $this->em->createQueryBuilder()->select('c.id', 'c.publicId')->from(Clients::class, 'c')
            ->where('c.soldeCredit > 0')->andWhere('c.id >= :demo')->setParameter('demo', ParametresRoute::PREMIER_ID_DEMO)
            ->orderBy('c.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (null === $client || null === $this->routeur->getRouteCollection()->get('app_caisse_solde_rembourser')) {
            return;
        }
        $identifiant = $compte->getUserIdentifier();
        $session = $this->navigateur->session($compte);
        $argent = $this->accorde('ROLE_PAIEMENT_VOIR') || $this->accorde('ROLE_PAIEMENT_ENCAISSER');

        if ($this->accorde('ROLE_CLIENT')) {
            $publicId = $client['publicId'] instanceof AbstractUid ? $client['publicId']->toRfc4122() : (string) $client['publicId'];
            $chemin = $this->routeur->generate('app_clients_show', ['publicId' => $publicId]);
            $reponse = $this->navigateur->get($chemin, $session);
            if (Reponse::OUVERT === $reponse->etat()) {
                $onglet = str_contains($reponse->contenu, 'id="panneau-compte"');
                $bilan->controler(\sprintf('%s : onglet « Compte » de la fiche client %s', $identifiant, $argent ? 'visible (Paiements ou Encaissements)' : 'absent (ni Paiements ni Encaissements)'), $onglet === $argent, $chemin.' → '.($onglet ? 'onglet présent' : 'onglet absent'));
                if ($onglet && !$argent) {
                    $bilan->constater(BilanRecette::FUITE, 'compte_client', 'app_clients_show', $chemin, $identifiant, 'solde et relevé du compte du client sans le droit Paiements ni Encaissements');
                }
            }
        }

        $envois = [
            ['app_caisse_solde_depot', ['ROLE_PAIEMENT_ENCAISSER'], 'dépôt sur le compte d\'un client'],
            ['app_caisse_solde_rembourser', ['ROLE_PAIEMENT_ENCAISSER', 'ROLE_SOLDE_CLIENT_REMBOURSER'], 'argent rendu depuis le compte d\'un client'],
        ];
        foreach ($envois as [$route, $droits, $libelle]) {
            $chemin = $this->routeur->generate($route, ['id' => (int) $client['id']]);
            $permis = $this->tous($droits);
            $reponse = $this->navigateur->post($chemin, [], $session, [], true);
            $atteint = NavigateurInterne::CONTROLEUR_ATTEINT === $reponse->statut;
            $bilan->controler(\sprintf('%s : %s %s', $identifiant, $libelle, $permis ? 'permis' : 'refusé'), $atteint === $permis, $chemin.' → '.($atteint ? 'arrivé au contrôleur' : $reponse->etat()));
            if ($atteint && !$permis) {
                $bilan->constater(BilanRecette::FUITE, 'compte_client', $route, $chemin, $identifiant, $libelle.' accepté sans le droit ('.implode(', ', $droits).')');
            }
        }
    }

    /**
     * Le livreur ne note pas l'échec d'une livraison confiée à un autre, et
     * « Mes livraisons » ne montre que les siennes. L'envoi porte un jeton
     * valide (posé dans sa session) : un refus vient bien du livreur du bon.
     * Dans la transaction de la recette : rien n'est gardé.
     */
    private function verifierLivraisonsDesAutres(User $compte, BilanRecette $bilan): void
    {
        $identifiant = $compte->getUserIdentifier();
        $jeton = 'recette-'.bin2hex(random_bytes(8));
        $session = $this->navigateur->session($compte, 'main', ['_csrf/'.TourneeController::CSRF => $jeton]);

        $sien = $this->em->createQueryBuilder()->select('e.id')->from(BonLivraison::class, 'e')
            ->where('e.livreur = :moi')->andWhere('e.statut = :valide')->setParameter('moi', $compte)->setParameter('valide', BonLivraison::STATUT_VALIDE)
            ->orderBy('e.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (null !== $sien) {
            // Sans raison : le contrôleur répond « choisissez la raison » sans rien écrire.
            $chemin = $this->routeur->generate('app_livraisons_tournee_echec', ['id' => $sien['id']]);
            $etat = $this->navigateur->post($chemin, ['_token' => $jeton], $session)->etat();
            $bilan->controler(\sprintf('%s : le jeton de la recette est accepté sur sa propre livraison', $identifiant), Reponse::REDIRIGE === $etat, $chemin.' → '.$etat);
        }

        $autre = $this->autre(BonLivraison::class, 'livreur', $compte, "e.livreur IS NOT NULL AND e.statut = 'valide'");
        if (null !== $autre) {
            $chemin = $this->routeur->generate('app_livraisons_tournee_echec', ['id' => $autre]);
            $etat = $this->navigateur->post($chemin, ['_token' => $jeton, 'motif' => BonLivraison::MOTIFS_ECHEC[0]], $session)->etat();
            $ok = \in_array($etat, [Reponse::REFUSE, Reponse::INTROUVABLE, Reponse::CONNEXION], true);
            $bilan->controler(\sprintf('%s : échec noté sur la livraison d\'un autre livreur', $identifiant), $ok, $chemin.' → '.$etat);
            if (!$ok) {
                $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::FUITE, 'objet_autrui', 'app_livraisons_tournee_echec', $chemin, $identifiant, 'échec noté sur la livraison d\'un autre livreur : la page répond « '.$etat.' »');
            }
        }

        // « Mes livraisons » (90 jours) : aucune livraison remise par un autre.
        $remises = $this->em->createQueryBuilder()->select('e.numero')->from(BonLivraison::class, 'e')
            ->where('e.livrePar <> :moi')->andWhere('e.statut = :livre')->andWhere('e.livreLe >= :depuis')
            ->setParameter('moi', $compte)->setParameter('livre', BonLivraison::STATUT_LIVRE)->setParameter('depuis', new \DateTimeImmutable('-89 days midnight'))
            ->getQuery()->getSingleColumnResult();
        if ([] !== $remises) {
            $chemin = $this->routeur->generate('app_livraisons_mes_livraisons', ['jours' => 90]);
            $reponse = $this->navigateur->get($chemin, $session);
            $texte = $reponse->texte();
            $vues = array_values(array_filter($remises, static fn (string $n): bool => 1 === preg_match('/(?<![\w-])'.preg_quote($n, '/').'(?![\w-])/u', $texte)));
            $ok = Reponse::OUVERT === $reponse->etat() && [] === $vues;
            $bilan->controler(\sprintf('%s : « Mes livraisons » sans celles des autres livreurs', $identifiant), $ok, $chemin.' → '.$reponse->etat().([] !== $vues ? ' ; montre '.implode(', ', \array_slice($vues, 0, 3)) : ''));
            if ([] !== $vues) {
                $bilan->constater(BilanRecette::FUITE, 'objet_autrui', 'app_livraisons_mes_livraisons', $chemin, $identifiant, 'livraisons d\'autres livreurs dans « Mes livraisons » : '.implode(', ', \array_slice($vues, 0, 3)));
            }
        }
    }

    /**
     * Mode « Voir » (Réglages › Rôles et menus) : un compte caisse dont les
     * ventes, les clients et le montage PAO sont en lecture seule. Chaque
     * envoi de formulaire de ces modules et chaque écran de création ou de
     * modification doit être refusé avant d'arriver au contrôleur ; la
     * recette remplace le contrôleur par une réponse vide pour ne rien
     * changer s'il y arrivait.
     *
     * @param list<User> $comptes
     */
    private function verifierLectureSeule(array $comptes, BilanRecette $bilan): void
    {
        $compte = null;
        // Un compte caisse parmi ceux demandés, sinon parmi toute la démonstration (--compte=demo.oumar).
        foreach ([...$comptes, ...$this->comptes()] as $c) {
            if (!\in_array(User::ROLE_ADMIN, $c->getRoles(), true) && \in_array('ROLE_COMMANDE', $c->getRoles(), true)) {
                $compte = $c;
                break;
            }
        }
        if (null === $compte) {
            $bilan->controler('Mode « Voir » : aucun compte caisse dans la démonstration', false);

            return;
        }
        // Dans la transaction de la recette : annulé à la fin.
        $compte = $this->em->getRepository(User::class)->find($compte->getId()) ?? $compte;
        $compte->definirDroits([...$compte->getDroitsEffectifs(), 'ROLE_PREPRESSE', Droits::LECTURE_VENTES, Droits::LECTURE_CLIENTS, Droits::LECTURE_PAO]);
        $this->em->flush();
        $roles = $compte->getRoles();
        $this->jeton = new UsernamePasswordToken($compte, 'main', $roles);
        $session = $this->navigateur->session($compte);
        $libelle = $compte->getUserIdentifier().' (ventes, clients et PAO en « Voir »)';
        $droitsModules = [];
        foreach (['ventes', 'clients', 'pao'] as $module) {
            array_push($droitsModules, ...ModulesRoles::catalogue()[$module]['droits']);
        }

        $essais = 0;
        $acceptes = [];
        foreach ($this->routeur->getRouteCollection() as $nom => $route) {
            $module = ModulesRoles::moduleEnLecture($nom, $roles);
            $methodes = $route->getMethods();
            $poste = [] === $methodes || \in_array('POST', $methodes, true);
            $ecranEcriture = ([] === $methodes || \in_array('GET', $methodes, true)) && LectureSeuleSubscriber::ecrit('GET', $nom);
            // Une adresse d'un autre module qui exige un droit des ventes, des clients ou de la PAO compte aussi.
            $exigences = $this->droits->exigences($route, $nom, $route->getPath());
            // La règle d'accès demande l'un de ses rôles : elle ne range la route dans ces modules que si tous en sont.
            $regleDuModule = [] !== $exigences['regle'] && [] === array_diff($exigences['regle'], $droitsModules);
            $duModule = null !== $module || $regleDuModule || [] !== array_intersect($droitsModules, $exigences['attributs']);
            if (!$duModule || (!$poste && !$ecranEcriture) || str_starts_with($nom, '_') || str_starts_with($nom, 'api_')) {
                continue;
            }
            $jeux = $this->parametres->remplir($nom, $route);
            if (\is_string($jeux)) {
                continue;
            }
            $chemin = $this->routeur->generate($nom, $jeux[0]);
            $reponse = $poste
                ? $this->navigateur->post($chemin, [], $session, [], true)
                : $this->navigateur->get($chemin, $session, [], true);
            ++$essais;
            if (NavigateurInterne::CONTROLEUR_ATTEINT === $reponse->statut) {
                $acceptes[] = $nom;
                $bilan->constater(null !== $module ? BilanRecette::FUITE : BilanRecette::A_EXAMINER, 'lecture_seule', $nom, $chemin, $libelle, ($poste ? 'envoi (POST)' : 'écran de modification').' accepté jusqu\'au contrôleur'.(null === $module ? ' : la route n\'est pas rangée dans un module en lecture seule' : ''));
            }
        }
        $bilan->controler('Mode « Voir » : '.$libelle, [] === $acceptes, \sprintf('%d envois et écrans de modification essayés, %d arrivés au contrôleur', $essais, \count($acceptes)));
    }

    /**
     * Identifiant d'une fiche qui n'appartient pas à cette personne.
     *
     * @param class-string $classe
     */
    private function autre(string $classe, string $proprietaire, User $compte, string $condition = '1 = 1'): ?int
    {
        $id = $this->em->createQueryBuilder()->select('e.id')->from($classe, 'e')
            ->where('e.'.$proprietaire.' <> :moi')->andWhere($condition)->setParameter('moi', $compte)
            ->orderBy('e.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();

        return null === $id ? null : (int) $id['id'];
    }

    /**
     * Une des fiches est-elle permise à la personne (ses droits, le mode « Voir ») ?
     *
     * @param list<array<string, mixed>> $fiches
     * @param list<string>               $roles
     */
    private function guidePermis(array $fiches, array $roles): bool
    {
        foreach ($fiches as $fiche) {
            if (CatalogueGuide::autorisee($fiche, $this->accorde(...), $roles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $fiches
     *
     * @return list<string>
     */
    private static function droitsDuGuide(array $fiches): array
    {
        return array_values(array_unique(array_map(
            static fn (mixed $droit): string => implode(' ou ', (array) $droit),
            array_merge(...array_map(static fn (array $f): array => $f['droits'], $fiches)),
        )));
    }

    private function accorde(string $attribut): bool
    {
        if (null === $this->jeton) {
            return false;
        }
        // Certains voteurs relisent la personne connectée (Security::isGranted) : elle est posée le temps de la décision.
        $avant = $this->jetons->getToken();
        $this->jetons->setToken($this->jeton);
        try {
            return $this->decisions->decide($this->jeton, [$attribut]);
        } finally {
            $this->jetons->setToken($avant);
        }
    }

    /**
     * Un seul des droits suffit (règle access_control, onglet du menu).
     *
     * @param list<string> $attributs
     */
    private function auMoinsUn(array $attributs, bool $videAccorde = true): bool
    {
        if ([] === $attributs) {
            return $videAccorde;
        }
        foreach ($attributs as $a) {
            if ('PUBLIC_ACCESS' === $a || $this->accorde($a)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tous les droits sont exigés (#[IsGranted] et denyAccessUnlessGranted).
     *
     * @param list<string> $attributs
     */
    private function tous(array $attributs): bool
    {
        foreach ($attributs as $a) {
            // Une expression ou un droit qui dépend de la fiche se juge sur la page elle-même.
            if (str_starts_with($a, 'expression(') || (!str_starts_with($a, 'ROLE_') && !str_starts_with($a, 'IS_'))) {
                continue;
            }
            if (!$this->accorde($a)) {
                return false;
            }
        }

        return true;
    }

    /** Les écrans de production ne répondent qu'au poste d'une machine (ProductionMachineSubscriber). */
    public static function depuisUnPoste(string $route): bool
    {
        return str_starts_with($route, 'app_production_');
    }

    private function posteMachine(): ?string
    {
        $this->posteMachine ??= $this->em->getConnection()->fetchOne("SELECT adresse_ip FROM machines WHERE adresse_ip IS NOT NULL AND adresse_ip <> '' ORDER BY id LIMIT 1");

        return \is_string($this->posteMachine) ? $this->posteMachine : null;
    }

    /** Raison pour laquelle ce montant n'est pas sensible (CONTEXTES_VOULUS), ou null. */
    public static function contexteVoulu(string $contexte): ?string
    {
        foreach (self::CONTEXTES_VOULUS as $motif => $raison) {
            if (preg_match($motif, $contexte)) {
                return $raison;
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param array<string, T> $liste route ou début de nom => raison
     *
     * @return T|null
     */
    public static function raison(array $liste, string $route): mixed
    {
        foreach ($liste as $prefixe => $raison) {
            if ($route === $prefixe || (str_ends_with($prefixe, '_') && str_starts_with($route, $prefixe))) {
                return $raison;
            }
        }

        return null;
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private static function droitsLisibles(array $roles): array
    {
        $catalogue = Droits::catalogue();
        if (\in_array(User::ROLE_ADMIN, $roles, true)) {
            return ['Direction : tous les droits'];
        }

        return array_values(array_map(static fn (string $r): string => $catalogue[$r]['libelle'] ?? $r, array_filter($roles, static fn (string $r): bool => isset($catalogue[$r]))));
    }
}
