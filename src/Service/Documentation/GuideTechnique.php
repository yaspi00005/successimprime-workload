<?php

namespace App\Service\Documentation;

use App\Security\Droits;
use App\Security\EntetesSecurite;
use App\Security\ModulesRoles;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Recette\DroitsRoute;
use App\Service\Synchro\CatalogueSynchro;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Yaml\Yaml;

/**
 * Guide technique de l'application (docs/technique/README.md), tiré du code
 * au moment où on le génère : tables (métadonnées Doctrine), routes et
 * règles d'accès, contrôleurs, services, commandes et tâches planifiées,
 * droits, mécanismes de sécurité, synchronisation, paramètres, regroupés
 * par module, avec les chapitres écrits (docs/technique/chapitres) et les
 * points d'audit.
 *
 * Lecture seule : aucune requête sur les données de la base (seulement les
 * métadonnées des entités), aucune valeur de .env.
 */
final class GuideTechnique
{
    /** Routes des outils de développement, absentes en production. */
    private const ROUTES_IGNOREES = '/^_(wdt|profiler|preview_error)/';

    /** Classes de src/EventSubscriber qui sont des mécanismes de sécurité ou de séparation local / en ligne. */
    private const SOUSCRIPTEURS_SECURITE = '/HorsLigne|Acces|Securite|LectureSeule|LivreurSeul|EspaceClient|ForcePassword|ActiveUser|ProductionMachine|Audit|LastUserCookie/';

    /** @var array<string, \ReflectionClass<object>|null> */
    private array $reflexions = [];

    /** @var array<string, string> classe => contenu du fichier (cache) */
    private array $sources = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $routeur,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /**
     * @param list<Command> $commandes commandes de la console (celles de l'application)
     *
     * @return array<string, mixed>
     */
    public function generer(array $commandes): array
    {
        $securiteYaml = $this->yaml('config/packages/security.yaml')['security'] ?? [];
        $routes = $this->routes($securiteYaml);
        $controleurs = $this->controleurs($routes);
        $modulesControleurs = array_column($controleurs, 'module', 'classe');
        $services = $this->services($modulesControleurs);
        $entites = $this->entites($modulesControleurs, array_column($services, 'module', 'classe'));
        $commandesGuide = $this->commandes($commandes);
        $securite = $this->securite($securiteYaml, $routes, $controleurs);

        $guide = [
            'genere_le' => $this->generation(),
            'stats' => [],
            'modules' => [],
            'chapitres' => $this->chapitres(),
            'entites' => $entites,
            'routes' => $routes,
            'controleurs' => $controleurs,
            'services' => $services,
            'commandes' => $commandesGuide,
            'droits' => $this->droits($securiteYaml),
            'securite' => $securite,
            'synchro' => $this->synchro(),
            'parametres' => $this->parametres(),
            'audit' => [],
        ];
        $guide['modules'] = $this->modules($guide);
        $guide['audit'] = AuditTechnique::points($guide);
        $guide['stats'] = $this->stats($guide);

        return $guide;
    }

    // ------------------------------------------------------------------
    // Entités
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $modulesControleurs
     * @param array<string, string> $modulesServices
     *
     * @return list<array<string, mixed>>
     */
    private function entites(array $modulesControleurs, array $modulesServices): array
    {
        $ecouteurs = $this->ecouteursDoctrine();
        $usages = $this->usagesEntites($modulesControleurs + $modulesServices);
        $entites = [];
        /** @var list<ClassMetadata<object>> $metas */
        $metas = $this->em->getMetadataFactory()->getAllMetadata();
        usort($metas, static fn (ClassMetadata $a, ClassMetadata $b): int => $a->getName() <=> $b->getName());
        foreach ($metas as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) {
                continue;
            }
            $classe = $meta->getName();
            $reflexion = $meta->getReflectionClass();
            $module = ModulesTechniques::moduleEntite($classe)
                ?? ModulesTechniques::dominant($usages[$classe] ?? [])
                ?? ModulesTechniques::COMMUN;

            $champs = [];
            foreach ($meta->getFieldNames() as $nom) {
                $m = $meta->getFieldMapping($nom);
                $champ = [
                    'nom' => $nom,
                    'colonne' => $m->columnName,
                    'type' => $m->type,
                    'nullable' => (bool) ($m->nullable ?? false),
                    'longueur' => $m->length ?? null,
                    'precision' => $m->precision ?? null,
                    'echelle' => $m->scale ?? null,
                    'unique' => ($m->unique ?? false) ? true : null,
                    'identifiant' => ($m->id ?? false) ? true : null,
                    'defaut_base' => self::valeurSimple($m->options['default'] ?? null),
                    'defaut_php' => $this->defautPhp($reflexion, $nom),
                ];
                $champs[] = array_filter($champ, static fn (mixed $v): bool => null !== $v);
            }

            $relations = [];
            foreach ($meta->getAssociationMappings() as $nom => $a) {
                $relations[] = self::relation($nom, $a);
            }

            $entites[] = [
                'classe' => $classe,
                'table' => $meta->getTableName(),
                'module' => $module,
                'role' => AnalyseCode::resume($reflexion->getDocComment()),
                'fichier' => $this->relatif((string) $reflexion->getFileName()),
                'champs' => $champs,
                'relations' => $relations,
                'index' => self::index($meta->table['indexes'] ?? []),
                'contraintes_uniques' => self::index($meta->table['uniqueConstraints'] ?? []),
                'rappels_cycle_de_vie' => array_map('array_values', $meta->lifecycleCallbacks),
                'ecouteurs' => array_values(array_unique(array_merge(
                    array_map(static fn (array $l): string => $l['class'].'::'.$l['method'], array_merge(...array_values($meta->entityListeners ?: [[]]))),
                    $ecouteurs['entites'][$classe] ?? [],
                ))),
                'synchronisee' => $this->typeSynchro($classe),
            ];
        }

