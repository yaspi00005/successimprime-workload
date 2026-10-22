<?php

namespace App\Tests\Unit\Controller;

use App\Controller\CategorieProduitController;
use App\Controller\FinitionController;
use App\Controller\FormatController;
use App\Controller\ProduitConfigurationController;
use App\Controller\SupportsController;
use App\Controller\TypesImpressionController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * Anciennes pages en anglais du thème (« Create new », « back to list »…) :
 * celles qu'aucune route n'affichait sont supprimées, les adresses des
 * finitions renvoient à la liste (ajout et modification s'y font), la
 * modification d'un paiement en attente est en français. Les routes en
 * JSON que les scripts du thème appellent (public/assets/js/traitement_*.js,
 * hors du dépôt) restent, et les droits ne changent pas.
 */
final class AnciennesPagesThemeTest extends TestCase
{
    private const RACINE = __DIR__.'/../../..';

    public function testPlusAucunePageAnglaiseDuTheme(): void
    {
        $motifs = ['back to list', 'Create new', "default('Save')", "'button_label': 'Update'", 'This friendly message', '<th>Id</th>', '>edit</a>', '>show</a>'];
        $fautes = [];
        foreach ((new Finder())->files()->in(self::RACINE.'/templates')->name('*.twig') as $fichier) {
            foreach ($motifs as $motif) {
                if (str_contains($fichier->getContents(), $motif)) {
                    $fautes[] = $fichier->getRelativePathname().' : '.$motif;
                }
            }
        }

        self::assertSame([], $fautes);
    }

    /** Aucune page supprimée n'est encore affichée ou incluse quelque part. */
    public function testChaqueGabaritAppeleExiste(): void
    {
        $manquants = [];
        $fichiers = (new Finder())->files()->in([self::RACINE.'/src', self::RACINE.'/templates'])->name(['*.php', '*.twig']);
        foreach ($fichiers as $fichier) {
            foreach (explode("\n", $fichier->getContents()) as $ligne) {
                if (str_contains($ligne, 'ignore missing')) {
                    continue;
                }
                preg_match_all('~[\'"]([a-z0-9_/.-]+\.html\.twig)[\'"]~i', $ligne, $m);
                foreach ($m[1] as $gabarit) {
                    if (!is_file(self::RACINE.'/templates/'.$gabarit)) {
                        $manquants[] = $fichier->getRelativePathname().' : '.$gabarit;
                    }
                }
            }
        }

        self::assertSame([], $manquants);
    }

    public function testLesAnciennesAdressesDesFinitionsRenvoientALaListe(): void
    {
        $methode = new \ReflectionMethod(FinitionController::class, 'anciennePage');
        $routes = array_map(static fn (\ReflectionAttribute $a) => $a->newInstance(), $methode->getAttributes(Route::class));
        self::assertSame(['app_finition_new', 'app_finition_show', 'app_finition_edit'], array_map(static fn (Route $r) => $r->name, $routes));
        self::assertSame(['/new', '/{id}', '/{id}/edit'], array_map(static fn (Route $r) => $r->path, $routes));
        self::assertSame('ROLE_ADMIN', $methode->getAttributes(IsGranted::class)[0]->newInstance()->attribute);

        $routeur = $this->createStub(UrlGeneratorInterface::class);
        $routeur->method('generate')->willReturnCallback(static fn (string $nom) => 'app_finition_index' === $nom ? '/finition' : '/autre');
        $conteneur = new Container();
        $conteneur->set('router', $routeur);
        $controleur = new FinitionController();
        $controleur->setContainer($conteneur);

        $reponse = $controleur->anciennePage();
        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('/finition', $reponse->getTargetUrl());
        self::assertSame(303, $reponse->getStatusCode());
    }

    /**
     * Les scripts du thème (hors du dépôt) appellent encore ces routes.
     * Plus pour les produits : Ventes › Produits et services (docs/produits.md)
     * n'utilise plus les scripts du thème.
     */
    public function testLesRoutesDesScriptsDuThemeRestent(): void
    {
        $noms = [];
        foreach ([FinitionController::class, FormatController::class, SupportsController::class, TypesImpressionController::class, CategorieProduitController::class, ProduitConfigurationController::class] as $classe) {
            foreach ((new \ReflectionClass($classe))->getMethods() as $methode) {
                foreach ($methode->getAttributes(Route::class) as $attribut) {
                    $noms[] = $attribut->newInstance()->name;
                }
            }
        }

        foreach (['finition', 'type_impression', 'support', 'categorie_produit', 'produit_configuration'] as $prefixe) {
            foreach (['index', 'create_ajax', 'get_ajax', 'update_ajax', 'delete_ajax', 'mass_action', 'reorder'] as $suffixe) {
                self::assertContains('app_'.$prefixe.'_'.$suffixe, $noms);
            }
        }
        foreach (['index', 'create_ajax', 'get_ajax', 'update_ajax', 'delete_ajax', 'toggle_publication'] as $suffixe) {
            self::assertContains('app_format_'.$suffixe, $noms);
        }
    }

    public function testLesDroitsNeChangentPas(): void
    {
        foreach (['/finition', '/finition/new', '/finition/3', '/finition/3/edit', '/finition/3/update/ajax', '/finition/mass-action'] as $chemin) {
            self::assertSame(['ROLE_ADMIN'], self::roles($chemin), $chemin);
        }
        self::assertSame(['ROLE_PAIEMENT_ENCAISSER'], self::roles('/paiements/3/edit'));
        self::assertSame(['ROLE_PAIEMENT_ENCAISSER'], self::roles('/paiements/new'));
    }

    /** @return list<string> rôles de la première règle qui correspond, comme le pare-feu */
    private static function roles(string $chemin): array
    {
        $config = Yaml::parseFile(self::RACINE.'/config/packages/security.yaml', Yaml::PARSE_CUSTOM_TAGS);
        foreach ($config['security']['access_control'] as $regle) {
            if (preg_match('{'.$regle['path'].'}', $chemin)) {
                return (array) $regle['roles'];
            }
        }

        self::fail('Aucune règle pour '.$chemin);
    }
}
