<?php

namespace App\Service\Recette;

use App\Twig\NavigationExtension;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Symfony\Component\Security\Http\AccessMapInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ce que les droits disent d'une page de l'admin, pour la comparer à ce
 * que la page répond vraiment :
 *  - la règle `access_control` de security.yaml qui couvre son adresse ;
 *  - les #[IsGranted] du contrôleur et les denyAccessUnlessGranted('…')
 *    écrits dans la méthode ;
 *  - l'onglet du menu qui la range (NavigationExtension::ONGLETS), avec le
 *    droit qui fait apparaître cet onglet (matrice « Rôles et menus »).
 */
final class DroitsRoute
{
    public function __construct(
        #[Autowire(service: 'security.access_map')] private readonly AccessMapInterface $carte,
    ) {
    }

    /**
     * @return array{regle: list<string>, attributs: list<string>, menu: list<string>|null, onglet: ?string}
     */
    public function exigences(Route $route, string $nom, string $chemin): array
    {
        [$regle] = $this->carte->getPatterns(Request::create($chemin));
        $menu = self::ongletDe($nom);

        return [
            'regle' => array_values(array_map('strval', $regle ?? [])),
            'attributs' => self::attributsDuControleur($route),
            'menu' => null === $menu ? null : (array) $menu['role'],
            'onglet' => $menu['libelle'] ?? null,
        ];
    }

    /**
     * Onglet du menu qui range cette route : celui dont le début de nom de
     * route est le plus long.
     *
     * @return array{libelle: string, route: string, role: string|list<string>|null, prefixes: list<string>}|null
     */
    public static function ongletDe(string $nom): ?array
    {
        $meilleur = null;
        $longueur = 0;
        foreach (NavigationExtension::ONGLETS as $onglets) {
            foreach ($onglets as $onglet) {
                foreach ($onglet['prefixes'] as $prefixe) {
                    if (str_starts_with($nom, $prefixe) && \strlen($prefixe) > $longueur) {
                        $meilleur = $onglet;
                        $longueur = \strlen($prefixe);
                    }
                }
            }
        }

        return $meilleur;
    }

    /**
     * Droits exigés par le contrôleur : #[IsGranted] de la classe et de la
     * méthode, et denyAccessUnlessGranted('ROLE_…') sans condition écrits
     * dans la méthode.
     *
     * @return list<string>
     */
    public static function attributsDuControleur(Route $route): array
    {
        $controleur = $route->getDefault('_controller');
        if (!\is_string($controleur) || !str_contains($controleur, '::')) {
            return [];
        }
        [$classe, $methode] = explode('::', $controleur, 2);
        if (!method_exists($classe, $methode)) {
            return [];
        }
        $reflexion = new \ReflectionMethod($classe, $methode);
        $attributs = [];
        foreach ([...$reflexion->getDeclaringClass()->getAttributes(IsGranted::class), ...$reflexion->getAttributes(IsGranted::class)] as $attribut) {
            $exigence = $attribut->newInstance()->attribute;
            $attributs[] = $exigence instanceof Expression ? 'expression('.$exigence.')' : (string) $exigence;
        }

        return array_values(array_unique([...$attributs, ...self::refusDansLeCode($reflexion)]));
    }

    /**
     * denyAccessUnlessGranted('ROLE_X') ou (User::ROLE_X) au début d'une
     * ligne de la méthode (pas dans une condition), sans sujet.
     *
     * @return list<string>
     */
    public static function refusDansLeCode(\ReflectionMethod $methode): array
    {
        $fichier = $methode->getFileName();
        if (false === $fichier || false === $methode->getStartLine() || false === $methode->getEndLine()) {
            return [];
        }
        $lignes = \array_slice(file($fichier) ?: [], $methode->getStartLine() - 1, $methode->getEndLine() - $methode->getStartLine() + 1);
        $code = implode('', $lignes);
        // Le corps seulement : après l'accolade qui ouvre la méthode.
        $debut = strpos($code, '{', (int) strpos($code, 'function'));

        return self::refusDansTexte(false === $debut ? $code : substr($code, $debut + 1));
    }

    /**
     * Appels au premier niveau du corps seulement : un refus dans un bloc
     * (« if » d'un cas particulier, comme le solde d'ouverture d'une
     * commande) ne vaut pas pour toute la page.
     *
     * @return list<string>
     */
    public static function refusDansTexte(string $code): array
    {
        preg_match_all('/^\s*\$this->denyAccessUnlessGranted\(\s*(?:\'(ROLE_[A-Z_]+)\'|User::(ROLE_[A-Z_]+))\s*\)\s*;/m', $code, $m, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
        $roles = [];
        foreach ($m as $t) {
            $avant = substr($code, 0, $t[0][1]);
            if (substr_count($avant, '{') - substr_count($avant, '}') > 0) {
                continue;
            }
            $roles[] = '' !== ($t[1][0] ?? '') && -1 !== $t[1][1] ? $t[1][0] : (string) \constant(\App\Entity\User::class.'::'.$t[2][0]);
        }

        return array_values(array_unique($roles));
    }
}