        return $entites;
    }

    private static function relation(string $nom, object $a): array
    {
        $type = match (true) {
            $a->isManyToOne() => 'ManyToOne',
            $a->isOneToMany() => 'OneToMany',
            $a->isManyToMany() => 'ManyToMany',
            default => 'OneToOne',
        };
        $relation = [
            'nom' => $nom,
            'type' => $type,
            'cible' => $a->targetEntity,
            'mappedBy' => property_exists($a, 'mappedBy') ? $a->mappedBy : null,
            'inversedBy' => property_exists($a, 'inversedBy') ? $a->inversedBy : null,
            'proprietaire' => $a->isOwningSide(),
            'cascade' => array_values($a->cascade),
            'orphanRemoval' => (bool) ($a->orphanRemoval ?? false),
        ];
        if (property_exists($a, 'joinColumns') && \is_array($a->joinColumns) && [] !== $a->joinColumns) {
            $relation['colonnes_jointure'] = array_map(static fn (object $j): array => [
                'colonne' => $j->name,
                'reference' => $j->referencedColumnName,
                'onDelete' => $j->onDelete ?? null,
                'nullable' => $j->nullable ?? true,
            ], $a->joinColumns);
        }
        if (property_exists($a, 'joinTable') && null !== $a->joinTable) {
            $relation['table_jointure'] = $a->joinTable->name;
        }

        return array_filter($relation, static fn (mixed $v): bool => null !== $v);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     *
     * @return list<array{nom: string, colonnes: list<string>}>
     */
    private static function index(array $definitions): array
    {
        $liste = [];
        foreach ($definitions as $nom => $d) {
            $liste[] = ['nom' => (string) $nom, 'colonnes' => array_values((array) ($d['columns'] ?? $d['fields'] ?? []))];
        }

        return $liste;
    }

    /** @param \ReflectionClass<object> $reflexion */
    private function defautPhp(\ReflectionClass $reflexion, string $champ): mixed
    {
        for ($c = $reflexion; false !== $c; $c = $c->getParentClass()) {
            if ($c->hasProperty($champ)) {
                $p = $c->getProperty($champ);

                return $p->hasDefaultValue() ? self::valeurSimple($p->getDefaultValue()) : null;
            }
        }

        return null;
    }

    private static function valeurSimple(mixed $v): mixed
    {
        if (\is_scalar($v) || null === $v) {
            return \is_string($v) ? AnalyseCode::couper($v, 120) : $v;
        }
        if (\is_array($v)) {
            return [] === $v ? [] : (\count($v) <= 12 && array_is_list($v) && array_filter($v, 'is_scalar') === $v ? $v : '[…]');
        }

        return null;
    }

    /**
     * Écouteurs Doctrine déclarés par attribut dans src : par entité et pour tout le flush.
     *
     * @return array{entites: array<string, list<string>>, globaux: list<array{classe: string, evenements: list<string>, resume: string}>}
     */
    private function ecouteursDoctrine(): array
    {
        $entites = [];
        $globaux = [];
        foreach ($this->classesDe('src') as $classe) {
            $r = $this->reflexion($classe);
            if (null === $r) {
                continue;
            }
            $evenements = [];
            foreach ($r->getAttributes(AsDoctrineListener::class) as $attr) {
                $evenements[] = (string) ($attr->getArguments()['event'] ?? $attr->getArguments()[0] ?? '?');
            }
            if ([] !== $evenements) {
                $globaux[] = ['classe' => $classe, 'evenements' => array_values(array_unique($evenements)), 'resume' => AnalyseCode::resume($r->getDocComment())];
            }
            foreach ($r->getAttributes(AsEntityListener::class) as $attr) {
                $args = $attr->getArguments();
                if (isset($args['entity'])) {
                    $entites[$args['entity']][] = $classe.' ('.($args['event'] ?? '?').')';
                }
            }
        }

        return ['entites' => $entites, 'globaux' => $globaux];
    }

    /**
     * Pour chaque entité, combien de contrôleurs et services de chaque module l'utilisent.
     *
     * @param array<string, string> $modules classe => module
     *
     * @return array<string, array<string, int>>
     */
    private function usagesEntites(array $modules): array
    {
        $usages = [];
        foreach ($modules as $classe => $module) {
            $source = $this->source($classe);
            preg_match_all('/^use (App\\\\Entity\\\\[A-Za-z0-9_]+);/m', $source, $m);
            foreach (array_unique($m[1]) as $entite) {
                $usages[$entite][$module] = ($usages[$entite][$module] ?? 0) + 1;
            }
        }

        return $usages;
    }

    private function typeSynchro(string $classe): ?array
    {
        foreach (CatalogueSynchro::types() as $cle => $type) {
            if ($type['classe'] === $classe) {
                return ['type' => $cle, 'sens' => $type['sens'], 'champs' => \count($type['champs'])];
            }
            foreach ($type['enfants'] ?? [] as $enfant => $def) {
                if ($def['classe'] === $classe) {
                    return ['type' => $cle.'.'.$enfant, 'sens' => $type['sens'], 'champs' => \count($def['champs'])];
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Routes
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $securiteYaml
     *
     * @return list<array<string, mixed>>
     */
    private function routes(array $securiteYaml): array
    {
        $acces = $securiteYaml['access_control'] ?? [];
        $pareFeux = $securiteYaml['firewalls'] ?? [];
        $routesAuthentificateurs = $this->routesAuthentificateurs();
        $liste = [];
        foreach ($this->routeur->getRouteCollection() as $nom => $route) {
            if (preg_match(self::ROUTES_IGNOREES, $nom)) {
                continue;
            }
            $liste[] = $this->route($nom, $route, $acces, $pareFeux, $routesAuthentificateurs);
        }
        usort($liste, static fn (array $a, array $b): int => $a['nom'] <=> $b['nom']);

        return $liste;
    }

    /**
     * @param list<array<string, mixed>>          $acces
     * @param array<string, array<string, mixed>> $pareFeux
     * @param array<string, string>               $routesAuthentificateurs
     *
     * @return array<string, mixed>
     */
    private function route(string $nom, Route $route, array $acces, array $pareFeux, array $routesAuthentificateurs): array
    {
        $controleur = $route->getDefault('_controller');
        $controleur = \is_string($controleur) ? $controleur : null;
        $methodes = $route->getMethods();
        $exemple = ReglesAcces::cheminExemple($route);
        $methodeEssai = [] === $methodes ? 'GET' : (\in_array('GET', $methodes, true) ? 'GET' : $methodes[0]);
        $regle = ReglesAcces::regle($acces, $exemple, $methodeEssai);
        // Une route POST seule : la règle d'une écriture peut différer (paiements).
        $pareFeu = ReglesAcces::pareFeu($pareFeux, $exemple);

        $attributs = [];
        $refus = [];
        $verifications = [];
        $corps = '';
        $csrf = null;
        $inerte = false;
        $methodeTrouvee = false;
        if (null !== $controleur && str_contains($controleur, '::')) {
            [$classe, $methode] = explode('::', $controleur, 2);
            $r = $this->reflexion($classe);
            if (null !== $r && $r->hasMethod($methode)) {
                $methodeTrouvee = true;
                $reflexion = $r->getMethod($methode);
                $attributs = DroitsRoute::attributsDuControleur($route);
                $corps = AnalyseCode::corps($reflexion);
                $refus = AnalyseCode::refusAcces($corps);
                $verifications = AnalyseCode::verificationsDroits($corps);
                $csrf = $this->csrf($r, $reflexion, $corps);
                if (null === $csrf && AnalyseCode::verifieOrigine($corps)) {
                    $csrf = 'contrôle de l\'en-tête Origin (formulaire public sans session)';
                }
                $inerte = $this->inerte($r, $corps);
            }
        }
        if (null === $csrf && isset($routesAuthentificateurs[$nom])) {
            $csrf = 'authentificateur '.$routesAuthentificateurs[$nom];
        }
        if (isset($routesAuthentificateurs[$nom]) && null !== $csrf && !str_starts_with($csrf, 'authentificateur')) {
            $csrf .= ' ; authentificateur '.$routesAuthentificateurs[$nom];
        }

        $sansEtat = null !== $pareFeu && ($pareFeu['sans_etat'] || !$pareFeu['securite']);
        $modifie = AnalyseCode::modifieDonnees($methodes, $corps) && !$inerte;
        if (isset($routesAuthentificateurs[$nom]) && [] === array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methodes)) {
            $modifie = true; // la connexion écrit la session
        }
        $roles = $regle['roles'] ?? [];
        $public = (\in_array('PUBLIC_ACCESS', $roles, true) || (null !== $pareFeu && !$pareFeu['securite']))
            && [] === $attributs && [] === array_filter($refus, static fn (string $r): bool => str_starts_with($r, 'ROLE_'));

        return [
            'nom' => $nom,
            'chemin' => $route->getPath(),
            'methodes' => $methodes,
            'controleur' => $controleur,
            'module' => ModulesTechniques::moduleRoute($nom, $route->getPath(), $controleur),
            'acces' => [
                'pare_feu' => $pareFeu['nom'] ?? null,
                // Pare-feu « security: false » (synchronisation) : access_control ne s'applique pas.
                'access_control' => null === $regle || (null !== $pareFeu && !$pareFeu['securite']) ? null : ['chemin' => $regle['chemin'], 'roles' => $regle['roles'], 'methodes' => $regle['methodes'], 'rang' => $regle['rang']],
                'note' => null !== $pareFeu && !$pareFeu['securite'] ? 'Pare-feu sans sécurité Symfony : chaque requête est vérifiée par sa signature dans le contrôleur (docs/synchronisation.md).' : null,
                'is_granted' => $attributs,
                'refus_dans_le_code' => $refus,
                'verifications_dans_le_code' => $verifications,
            ],
            'public' => $public,
            'modifie_donnees' => $modifie,
            'csrf' => null !== $csrf,
            'csrf_detail' => $csrf ?? ($sansEtat ? 'sans objet : pare-feu sans session (signature ou jeton d\'API)' : null),
            'sans_csrf' => $modifie && null === $csrf && !$sansEtat && ($methodeTrouvee || null === $controleur),
            'sensibles' => AnalyseCode::sensibles($nom, $route->getPath()),
        ];
    }

    /**
     * Où la route vérifie son jeton CSRF : attribut, corps de l'action, ou
     * méthode de la classe appelée par l'action (trois niveaux).
     *
     * @param \ReflectionClass<object> $classe
     */
    private function csrf(\ReflectionClass $classe, \ReflectionMethod $methode, string $corps): ?string
    {
        if ([] !== $methode->getAttributes(IsCsrfTokenValid::class) || [] !== $classe->getAttributes(IsCsrfTokenValid::class)) {
            return 'attribut #[IsCsrfTokenValid]';
        }
        if (AnalyseCode::verifieCsrf($corps)) {
            return preg_match('/->createForm(Builder)?\s*\(|->createNamedBuilder/', $corps) && !preg_match('/isCsrfTokenValid|JetonJson/', $corps) ? 'formulaire Symfony' : 'dans l\'action';
        }
        $vus = [$methode->getName() => true];
        $aVoir = [[$corps, 0]];
        while ([] !== $aVoir) {
            [$texte, $niveau] = array_shift($aVoir);
            if ($niveau >= 3) {
                continue;
            }
            foreach (AnalyseCode::appelsInternes($texte) as $appel) {
                if (isset($vus[$appel]) || !$classe->hasMethod($appel)) {
                    continue;
                }
                $vus[$appel] = true;
                $sousCorps = AnalyseCode::corps($classe->getMethod($appel));
                if (AnalyseCode::verifieCsrf($sousCorps)) {
                    return 'méthode '.$appel.'()';
                }
                $aVoir[] = [$sousCorps, $niveau + 1];
            }
        }

        return null;
    }

    /**
     * L'action ne fait que répondre (renvoi, JSON, refus), directement ou par
     * une méthode de la classe qui ne fait que cela (trois niveaux) : elle ne
     * modifie rien, même si elle accepte POST (anciennes pages du thème).
     *
     * @param \ReflectionClass<object> $classe
     */
    private function inerte(\ReflectionClass $classe, string $corps, int $niveau = 0): bool
    {
        $internes = AnalyseCode::inerte($corps);
        if (false === $internes) {
            return false;
        }
        foreach ($internes as $methode) {
            if ($niveau >= 3 || !$classe->hasMethod($methode) || !$this->inerte($classe, AnalyseCode::corps($classe->getMethod($methode)), $niveau + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Routes de connexion traitées par un authentificateur qui vérifie un
     * jeton CSRF (CsrfTokenBadge).
     *
     * @return array<string, string> route => authentificateur
     */
    private function routesAuthentificateurs(): array
    {
        $routes = [];
        foreach (glob($this->projet.'/src/Security/*Authenticator.php') ?: [] as $fichier) {
            $source = (string) file_get_contents($fichier);
            if (!str_contains($source, 'CsrfTokenBadge')) {
                continue;
            }
            $nom = basename($fichier, '.php');
            if (preg_match('/LOGIN_ROUTE\s*=\s*\'(app_[a-z0-9_]+)\'/', $source, $m)) {
                $routes[$m[1]] = $nom;
            }
            if (preg_match('/function supports\(.*?\{(.*?)\n    \}/s', $source, $s) && preg_match_all('/\'(app_[a-z0-9_]+)\'/', $s[1], $m)) {
                foreach ($m[1] as $r) {
                    $routes[$r] = $nom;
                }
            }
        }

        return $routes;
    }

    // ------------------------------------------------------------------
    // Contrôleurs et services
    // ------------------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return list<array<string, mixed>>
     */
    private function controleurs(array $routes): array
    {
        $parClasse = [];
        foreach ($routes as $r) {
            if (null !== $r['controleur'] && str_contains($r['controleur'], '::')) {
                $parClasse[explode('::', $r['controleur'])[0]][] = $r;
            }
        }
        $liste = [];
        foreach ($this->classesDe('src/Controller') as $classe) {
            $r = $this->reflexion($classe);
            if (null === $r || $r->isAbstract() || $r->isInterface()) {
                continue;
            }
            $sesRoutes = $parClasse[$classe] ?? [];
            $modules = [];
            foreach ($sesRoutes as $route) {
                $modules[$route['module']] = ($modules[$route['module']] ?? 0) + 1;
            }
            $module = ModulesTechniques::dominant($modules)
                ?? ([] !== $modules ? array_key_first($modules) : null)
                ?? ModulesTechniques::moduleParMot(ModulesTechniques::nomCourt($classe))
                ?? ModulesTechniques::COMMUN;

            $actions = [];
            $entitesChargees = [];
            foreach ($sesRoutes as $route) {
                $methode = explode('::', $route['controleur'])[1];
                if (!$r->hasMethod($methode)) {
                    continue;
                }
                foreach ($r->getMethod($methode)->getParameters() as $p) {
                    $type = self::typeParametre($p);
                    if (null === $type || !preg_match('/\\\\/', $type) && !class_exists($type) && !interface_exists($type)) {
                        continue;
                    }
                    if (str_starts_with($type, 'App\\Entity\\')) {
                        $entitesChargees[$type] = true;
                    } elseif (!\in_array($type, ['Symfony\\Component\\HttpFoundation\\Request'], true)) {
                        $actions[$type] = true;
                    }
                }
            }
            $constructeur = [];
            if (null !== ($c = $r->getConstructor())) {
                foreach ($c->getParameters() as $p) {
                    $constructeur[] = ['nom' => $p->getName(), 'type' => self::typeParametre($p) ?? 'mixed'];
                }
            }
            $modifiant = array_filter($sesRoutes, static fn (array $x): bool => $x['modifie_donnees']);
            $liste[] = [
                'classe' => $classe,
                'module' => $module,
                'resume' => AnalyseCode::resume($r->getDocComment()),
                'fichier' => $this->relatif((string) $r->getFileName()),
                'is_granted_classe' => array_map(static fn (\ReflectionAttribute $a): string => (string) self::texteAttribut($a), $r->getAttributes(\Symfony\Component\Security\Http\Attribute\IsGranted::class)),
                'routes' => array_values(array_map(static fn (array $x): string => $x['nom'], $sesRoutes)),
                'services_constructeur' => $constructeur,
                'services_actions' => array_keys($actions),
                'entites_chargees' => array_keys($entitesChargees),
                'csrf' => [
                    'routes_modifiant' => \count($modifiant),
                    'avec_jeton' => \count(array_filter($modifiant, static fn (array $x): bool => $x['csrf'])),
                    'appels_isCsrfTokenValid' => substr_count($this->source($classe), 'isCsrfTokenValid('),
                    'sans_jeton' => array_values(array_map(static fn (array $x): string => $x['nom'], array_filter($sesRoutes, static fn (array $x): bool => $x['sans_csrf']))),
                ],
            ];
        }

        return $liste;
    }

    private static function texteAttribut(\ReflectionAttribute $a): string
    {
        $exigence = $a->newInstance()->attribute;

        return $exigence instanceof \Symfony\Component\ExpressionLanguage\Expression ? 'expression('.$exigence.')' : (string) $exigence;
    }

    private static function typeParametre(\ReflectionParameter $p): ?string
    {
        $t = $p->getType();
        if ($t instanceof \ReflectionNamedType) {
            return $t->getName();
        }

        return null === $t ? null : (string) $t;
    }

    /**
     * @param array<string, string> $modulesControleurs
     *
     * @return list<array<string, mixed>>
     */
    private function services(array $modulesControleurs): array
    {
        // Qui utilise chaque service : les contrôleurs (use … ;), pour le module des services à la racine.
        $usages = [];
        foreach ($modulesControleurs as $controleur => $module) {
            preg_match_all('/^use (App\\\\Service\\\\[A-Za-z0-9_\\\\]+);/m', $this->source($controleur), $m);
            foreach (array_unique($m[1]) as $service) {
                $usages[$service][$module] = ($usages[$service][$module] ?? 0) + 1;
            }
        }
        $liste = [];
        foreach ($this->classesDe('src/Service') as $classe) {
            $r = $this->reflexion($classe);
            if (null === $r) {
                continue;
            }
            $methodes = [];
            foreach ($r->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() !== $classe || str_starts_with($m->getName(), '__')) {
                    continue;
                }
                $methodes[] = array_filter([
                    'nom' => $m->getName(),
                    'statique' => $m->isStatic() ?: null,
                    'resume' => AnalyseCode::premiereLigne($m->getDocComment()) ?: null,
                ], static fn (mixed $v): bool => null !== $v);
            }
            $liste[] = [
                'classe' => $classe,
                'sorte' => $r->isInterface() ? 'interface' : ($r->isEnum() ? 'enum' : ($r->isTrait() ? 'trait' : 'classe')),
                'module' => ModulesTechniques::moduleService($classe)
                    ?? ModulesTechniques::dominant($usages[$classe] ?? [])
                    ?? ModulesTechniques::moduleParMot(ModulesTechniques::nomCourt($classe))
                    ?? ModulesTechniques::COMMUN,
                'resume' => AnalyseCode::resume($r->getDocComment()),
                'fichier' => $this->relatif((string) $r->getFileName()),
                'methodes_publiques' => $methodes,
            ];
        }

        return $liste;
    }

    // ------------------------------------------------------------------
    // Commandes et tâches planifiées
    // ------------------------------------------------------------------

    /**
     * @param list<Command> $commandes
     *
     * @return list<array<string, mixed>>
     */
    private function commandes(array $commandes): array
    {
        $planning = $this->planification();
        $liste = [];
        foreach ($commandes as $commande) {
            $nom = (string) $commande->getName();
            if (!str_starts_with($nom, 'app:')) {
                continue;
            }
            // Les commandes sont paresseuses : la vraie classe est derrière LazyCommand.
            if ($commande instanceof LazyCommand) {
                $commande = $commande->getCommand();
            }
            $r = new \ReflectionClass($commande);
            $options = [];
            foreach ($commande->getDefinition()->getOptions() as $o) {
                $options[] = array_filter([
                    'nom' => '--'.$o->getName(),
                    'raccourci' => $o->getShortcut(),
                    'description' => $o->getDescription(),
                    'valeur' => $o->acceptValue() ? ($o->isValueRequired() ? 'requise' : 'facultative').($o->isArray() ? ', plusieurs' : '') : 'aucune',
                    'defaut' => self::defautOption($o),
                ], static fn (mixed $v): bool => null !== $v && '' !== $v);
            }
            $arguments = [];
            foreach ($commande->getDefinition()->getArguments() as $a) {
                $arguments[] = ['nom' => $a->getName(), 'obligatoire' => $a->isRequired(), 'description' => $a->getDescription()];
            }
            $court = ModulesTechniques::nomCourt($r->getName());
            $liste[] = [
                'nom' => $nom,
                'description' => $commande->getDescription(),
                'classe' => $r->getName(),
                'fichier' => $this->relatif((string) $r->getFileName()),
                'resume' => AnalyseCode::resume($r->getDocComment()),
                'module' => self::moduleCommande($nom, $court),
                'options' => $options,
                'arguments' => $arguments,
                'planification' => $planning[$nom] ?? [],
                'planifiee' => isset($planning[$nom]),
            ];
        }
        usort($liste, static fn (array $a, array $b): int => $a['nom'] <=> $b['nom']);

        return $liste;
    }

    private static function moduleCommande(string $nom, string $court): string
    {
        $table = [
            'app:synchro' => 'synchronisation', 'app:espace' => 'espace_client', 'app:site' => 'site_public',
            'app:recette' => 'commun', 'app:demo' => 'commun', 'app:doc' => 'commun', 'app:verifier' => 'commun', 'app:installer' => 'commun',
            'app:sauvegarde' => 'reglages', 'app:corbeille' => 'reglages', 'app:alertes' => 'reglages', 'app:bascule' => 'reglages',
            'app:autoriser-appareil' => 'securite', 'app:creer-utilisateur' => 'securite', 'app:push' => 'commun', 'app:fichiers' => 'securite',
            'app:devis' => 'ventes', 'app:prepresse' => 'atelier', 'app:dossier' => 'atelier', 'app:atelier' => 'atelier',
            'app:executer-decaissements' => 'finances', 'app:alerte-tresorerie' => 'finances', 'app:dotations' => 'finances',
            'app:executer-rappels' => 'finances', 'app:aligner-soldes' => 'finances', 'app:initialiser-plafond' => 'finances',
            'app:primes' => 'equipe', 'app:messagerie-interne' => 'equipe', 'app:entretien' => 'accueil', 'app:notifications' => 'accueil',
            'app:planning' => 'accueil', 'app:rapport' => 'accueil', 'app:messagerie' => 'clients', 'app:envoyer-campagnes' => 'clients',
            'app:credits' => 'clients', 'app:fidelite' => 'clients', 'app:sondages' => 'clients', 'app:promotions' => 'ventes',
            'app:actions-caisse' => 'ventes', 'app:orange-money' => 'finances',
            'app:finances' => 'finances', 'app:immobilisations' => 'finances', 'app:solde-client' => 'finances',
            'app:pointage' => 'equipe', 'app:nettoyer-fichiers' => 'atelier', 'app:poste-machiniste' => 'atelier',
            'app:clients' => 'clients', 'app:fiche-client' => 'clients', 'app:achats' => 'stock',
        ];
        $meilleur = null;
        $longueur = 0;
        foreach ($table as $debut => $module) {
            if (str_starts_with($nom, $debut) && \strlen($debut) > $longueur) {
                $meilleur = $module;
                $longueur = \strlen($debut);
            }
        }

        return $meilleur ?? ModulesTechniques::moduleParMot($court) ?? ModulesTechniques::COMMUN;
    }

    private static function defautOption(InputOption $o): mixed
    {
        if (!$o->acceptValue()) {
            return null;
        }
        $d = $o->getDefault();
        if (\is_string($d) && preg_match('/(secret|cle|key|token|pass|dsn)/i', $o->getName())) {
            return '(masqué)';
        }

        return \is_scalar($d) || (\is_array($d) && [] !== $d && array_filter($d, 'is_scalar') === $d) ? $d : null;
    }

    /**
     * @return array<string, list<array{frequence: string, ou: string, source: string}>>
     */
    private function planification(): array
    {
        $scripts = [];
        foreach (glob($this->projet.'/scripts/windows/*.bat') ?: [] as $f) {
            $scripts[basename($f)] = (string) file_get_contents($f);
        }
        $releves = [TachesPlanifiees::depuisScriptsWindows($scripts)];
        foreach (glob($this->projet.'/docs/*.md') ?: [] as $f) {
            $md = (string) file_get_contents($f);
            $source = 'docs/'.basename($f);
            $ou = 'deploiement.md' === basename($f) ? 'serveur en ligne (crontab)' : 'ERP local ou serveur (crontab d\'exemple)';
            $releves[] = TachesPlanifiees::depuisCrontab($md, $source, $ou);
            $releves[] = TachesPlanifiees::depuisPhrases($md, $source);
        }
        // Les fichiers crontab éventuels du dépôt.
        foreach (array_merge(glob($this->projet.'/scripts/*cron*') ?: [], glob($this->projet.'/scripts/*/*cron*') ?: []) as $f) {
            $releves[] = TachesPlanifiees::depuisCrontab((string) file_get_contents($f), $this->relatif($f), 'crontab du dépôt');
        }

        // Les lignes crontab données en exemple dans le docblock d'une commande.
        foreach (glob($this->projet.'/src/Command/*.php') ?: [] as $f) {
            // Retirer l'astérisque du docblock et rétablir « * /5 » (écrit ainsi pour ne pas fermer le commentaire).
            $texte = (string) preg_replace(['/^[ \t]*\*[ \t]?/m', '#\* /(\d)#'], ['', '*/$1'], (string) file_get_contents($f));
            $releves[] = TachesPlanifiees::depuisCrontab($texte, $this->relatif($f), 'ERP local ou serveur (crontab d\'exemple)');
        }

        return TachesPlanifiees::fusionner($releves);
    }

    // ------------------------------------------------------------------
    // Droits
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $securiteYaml
     *
     * @return array<string, mixed>
     */
    private function droits(array $securiteYaml): array
    {
        $catalogue = [];
        foreach (Droits::catalogue() as $code => $d) {
            $catalogue[] = ['code' => $code, 'libelle' => $d['libelle'], 'groupe' => $d['groupe'], 'description' => $d['detail']];
        }
        $modules = [];
        foreach (ModulesRoles::catalogue() as $cle => $m) {
            $modules[] = ['cle' => $cle] + $m + ['niveaux_possibles' => ModulesRoles::niveauxPossibles($cle)];
        }
        $voteurs = [];
        foreach ($this->classesDe('src/Security') as $classe) {
            $r = $this->reflexion($classe);
            if (null === $r || !$r->isSubclassOf(Voter::class)) {
                continue;
            }
            $attributs = [];
            foreach ($r->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $c) {
                if (\is_string($c->getValue()) && $c->getDeclaringClass()->getName() === $classe) {
                    $attributs[] = $c->getValue();
                }
            }
            $voteurs[] = ['classe' => $classe, 'fichier' => $this->relatif((string) $r->getFileName()), 'resume' => AnalyseCode::resume($r->getDocComment(), 600), 'attributs' => $attributs];
        }

        return [
            'catalogue' => $catalogue,
            'groupes' => array_keys(Droits::parGroupe()),
            'modules_roles' => $modules,
            'montants_masquables' => ModulesRoles::montants(),
            'role_hierarchy' => $securiteYaml['role_hierarchy'] ?? [],
            'roles_par_defaut' => $this->rolesParDefaut(),
            'voteurs' => $voteurs,
        ];
    }

    /**
     * Rôles créés par la migration qui les a mis en base, et les migrations
     * qui ont changé leurs droits depuis. L'état réel est dans Réglages ›
     * Rôles et droits (table role_utilisateur, non lue ici).
     *
     * @return array<string, mixed>
     */
    private function rolesParDefaut(): array
    {
        $initiaux = [];
        $origine = null;
        $ajustements = [];
        foreach (glob($this->projet.'/migrations/Version*.php') ?: [] as $f) {
            $source = (string) file_get_contents($f);
            if (!str_contains($source, 'role_utilisateur')) {
                continue;
            }
            $version = basename($f, '.php');
            $description = preg_match('/function getDescription\(\): string\s*\{\s*return \'((?:[^\'\\\\]|\\\\.)*)\'/', $source, $m) ? stripslashes($m[1]) : '';
            if (null === $origine && preg_match('/private const ROLES = \[(.*?)\n    \];/s', $source, $m)) {
                $origine = $version;
                preg_match_all('/\[\'(ROLE_[A-Z_]+)\', \'((?:[^\'\\\\]|\\\\.)*)\', \[([^\]]*)\], (\d+)\]/', $m[1], $roles, \PREG_SET_ORDER);
                foreach ($roles as $role) {
                    preg_match_all('/\'(ROLE_[A-Z_]+)\'/', $role[3], $droits);
                    $initiaux[] = ['code' => $role[1], 'nom' => stripslashes($role[2]), 'droits' => $droits[1], 'ordre' => (int) $role[4]];
                }
                continue;
            }
            if (preg_match('/(UPDATE|INSERT INTO) role_utilisateur/', $source)) {
                $ajustements[] = ['migration' => $version, 'description' => $description];
            }
        }

        return [
            'source' => null === $origine ? null : 'migrations/'.$origine.'.php',
            'initiaux' => $initiaux,
            'ajustements' => $ajustements,
            'note' => 'Rôles posés par la migration d\'origine, puis changés par les migrations listées ; l\'état réel de chaque installation se lit dans Réglages › Rôles et droits (table role_utilisateur). ROLE_ADMIN reçoit tout par role_hierarchy.',
        ];
    }

    // ------------------------------------------------------------------
    // Sécurité
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed>       $securiteYaml
     * @param list<array<string, mixed>> $routes
     * @param list<array<string, mixed>> $controleurs
     *
     * @return array<string, mixed>
     */
    private function securite(array $securiteYaml, array $routes, array $controleurs): array
    {
        $framework = $this->yaml('config/packages/framework.yaml')['framework'] ?? [];
        $limiteurs = $this->yaml('config/packages/rate_limiter.yaml')['framework']['rate_limiter'] ?? [];
        $csrfYaml = $this->yaml('config/packages/csrf.yaml')['framework'] ?? [];

        $pareFeux = [];
        foreach ($securiteYaml['firewalls'] ?? [] as $nom => $c) {
            $pareFeux[] = array_filter([
                'nom' => $nom,
                'motif' => $c['pattern'] ?? null,
                'securite' => false !== ($c['security'] ?? true),
                'sans_etat' => (bool) ($c['stateless'] ?? false),
                'fournisseur' => $c['provider'] ?? null,
                'authentificateur' => $c['custom_authenticator'] ?? null,
                'verificateur' => $c['user_checker'] ?? null,
                'deconnexion' => isset($c['logout']) ? array_intersect_key((array) $c['logout'], ['path' => 1, 'target' => 1]) : null,
                'rester_connecte' => isset($c['remember_me']) ? array_diff_key((array) $c['remember_me'], ['secret' => 1]) : null,
                'limite_connexions' => $c['login_throttling'] ?? null,
            ], static fn (mixed $v): bool => null !== $v);
        }

        $limites = [];
        foreach ($limiteurs as $nom => $l) {
            $limites[] = ['nom' => $nom, 'politique' => $l['policy'] ?? null, 'limite' => $l['limit'] ?? null, 'intervalle' => $l['interval'] ?? null, 'utilise_par' => $this->utilisationsLimiteur($nom)];
        }

        $sansCsrf = array_values(array_map(static fn (array $r): array => ['nom' => $r['nom'], 'chemin' => $r['chemin'], 'methodes' => $r['methodes'], 'controleur' => $r['controleur']], array_filter($routes, static fn (array $r): bool => $r['sans_csrf'])));
        $csrfParControleur = [];
        foreach ($controleurs as $c) {
            if ($c['csrf']['routes_modifiant'] > 0) {
                $csrfParControleur[] = ['controleur' => $c['classe'], 'appels_isCsrfTokenValid' => $c['csrf']['appels_isCsrfTokenValid'], 'routes_modifiant' => $c['csrf']['routes_modifiant'], 'avec_jeton' => $c['csrf']['avec_jeton'], 'sans_jeton' => $c['csrf']['sans_jeton']];
            }
        }

        $enLigne = [];
        foreach ($this->classesDe('src') as $classe) {
            $n = substr_count($this->source($classe), 'estEnLigne(');
            if ($n > 0 && !str_ends_with($classe, '\\AccesEnLigne')) {
                $enLigne[] = ['classe' => $classe, 'appels_estEnLigne' => $n];
            }
        }
        $horsLigne = [];
        foreach ($this->classesDe('src/EventSubscriber') as $classe) {
            if (str_contains($classe, 'HorsLigne') || str_contains($classe, 'AccesEnLigne') || str_contains($classe, 'ProductionMachine')) {
                $horsLigne[] = ['classe' => $classe, 'resume' => AnalyseCode::resume($this->reflexion($classe)?->getDocComment(), 600)];
            }
        }

        return [
            'mecanismes' => $this->mecanismes($framework, $securiteYaml, $limites),
            'pare_feux' => $pareFeux,
            'access_control' => array_map(static fn (array $r): array => array_filter(['chemin' => $r['path'] ?? null, 'roles' => (array) ($r['roles'] ?? []), 'methodes' => $r['methods'] ?? null], static fn (mixed $v): bool => null !== $v), $securiteYaml['access_control'] ?? []),
            'en_tetes' => [
                'site_public_https' => EntetesSecurite::entetes('text/html', EntetesSecurite::ZONE_PUBLIQUE, true),
                'administration_https' => EntetesSecurite::entetes('text/html', EntetesSecurite::ZONE_ADMIN, true),
                'fichier_servi' => EntetesSecurite::POLITIQUE_FICHIER,
                'mesure_audience' => EntetesSecurite::AJOUTS_MESURE_AUDIENCE,
            ],
            'cookie_session' => $framework['session'] ?? [],
            'mots_de_passe' => $securiteYaml['password_hashers'] ?? [],
            'csrf_configuration' => $csrfYaml['csrf_protection'] ?? [],
            'limiteurs' => $limites,
            'csrf_par_controleur' => $csrfParControleur,
            'routes_modifiant_sans_csrf' => $sansCsrf,
            'separation_en_ligne_local' => ['souscripteurs' => $horsLigne, 'classes_qui_testent_estEnLigne' => $enLigne],
            'ecouteurs_doctrine' => $this->ecouteursDoctrine()['globaux'],
        ];
    }

    /**
     * @param array<string, mixed>       $framework
     * @param array<string, mixed>       $securiteYaml
     * @param list<array<string, mixed>> $limites
     *
     * @return list<array{cle: string, titre: string, fichier: string, resume: string}>
     */
    private function mecanismes(array $framework, array $securiteYaml, array $limites): array
    {
        $liste = [
            ['cle' => 'config:session', 'titre' => 'Cookie de session', 'fichier' => 'config/packages/framework.yaml', 'resume' => 'Cookie de session : '.json_encode($framework['session'] ?? [], \JSON_UNESCAPED_UNICODE).'.'],
            ['cle' => 'config:mots-de-passe', 'titre' => 'Hachage des mots de passe', 'fichier' => 'config/packages/security.yaml', 'resume' => 'password_hashers : '.json_encode($securiteYaml['password_hashers'] ?? [], \JSON_UNESCAPED_UNICODE).' (algorithme choisi par Symfony, bcrypt ou argon2).'],
            ['cle' => 'config:csrf', 'titre' => 'Protection CSRF des formulaires', 'fichier' => 'config/packages/csrf.yaml', 'resume' => 'Jetons CSRF des formulaires et de la connexion (stateless_token_ids : submit, authenticate, logout) ; les actions vérifient isCsrfTokenValid.'],
            ['cle' => 'config:access-control', 'titre' => 'Règles access_control', 'fichier' => 'config/packages/security.yaml', 'resume' => \count($securiteYaml['access_control'] ?? []).' règles, la première qui correspond s\'applique ; la dernière (^/) demande ROLE_USER.'],
            ['cle' => 'config:role-hierarchy', 'titre' => 'Hiérarchie des rôles', 'fichier' => 'config/packages/security.yaml', 'resume' => 'ROLE_ADMIN hérite de tous les droits fins ; ROLE_COMPTABILITE donne ROLE_PAIEMENT_VOIR ; les autres rôles tiennent leurs droits en base (User::getRoles).'],
        ];
        foreach ($securiteYaml['firewalls'] ?? [] as $nom => $c) {
            if (isset($c['login_throttling'])) {
                $liste[] = ['cle' => 'config:limite-connexion:'.$nom, 'titre' => 'Limite des essais de connexion (pare-feu '.$nom.')', 'fichier' => 'config/packages/security.yaml', 'resume' => 'login_throttling : '.json_encode($c['login_throttling'], \JSON_UNESCAPED_UNICODE).'.'];
            }
            if (isset($c['remember_me'])) {
                $liste[] = ['cle' => 'config:rester-connecte:'.$nom, 'titre' => 'Rester connecté (pare-feu '.$nom.')', 'fichier' => 'config/packages/security.yaml', 'resume' => 'remember_me : '.json_encode(array_diff_key((array) $c['remember_me'], ['secret' => 1]), \JSON_UNESCAPED_UNICODE).'.'];
            }
        }
        foreach ($limites as $l) {
            $liste[] = ['cle' => 'limiteur:'.$l['nom'], 'titre' => 'Limiteur '.$l['nom'], 'fichier' => 'config/packages/rate_limiter.yaml', 'resume' => \sprintf('%s essais par %s (%s), utilisé par : %s.', $l['limite'], $l['intervalle'], $l['politique'], [] === $l['utilise_par'] ? 'aucun fichier trouvé' : implode(', ', $l['utilise_par']))];
        }
        if (is_file($this->projet.'/public/uploads/.htaccess')) {
            $liste[] = ['cle' => 'fichier:uploads-htaccess', 'titre' => 'public/uploads/.htaccess', 'fichier' => 'public/uploads/.htaccess', 'resume' => 'Refuse de servir sous Apache un fichier PHP, CGI, HTML, SVG, XML ou JavaScript déposé dans public/uploads (docs/securite.md).'];
        }
        $classes = array_merge(
            $this->classesDe('src/Security'),
            array_filter($this->classesDe('src/EventSubscriber'), static fn (string $c): bool => 1 === preg_match(self::SOUSCRIPTEURS_SECURITE, $c)),
            ['App\\Service\\FichiersPrives'],
        );
        foreach ($classes as $classe) {
            $r = $this->reflexion($classe);
            if (null === $r) {
                continue;
            }
            $liste[] = ['cle' => 'classe:'.$classe, 'titre' => ModulesTechniques::nomCourt($classe), 'fichier' => $this->relatif((string) $r->getFileName()), 'resume' => AnalyseCode::resume($r->getDocComment(), 600)];
        }

        return $liste;
    }

    /**
     * Fichiers de src qui utilisent ce limiteur (argument « $nomEnCamelLimiter » ou nom écrit).
     *
     * @return list<string>
     */
    private function utilisationsLimiteur(string $nom): array
    {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $nom))));
        $fichiers = [];
        foreach ($this->classesDe('src') as $classe) {
            $s = $this->source($classe);
            if (str_contains($s, '$'.$camel.'Limiter') || str_contains($s, "'limiter.".$nom."'") || str_contains($s, "'".$nom."'")) {
                $fichiers[] = ModulesTechniques::nomCourt($classe);
            }
        }

        return $fichiers;
    }

    // ------------------------------------------------------------------
    // Synchronisation, paramètres
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function synchro(): array
    {
        $types = [];
        foreach (CatalogueSynchro::types() as $cle => $t) {
            $enfants = [];
            foreach ($t['enfants'] ?? [] as $nom => $e) {
                $enfants[] = ['nom' => $nom, 'classe' => $e['classe'], 'champs' => $e['champs'], 'relations' => $e['relations'] ?? []];
            }
            $types[] = array_filter([
                'type' => $cle,
                'classe' => $t['classe'],
                'libelle' => $t['libelle'],
                'sens' => $t['sens'],
                'champs' => $t['champs'],
                'relations' => $t['relations'],
                'listes' => $t['listes'],
                'numero' => $t['numero'] ?? null,
                'remontees' => isset($t['remontees']) ? array_keys($t['remontees']) : null,
                'impose_au_local' => isset($t['imposeAuLocal']) ? array_keys($t['imposeAuLocal']) : null,
                'a_la_creation' => isset($t['aLaCreation']) ? array_keys($t['aLaCreation']) : null,
                'filtres' => isset($t['filtres']) ? array_keys($t['filtres']) : null,
                'facultatives' => $t['facultatives'] ?? null,
                'unique_sur' => $t['uniqueSur'] ?? null,
                'condition_envoi' => isset($t['pret']) ? 'oui (pret)' : null,
                'enfants' => [] === $enfants ? null : $enfants,
            ], static fn (mixed $v): bool => null !== $v);
        }

        return [
            'source' => 'src/Service/Synchro/CatalogueSynchro.php',
            'sens' => [
                CatalogueSynchro::DESCENDANT => 'L\'ERP local est maître, le site reçoit.',
                CatalogueSynchro::DEUX => 'Descendant, et les fiches créées en ligne remontent ; une modification faite en ligne d\'une fiche du local est notée comme conflit (sauf les « remontées » permises).',
                CatalogueSynchro::ASCENDANT => 'Créé en ligne, envoyé au local, qui ne renvoie rien.',
            ],
            'types' => $types,
            'reglages_publics' => CatalogueSynchro::REGLAGES_PUBLICS,
            'gestes' => ['reglages' => CatalogueSynchro::ACTION_REGLAGES, 'proposition' => CatalogueSynchro::ACTION_PROPOSITION],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parametres(): array
    {
        $tuiles = [];
        foreach (CatalogueParametres::tuiles() as $cle => $t) {
            $champs = [];
            foreach ($t['champs'] as $c) {
                $secret = 1 === preg_match('/(secret|password|mot_de_passe|cle_api|api_key|token)/i', (string) ($c['cle'] ?? ''));
                $champs[] = array_filter([
                    'cle' => $c['cle'] ?? null,
                    'libelle' => $c['libelle'] ?? null,
                    'type' => $c['type'] ?? null,
                    'defaut' => $secret ? null : self::valeurSimple($c['defaut'] ?? null),
                    'source' => $c['source'] ?? null,
                ], static fn (mixed $v): bool => null !== $v);
            }
            $tuiles[] = ['tuile' => $cle, 'famille' => $t['famille'], 'libelle' => $t['libelle'], 'roles' => $t['roles'], 'route' => $t['route'], 'champs' => $champs, 'publique' => isset(CatalogueSynchro::REGLAGES_PUBLICS[$cle])];
        }

        return ['source' => 'src/Service/Parametres/CatalogueParametres.php', 'familles' => CatalogueParametres::FAMILLES, 'tuiles' => $tuiles];
    }

    // ------------------------------------------------------------------
    // Modules, statistiques, chapitres, génération
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $guide
     *
     * @return list<array<string, mixed>>
     */
    private function modules(array $guide): array
    {
        $modules = [];
        foreach (ModulesTechniques::MODULES as $cle => $m) {
            $modules[$cle] = ['cle' => $cle, 'libelle' => $m['libelle'], 'sorte' => $m['sorte'], 'entites' => [], 'routes' => [], 'controleurs' => [], 'services' => [], 'commandes' => []];
        }
        foreach (['entites' => 'classe', 'routes' => 'nom', 'controleurs' => 'classe', 'services' => 'classe', 'commandes' => 'nom'] as $liste => $champ) {
            foreach ($guide[$liste] as $element) {
                $modules[$element['module']][$liste][] = $element[$champ];
            }
        }

        return array_values($modules);
    }

    /**
     * @param array<string, mixed> $guide
     *
     * @return array<string, mixed>
     */
    private function stats(array $guide): array
    {
        $parType = [];
        foreach ($guide['audit'] as $a) {
            $parType[$a['type']] = ($parType[$a['type']] ?? 0) + 1;
        }

        return [
            'entites' => \count($guide['entites']),
            'routes' => \count($guide['routes']),
            'routes_modifiant_des_donnees' => \count(array_filter($guide['routes'], static fn (array $r): bool => $r['modifie_donnees'])),
            'routes_sans_csrf_signalees' => \count($guide['securite']['routes_modifiant_sans_csrf']),
            'routes_publiques' => \count(array_filter($guide['routes'], static fn (array $r): bool => $r['public'])),
            'controleurs' => \count($guide['controleurs']),
            'services' => \count($guide['services']),
            'commandes' => \count($guide['commandes']),
            'commandes_planifiees' => \count(array_filter($guide['commandes'], static fn (array $c): bool => $c['planifiee'])),
            'migrations' => \count(glob($this->projet.'/migrations/Version*.php') ?: []),
            'tests_unitaires' => $this->compterTests(),
            'droits' => \count($guide['droits']['catalogue']),
            'voteurs' => \count($guide['droits']['voteurs']),
            'types_synchronises' => \count($guide['synchro']['types']),
            'tuiles_parametres' => \count($guide['parametres']['tuiles']),
            'chapitres' => \count($guide['chapitres']),
            'audit' => \count($guide['audit']),
            'audit_par_type' => $parType,
        ];
    }

    private function compterTests(): int
    {
        $n = 0;
        $dossier = $this->projet.'/tests/Unit';
        if (!is_dir($dossier)) {
            return 0;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dossier, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (str_ends_with($f->getFilename(), 'Test.php')) {
                ++$n;
            }
        }

        return $n;
    }

    /**
     * @return list<array{id: string, titre: string, markdown: string}>
     */
    private function chapitres(): array
    {
        $chapitres = [];
        $fichiers = glob($this->projet.'/docs/technique/chapitres/*.md') ?: [];
        sort($fichiers);
        foreach ($fichiers as $f) {
            $md = (string) file_get_contents($f);
            $titre = preg_match('/^#\s+(.+)$/m', $md, $m) ? trim($m[1]) : basename($f, '.md');
            $chapitres[] = ['id' => (string) preg_replace('/^\d+-/', '', basename($f, '.md')), 'titre' => $titre, 'markdown' => $md];
        }

        return $chapitres;
    }

    /**
     * @return array{date: string, commit: ?string, branche: ?string}
     */
    private function generation(): array
    {
        $commit = null;
        $branche = null;
        $git = $this->projet.'/.git';
        if (is_file($git)) { // arbre de travail : « gitdir: … »
            $git = preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m) ? trim($m[1]) : $git;
        }
        $head = @file_get_contents($git.'/HEAD');
        if (\is_string($head)) {
            $head = trim($head);
            if (str_starts_with($head, 'ref: ')) {
                $ref = substr($head, 5);
                $branche = preg_replace('#^refs/heads/#', '', $ref);
                $commit = @file_get_contents($git.'/'.$ref) ?: null;
                $communs = is_file($git.'/commondir') ? $git.'/'.trim((string) file_get_contents($git.'/commondir')) : $git;
                $commit ??= (@file_get_contents($communs.'/'.$ref) ?: null);
                if (null === $commit && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', (string) @file_get_contents($communs.'/packed-refs'), $m)) {
                    $commit = $m[1];
                }
            } else {
                $commit = $head;
            }
        }

        return ['date' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'commit' => null === $commit ? null : trim($commit), 'branche' => $branche];
    }

    // ------------------------------------------------------------------
    // Outils
    // ------------------------------------------------------------------

    /**
     * Classes d'un dossier de src, d'après les fichiers (PSR-4 App\ => src/).
     *
     * @return list<string>
     */
    private function classesDe(string $dossier): array
    {
        $racine = $this->projet.'/'.$dossier;
        if (!is_dir($racine)) {
            return [];
        }
        $classes = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS)) as $f) {
            // Seulement les fichiers nommés comme une classe (src/Repository/.php, vestige du thème, est ignoré).
            if ('php' !== $f->getExtension() || !preg_match('/^[A-Z][A-Za-z0-9_]*\.php$/', $f->getFilename())) {
                continue;
            }
            $relatif = substr($f->getPathname(), \strlen($this->projet.'/src/'), -4);
            $classes[] = 'App\\'.str_replace('/', '\\', $relatif);
        }
        sort($classes);

        return $classes;
    }

    /** @return \ReflectionClass<object>|null */
    private function reflexion(string $classe): ?\ReflectionClass
    {
        if (!\array_key_exists($classe, $this->reflexions)) {
            try {
                $this->reflexions[$classe] = class_exists($classe) || interface_exists($classe) || trait_exists($classe) || enum_exists($classe) ? new \ReflectionClass($classe) : null;
            } catch (\Throwable) {
                $this->reflexions[$classe] = null; // fichier en cours d'écriture, classe introuvable : ignoré
            }
        }

        return $this->reflexions[$classe];
    }

    private function source(string $classe): string
    {
        if (!isset($this->sources[$classe])) {
            $fichier = $this->projet.'/src/'.str_replace('\\', '/', substr($classe, 4)).'.php';
            $this->sources[$classe] = is_file($fichier) ? (string) file_get_contents($fichier) : '';
        }

        return $this->sources[$classe];
    }

    private function relatif(string $chemin): string
    {
        return str_starts_with($chemin, $this->projet.'/') ? substr($chemin, \strlen($this->projet) + 1) : $chemin;
    }

    /** @return array<string, mixed> */
    private function yaml(string $fichier): array
    {
        $chemin = $this->projet.'/'.$fichier;

        return is_file($chemin) ? (array) Yaml::parseFile($chemin) : [];
    }
}
