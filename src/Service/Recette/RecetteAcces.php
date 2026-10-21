<?php

namespace App\Service\Recette;

use App\Controller\NomsCommerciauxController;
use App\Controller\TourneeController;
use App\Entity\BonLivraison;
use App\Entity\BulletinPaie;
use App\Entity\Clients;
use App\Entity\DemandeEmploye;
use App\Entity\EnvoiMessage;
use App\Entity\Pointage;
use App\Entity\Reclamation;
use App\Entity\User;
use App\EventSubscriber\LectureSeuleSubscriber;
use App\Security\Droits;
use App\Security\LivreurSeul;
use App\Security\ModulesRoles;
use App\Service\Corbeille\TypesCorbeille;
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
use Symfony\Component\Uid\Uuid;

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
        'app_fichier_dossier_partage' => 'Dossier partagé des fichiers : à la prépresse (denyAccessUnlessGranted ROLE_PREPRESSE), lien depuis Prépresse et BAT (docs/prepresse.md).',
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
        'app_finances_encaissements' => [['ROLE_PAIEMENT_ENCAISSER', 'ROLE_PAIEMENT_VOIR'], 'paiements reçus et relevé Orange Money, le travail de la caisse : le moyen nommé est celui du paiement, aucun solde de compte'],
        'app_clients_detail' => [['ROLE_PAIEMENT_VOIR'], 'paiements du client avec leur moyen (chèque, banque) : aucun solde de compte de trésorerie'],
        'app_clients_soldes' => [['ROLE_SOLDE_CLIENT_REMBOURSER', 'ROLE_PAIEMENT_VOIR'], 'versements et remboursements des comptes clients, rendus par la caisse (réponse de Yaya du 18/10, Q2)'],
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
        'app_recherche', 'app_messages_clients_index', 'app_planning_index', 'app_livraisons_index', 'app_bons_livraison_index',
        'app_livraisons_a_retirer', 'app_livraisons_planning', 'app_livraisons_suivi', 'app_reclamation_index',
    ];

    /** Envois d'essai de la recette des crédits SMS et e-mail (annulés à la fin). */
    private const ESSAI_NUMERO = '22376009988';
    private const ESSAI_ADRESSE = 'recette.credits@exemple.ml';
    /** Objet d'e-mail d'essai : un modèle peut y mettre le nom du client, caché sans le droit Clients. */
    private const ESSAI_OBJET = 'Rappel pour Témoin Recette';
    /** Destinataire d'un code de sécurité du personnel : en entier pour la direction seulement. */
    private const ESSAI_PERSONNEL = 'recette.personnel@exemple.ml';

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
        private readonly RecetteRapportsMail $rapportsMail,
        private readonly RecetteProductionRouleau $productionRouleau,
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
        if (preg_match('#^/(nos-actions-sociales|nous-soutenir)(/|$)#', $chemin)) {
            return false; // site public (docs/site-vitrine.md) : vérifié avec le visiteur
        }

        // Site public, espace client, liens publics signés : vérifiés à part (visiteur, client).
        return !preg_match('#^/(espace|api/|site|nos-produits|calculateur|studio-textile|atelier-3d|realisations|equipements|guide-fichiers|a-propos|nos-engagements|faq|suivre-ma-commande|suivre-ma-reclamation|comment-venir|contact|conseils|demander-un-devis|sitemap\.xml|robots\.txt|assistant/|avis/|acces/|tablette|f/|d/|ecran-accueil|om/|appareil/autoriser)#', $chemin)
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
                $this->verifierNomsCommerciaux($compte, $bilan);
                $this->verifierCompteClient($compte, $bilan);
                $this->verifierSuppressionClient($compte, $bilan);
                $this->productionRouleau->verifierCompte($compte, fn (string $droit): bool => $this->accorde($droit), $bilan);
            }
            $suivi && $suivi('corbeille');
            try {
                $this->verifierCorbeille($comptes, $bilan);
            } catch (\Throwable $e) {
                $bilan->controler('Corbeille : contrôle interrompu', false, $e::class.' : '.$e->getMessage());
            }
            $suivi && $suivi('lecture seule');
            try {
                $this->verifierLectureSeule($comptes, $bilan);
            } catch (\Throwable $e) {
                // Le bilan des comptes reste écrit ; le contrôle est noté en échec.
                $bilan->controler('Mode « Voir » : contrôle interrompu', false, $e::class.' : '.$e->getMessage());
            }
            $suivi && $suivi('crédits SMS et e-mail');
            try {
                $this->verifierCreditsMessages($comptes, $bilan);
            } catch (\Throwable $e) {
                $bilan->controler('Crédits SMS et e-mail : contrôle interrompu', false, $e::class.' : '.$e->getMessage());
            }
            $suivi && $suivi('rapports par e-mail');
            try {
                $this->rapportsMail->verifier($comptes, $bilan);
            } catch (\Throwable $e) {
                $bilan->controler('Rapports par e-mail : contrôle interrompu', false, $e::class.' : '.$e->getMessage());
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
            // Heures et points d'une journée (Mon pointage) : seulement les siens.
            $autres = $this->journeesDAutrui($reponse, $compte);
            if ($autres > 0) {
                $bilan->constater(BilanRecette::FUITE, 'pointage_autrui', $p['route'], $p['chemin'], $identifiant, \sprintf('%d journée(s) de pointage d\'autres personnes (heures, points) sans le droit RH', $autres));
            }
        }
    }

    /** Journées de pointage d'autres personnes affichées dans la page. */
    private function journeesDAutrui(Reponse $reponse, User $compte): int
    {
        $journees = ContenuSensible::journeesPointage($reponse->contenu);

        return [] === $journees ? 0 : (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pointage WHERE id IN ('.implode(',', $journees).') AND utilisateur_id <> ?', [$compte->getId()]);
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
        $bilan->controler(\sprintf('%s : tableau de bord, clients, commandes, statistiques, recherche, messages des clients, listes des livraisons et suivi refusés', $identifiant), [] === $ouvertes, [] === $ouvertes ? \sprintf('%d pages refusées', $essayees) : implode(', ', $ouvertes));

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
                $this->verifierMonPointage($compte, $pointage, $bilan);
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
     * Mon pointage (heures et points de chaque journée) ne montre que les
     * journées de la personne connectée, même quand l'adresse désigne
     * quelqu'un d'autre (utilisateur, id, mois de son pointage).
     */
    private function verifierMonPointage(User $compte, int $pointage, BilanRecette $bilan): void
    {
        if (null === $compte->getEmploye() || null === $this->routeur->getRouteCollection()->get('app_mon_espace_pointage')) {
            return;
        }
        $identifiant = $compte->getUserIdentifier();
        $autre = $this->em->getConnection()->fetchAssociative('SELECT utilisateur_id, DATE_FORMAT(arrivee_le, \'%Y-%m\') AS mois FROM pointage WHERE id = ?', [$pointage]);
        if (false === $autre) {
            return;
        }
        $chemin = $this->routeur->generate('app_mon_espace_pointage', ['mois' => $autre['mois'], 'utilisateur' => $autre['utilisateur_id'], 'id' => $autre['utilisateur_id']]);
        $reponse = $this->navigateur->get($chemin, $this->navigateur->session($compte));
        $autres = Reponse::OUVERT === $reponse->etat() ? $this->journeesDAutrui($reponse, $compte) : 0;
        $ok = Reponse::OUVERT === $reponse->etat() && 0 === $autres;
        $bilan->controler(\sprintf('%s : « Mon pointage » ne montre que ses propres journées, même avec l\'identifiant d\'un autre dans l\'adresse', $identifiant), $ok, $chemin.' → '.$reponse->etat().($autres > 0 ? ' ; '.$autres.' journée(s) d\'un autre' : ''));
        if ($autres > 0) {
            $bilan->constater(BilanRecette::FUITE, 'pointage_autrui', 'app_mon_espace_pointage', $chemin, $identifiant, $autres.' journée(s) de pointage d\'une autre personne dans « Mon pointage »');
        } elseif (!$ok) {
            $bilan->constater(Reponse::ERREUR === $reponse->etat() ? BilanRecette::ERREUR : BilanRecette::A_EXAMINER, 'mon_pointage', 'app_mon_espace_pointage', $chemin, $identifiant, '« Mon pointage » répond « '.$reponse->etat().' »');
        }
    }

    /**
     * Noms commerciaux du catalogue (docs/noms-commerciaux.md) : l'envoi du
     * formulaire, avec un jeton valide posé dans la session, est refusé à
     * qui n'est pas de la direction. La direction l'envoie sans aucun nom :
     * rien ne change, et la recette annule de toute façon ses écritures.
     */
    private function verifierNomsCommerciaux(User $compte, BilanRecette $bilan): void
    {
        $route = 'app_produits_noms_commerciaux_enregistrer';
        if (null === $this->routeur->getRouteCollection()->get($route)) {
            return;
        }
        $identifiant = $compte->getUserIdentifier();
        $jeton = 'recette-'.bin2hex(random_bytes(8));
        $session = $this->navigateur->session($compte, 'main', ['_csrf/'.NomsCommerciauxController::JETON => $jeton]);
        $chemin = $this->routeur->generate($route);
        $etat = $this->navigateur->post($chemin, ['_token' => $jeton], $session)->etat();
        $direction = $this->accorde('ROLE_ADMIN');
        $ok = $direction ? Reponse::REDIRIGE === $etat : \in_array($etat, [Reponse::REFUSE, Reponse::INTROUVABLE, Reponse::CONNEXION], true);
        $libelle = $direction ? 'noms commerciaux enregistrés par la direction' : 'noms commerciaux refusés hors de la direction';
        $bilan->controler(\sprintf('%s : %s', $identifiant, $libelle), $ok, $chemin.' → '.$etat);
        if (!$ok) {
            $bilan->constater(Reponse::ERREUR === $etat || $direction ? BilanRecette::ERREUR : BilanRecette::FUITE, 'envoi_refuse', $route, $chemin, $identifiant, $libelle.' : l\'envoi répond « '.$etat.' »');
        }
    }

    /**
     * Compte du client à l'imprimerie (docs/finances.md, « Compte du
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
     * Supprimer un client (bouton de la fiche) : la Direction seulement.
     * L'envoi s'arrête à l'entrée du contrôleur : rien n'est supprimé.
     */
    private function verifierSuppressionClient(User $compte, BilanRecette $bilan): void
    {
        $client = $this->em->createQueryBuilder()->select('c.id')->from(Clients::class, 'c')
            ->where('c.id >= :demo')->setParameter('demo', ParametresRoute::PREMIER_ID_DEMO)
            ->orderBy('c.id', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (null === $client) {
            return;
        }
        $identifiant = $compte->getUserIdentifier();
        $chemin = $this->routeur->generate('app_clients_delete', ['id' => (int) $client['id']]);
        $permis = $this->accorde('ROLE_ADMIN');
        $reponse = $this->navigateur->post($chemin, [], $this->navigateur->session($compte), [], true);
        $atteint = NavigateurInterne::CONTROLEUR_ATTEINT === $reponse->statut;
        $bilan->controler(\sprintf('%s : suppression d\'un client %s', $identifiant, $permis ? 'permise (Direction)' : 'refusée'), $atteint === $permis, $chemin.' → '.($atteint ? 'arrivé au contrôleur' : $reponse->etat()));
        if ($atteint && !$permis) {
            $bilan->constater(BilanRecette::FUITE, 'suppression_client', 'app_clients_delete', $chemin, $identifiant, 'suppression d\'un client acceptée sans être la Direction');
        }

        // Le bouton « Supprimer » de la fiche (Yaya, 18/10/2026) : la Direction seulement.
        $publicId = (string) $this->em->find(Clients::class, (int) $client['id'])?->getPublicId();
        if ($this->accorde('ROLE_CLIENT') && '' !== $publicId) {
            $fiche = $this->navigateur->get($this->routeur->generate('app_clients_show', ['publicId' => $publicId]), $this->navigateur->session($compte));
            if (200 === $fiche->statut) {
                $bouton = str_contains($fiche->contenu, 'action="'.$chemin.'"');
                $bilan->controler(\sprintf('%s : bouton « Supprimer » de la fiche client %s', $identifiant, $permis ? 'montré (Direction)' : 'caché'), $bouton === $permis || ($permis && str_contains($fiche->contenu, 'si-fh-suppression-impossible')), $chemin);
                if ($bouton && !$permis) {
                    $bilan->constater(BilanRecette::FUITE, 'suppression_client', 'app_clients_show', $chemin, $identifiant, 'bouton « Supprimer » d\'un client montré sans être la Direction');
                }
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
     * Corbeille (docs/corbeille.md) : l'écran ne s'ouvre qu'à la Direction et
     * au droit « Corbeille » ; une entrée ne se voit, ne se restaure et ne se
     * supprime qu'avec le droit de son genre de fiche (une adresse : Clients ;
     * un jour férié : la Direction), même demandée par son numéro ; en mode
     * « Voir », ni restaurer ni supprimer pour de bon. Deux entrées d'essai
     * sont posées dans la transaction de la recette (annulée à la fin).
     *
     * @param list<User> $comptes
     */
    private function verifierCorbeille(array $comptes, BilanRecette $bilan): void
    {
        if (null === $this->routeur->getRouteCollection()->get('app_corbeille_index')) {
            return;
        }
        $connexion = $this->em->getConnection();
        $entrees = [];
        foreach (['jour_ferie', 'adresse_livraison'] as $table) {
            $libelle = TypesCorbeille::type($table).' « Recette de la corbeille »';
            $connexion->insert('corbeille', [
                'groupe' => Uuid::v7()->toRfc4122(), 'racine' => 1, 'ordre' => 0, 'table_nom' => $table, 'ligne_id' => '0',
                'type' => TypesCorbeille::type($table), 'libelle' => $libelle, 'donnees' => '{"libelle":"Recette de la corbeille"}',
                'mode' => 'ligne', 'supprime_le' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            $entrees[] = ['id' => (int) $connexion->lastInsertId(), 'libelle' => $libelle, 'droit' => TypesCorbeille::droit($table)];
        }

        foreach ($comptes as $compte) {
            $this->essayerCorbeille($compte, $compte->getUserIdentifier(), $entrees, $bilan);
        }

        // Un compte caisse (sans la Direction) à qui l'on donne le droit « Corbeille », puis le mode « Voir ».
        $caisse = null;
        foreach ([...$comptes, ...$this->comptes()] as $c) {
            if (!\in_array(User::ROLE_ADMIN, $c->getRoles(), true) && \in_array('ROLE_COMMANDE', $c->getRoles(), true)) {
                $caisse = $this->em->getRepository(User::class)->find($c->getId()) ?? $c;
                break;
            }
        }
        if (null === $caisse) {
            $bilan->controler('Corbeille : aucun compte caisse dans la démonstration', false);

            return;
        }
        $avant = $caisse->getDroitsEffectifs();
        // Chaque page remet à zéro le gestionnaire d'entités : la fiche est relue avant chaque changement.
        $droits = function (array $droits) use ($caisse): User {
            $compte = $this->em->getRepository(User::class)->find($caisse->getId()) ?? $caisse;
            $compte->definirDroits($droits);
            $this->em->flush();

            return $compte;
        };
        try {
            $compte = $droits([...$avant, Droits::CORBEILLE]);
            $this->essayerCorbeille($compte, $compte->getUserIdentifier().' (avec le droit « Corbeille »)', $entrees, $bilan);

            $compte = $droits([...$avant, Droits::CORBEILLE, Droits::LECTURE_CORBEILLE]);
            $this->essayerCorbeille($compte, $compte->getUserIdentifier().' (corbeille en « Voir »)', $entrees, $bilan, true);
        } finally {
            $droits($avant);
        }
    }

    /**
     * @param list<array{id: int, libelle: string, droit: string}> $entrees
     */
    private function essayerCorbeille(User $compte, string $libelle, array $entrees, BilanRecette $bilan, bool $lecture = false): void
    {
        $roles = $compte->getRoles();
        $this->jeton = new UsernamePasswordToken($compte, 'main', $roles);
        // Jetons valides posés dans la session : un refus vient bien des droits.
        $jeton = 'recette-'.bin2hex(random_bytes(8));
        $valeurs = [];
        foreach ($entrees as $e) {
            $valeurs['_csrf/corbeille-restaurer-'.$e['id']] = $jeton;
            $valeurs['_csrf/corbeille-supprimer-'.$e['id']] = $jeton;
        }
        $session = $this->navigateur->session($compte, 'main', $valeurs);
        $refus = [Reponse::REFUSE, Reponse::INTROUVABLE, Reponse::CONNEXION];
        $direction = $this->accorde(User::ROLE_ADMIN);
        $corbeille = $direction || $this->accorde(Droits::CORBEILLE);

        $index = $this->routeur->generate('app_corbeille_index');
        $liste = $this->navigateur->get($index, $session);
        $ok = $corbeille ? Reponse::OUVERT === $liste->etat() : \in_array($liste->etat(), $refus, true);
        $bilan->controler(\sprintf('Corbeille : %s %s l\'écran', $libelle, $corbeille ? 'ouvre' : 'n\'ouvre pas'), $ok, $index.' → '.$liste->etat());
        if (!$ok && Reponse::OUVERT === $liste->etat()) {
            $bilan->constater(BilanRecette::FUITE, 'corbeille', 'app_corbeille_index', $index, $libelle, 'corbeille ouverte sans la Direction ni le droit « Corbeille »');
        }

        foreach ($entrees as $e) {
            $permise = $corbeille && ($direction || $this->accorde($e['droit']));
            if (Reponse::OUVERT === $liste->etat() && !$permise && str_contains($liste->texte(), $e['libelle'])) {
                $bilan->constater(BilanRecette::FUITE, 'corbeille', 'app_corbeille_index', $index, $libelle, $e['libelle'].' montré dans la liste sans le droit '.$e['droit']);
            }
            $chemin = $this->routeur->generate('app_corbeille_voir', ['id' => $e['id']]);
            $etat = $this->navigateur->get($chemin, $session)->etat();
            $ok = $permise ? Reponse::OUVERT === $etat : \in_array($etat, $refus, true);
            $bilan->controler(\sprintf('Corbeille : %s %s %s', $libelle, $permise ? 'ouvre' : 'n\'ouvre pas', $e['libelle']), $ok, $chemin.' → '.$etat);
            if (!$ok && !$permise) {
                $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::FUITE, 'corbeille', 'app_corbeille_voir', $chemin, $libelle, $e['libelle'].' ouvert sans le droit '.$e['droit'].' : '.$etat);
            }

            // Restaurer, supprimer pour de bon : refusés sans le droit de la fiche, et en mode « Voir » avant le contrôleur.
            if ($permise && !$lecture) {
                continue;
            }
            foreach (['app_corbeille_restaurer', 'app_corbeille_supprimer'] as $route) {
                $chemin = $this->routeur->generate($route, ['id' => $e['id']]);
                $reponse = $this->navigateur->post($chemin, ['_token' => $jeton], $session, [], $lecture);
                $toujours = false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM corbeille WHERE id = ?', [$e['id']]);
                $ok = \in_array($reponse->etat(), $refus, true) && NavigateurInterne::CONTROLEUR_ATTEINT !== $reponse->statut && $toujours;
                $quoi = 'app_corbeille_restaurer' === $route ? 'restaure' : 'supprime pour de bon';
                $bilan->controler(\sprintf('Corbeille : %s %s %s : refusé', $libelle, $quoi, $e['libelle']), $ok, $chemin.' → '.(NavigateurInterne::CONTROLEUR_ATTEINT === $reponse->statut ? 'contrôleur atteint' : $reponse->etat()).($toujours ? '' : ', entrée retirée'));
                if (!$ok) {
                    $bilan->constater(Reponse::ERREUR === $reponse->etat() ? BilanRecette::ERREUR : BilanRecette::FUITE, 'corbeille', $route, $chemin, $libelle, \sprintf('%s %s accepté%s', $quoi, $e['libelle'], $lecture ? ' en mode « Voir »' : ' sans le droit '.$e['droit']));
                }
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
     * Crédits SMS et e-mail (docs/messagerie-marketing.md) : l'historique
     * des envois et son export ne montrent le numéro, l'adresse et l'objet
     * qu'avec le droit Clients, et le destinataire d'un message au personnel
     * (code de sécurité de l'administration en ligne) qu'à la direction ;
     * sans le droit « Crédits SMS et e-mail », l'écran, l'historique et
     * l'export sont refusés, adresse tapée ou non. Trois envois d'essai et
     * les droits prêtés restent dans la transaction de la recette : annulés
     * à la fin.
     *
     * @param list<User> $comptes
     */
    private function verifierCreditsMessages(array $comptes, BilanRecette $bilan): void
    {
        if (null === $this->routeur->getRouteCollection()->get('app_credits_messages_envois')) {
            return;
        }
        $le = new \DateTimeImmutable();
        $this->em->persist(EnvoiMessage::nouveau(EnvoiMessage::SMS, 'relance_retrait', self::ESSAI_NUMERO, EnvoiMessage::ENVOYE, null, 1, null, $le));
        $this->em->persist(EnvoiMessage::nouveau(EnvoiMessage::EMAIL, 'code_connexion', self::ESSAI_ADRESSE, EnvoiMessage::ECHEC, 'Boîte pleine', 0, self::ESSAI_OBJET, $le));
        $this->em->persist(EnvoiMessage::nouveau(EnvoiMessage::EMAIL, 'code_securite', self::ESSAI_PERSONNEL, EnvoiMessage::ENVOYE, null, 1, 'Votre code de sécurité Succès Imprim', $le));
        $this->em->flush();
        $pages = [
            'app_credits_messages_index' => $this->routeur->generate('app_credits_messages_index'),
            'app_credits_messages_envois' => $this->routeur->generate('app_credits_messages_envois'),
            'app_credits_messages_export' => $this->routeur->generate('app_credits_messages_export'),
        ];
        // Recherche du destinataire d'un message au personnel : trouvée par la direction seulement.
        $recherche = $this->routeur->generate('app_credits_messages_envois', ['q' => 'recette.personnel']);

        // Sans le droit : tout refusé.
        foreach ($comptes as $compte) {
            $this->jeton = new UsernamePasswordToken($compte, 'main', $compte->getRoles());
            if ($this->accorde('ROLE_CREDITS_MESSAGES')) {
                continue;
            }
            $session = $this->navigateur->session($compte);
            $ouvertes = [];
            foreach ($pages as $route => $chemin) {
                $etat = $this->navigateur->get($chemin, $session)->etat();
                if (!\in_array($etat, [Reponse::REFUSE, Reponse::CONNEXION], true)) {
                    $ouvertes[] = $chemin.' → '.$etat;
                    $bilan->constater(Reponse::ERREUR === $etat ? BilanRecette::ERREUR : BilanRecette::FUITE, 'sans_droit', $route, $chemin, $compte->getUserIdentifier(), 'crédits SMS et e-mail ouverts sans le droit : la page répond « '.$etat.' »');
                }
            }
            $bilan->controler(\sprintf('%s : crédits SMS et e-mail refusés sans le droit', $compte->getUserIdentifier()), [] === $ouvertes, [] === $ouvertes ? 'écran, historique et export refusés' : implode(' ; ', $ouvertes));
        }

        $client = ['76 00 99 88', '76009988', 'recette.credits', 'Témoin Recette'];
        $profils = [
            // Avec le droit seul (sans le droit Clients) : numéros et adresses masqués, à l'écran et dans l'export.
            'masqué (droit Crédits sans le droit Clients)' => [
                static fn (array $r): bool => !\in_array(User::ROLE_ADMIN, $r, true) && !\in_array('ROLE_CLIENT', $r, true) && !LivreurSeul::est($r),
                // Sans le droit Clients, la recherche n'est pas proposée (et ignorée) : rien à essayer.
                [...$client, 'recette.personnel'], ['•• •• 99 88'], null,
            ],
            // Droit Clients sans être la direction : les clients en entier, le personnel masqué (et introuvable par la recherche).
            'clients en entier, personnel masqué (droit Clients, pas la direction)' => [
                static fn (array $r): bool => !\in_array(User::ROLE_ADMIN, $r, true) && \in_array('ROLE_CLIENT', $r, true) && !LivreurSeul::est($r),
                ['recette.personnel'], ['76 00 99 88', 'recette.credits'], false,
            ],
            // La direction : tout en entier, et la recherche trouve le message au personnel.
            'complet (direction)' => [
                static fn (array $r): bool => \in_array(User::ROLE_ADMIN, $r, true),
                [], ['76 00 99 88', 'recette.credits', 'Témoin Recette', 'recette.personnel'], true,
            ],
        ];
        foreach ($profils as $libelle => [$convient, $interdits, $attendus, $trouve]) {
            $compte = null;
            foreach ($comptes as $c) {
                if ($convient($c->getRoles())) {
                    $compte = $c;
                    break;
                }
            }
            if (null === $compte) {
                $bilan->controler('Crédits SMS et e-mail : aucun compte pour vérifier l\'historique '.$libelle, false);
                continue;
            }
            if (!\in_array(User::ROLE_ADMIN, $compte->getRoles(), true)) {
                $compte = $this->em->getRepository(User::class)->find($compte->getId()) ?? $compte;
                $compte->definirDroits([...$compte->getDroitsEffectifs(), 'ROLE_CREDITS_MESSAGES']);
                $this->em->flush();
            }
            $this->verifierMasquageCredits($compte, $pages + ['recherche' => $recherche], $interdits, $attendus, $trouve, $libelle, $bilan);
        }
    }

    /**
     * @param array<string, string> $pages
     * @param list<string>          $interdits formes complètes qui ne doivent pas paraître (fuite)
     * @param list<string>          $attendus  ce qui doit paraître sur chaque page
     * @param bool|null             $trouve    la recherche du destinataire du personnel doit-elle le trouver ? (null : pas de recherche)
     */
    private function verifierMasquageCredits(User $compte, array $pages, array $interdits, array $attendus, ?bool $trouve, string $libelle, BilanRecette $bilan): void
    {
        $identifiant = $compte->getUserIdentifier();
        $this->jeton = new UsernamePasswordToken($compte, 'main', $compte->getRoles());
        $session = $this->navigateur->session($compte);
        $constats = [];
        foreach (null === $trouve ? ['app_credits_messages_index', 'app_credits_messages_envois', 'app_credits_messages_export'] : ['app_credits_messages_index', 'app_credits_messages_envois', 'app_credits_messages_export', 'recherche'] as $route) {
            $reponse = $this->navigateur->get($pages[$route], $session);
            $texte = $reponse->estHtml() ? $reponse->texte() : $reponse->contenu;
            if (Reponse::OUVERT !== $reponse->etat()) {
                $constats[] = $pages[$route].' → '.$reponse->etat();
                $bilan->constater(Reponse::ERREUR === $reponse->etat() ? BilanRecette::ERREUR : BilanRecette::A_EXAMINER, 'menu_refuse', $route, $pages[$route], $identifiant, 'droit « Crédits SMS et e-mail » donné mais la page répond « '.$reponse->etat().' »');
                continue;
            }
            $vus = array_values(array_filter($interdits, static fn (string $f): bool => str_contains($texte, $f)));
            if ([] !== $vus) {
                $constats[] = $pages[$route].' montre '.implode(', ', $vus);
                $bilan->constater(BilanRecette::FUITE, 'destinataire', $route, $pages[$route], $identifiant, 'numéro, adresse ou objet en entier sans le droit : '.implode(', ', $vus));
            }
            if ('recherche' === $route) {
                // Sans la direction, la recherche ne doit pas trouver le message au personnel (ses connexions se devineraient).
                if ($trouve !== !str_contains($texte, 'Aucun envoi avec ces filtres')) {
                    $constats[] = $pages[$route].($trouve ? ' ne trouve pas' : ' trouve').' le message au personnel';
                    if (!$trouve) {
                        $bilan->constater(BilanRecette::FUITE, 'destinataire', 'app_credits_messages_envois', $pages[$route], $identifiant, 'la recherche trouve le destinataire d\'un message au personnel sans être la direction');
                    }
                }
                continue;
            }
            // Écran « +223 76 00 99 88 », export « 00223 76 00 99 88 » (pas de formule dans le tableur).
            $manquent = array_values(array_filter($attendus, static fn (string $f): bool => !str_contains($texte, $f)));
            if ([] !== $manquent) {
                $constats[] = $pages[$route].' sans '.implode(', ', $manquent);
            }
        }
        $bilan->controler(\sprintf('%s : historique des envois %s', $identifiant, $libelle), [] === $constats, [] === $constats ? 'derniers envois, historique, recherche et export CSV vérifiés' : implode(' ; ', $constats));
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
