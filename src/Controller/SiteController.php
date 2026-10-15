<?php

namespace App\Controller;

use App\Entity\CategorieProduit;
use App\Repository\RealisationRepository;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\Site\AvantApres;
use App\Service\Site\PhotosSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Site vitrine (maquettes 01 à 12, jalon J6), ouvert à tous. En ligne,
 * l'accueil est servi sur « / » aux visiteurs (DashboardController) ;
 * « /site » le montre aussi sur l'ERP local, pour le prévisualiser.
 */
final class SiteController extends AbstractController
{
    /** Univers mis en avant sur l'accueil (maquette 01). */
    public const UNIVERS_ACCUEIL = 4;

    /** Familles d'univers : mots du nom de la catégorie => couleur, icône et exemples par défaut. */
    public const FAMILLES = [
        'grand' => ['mots' => ['grand format', 'bâche', 'bache', 'banderole', 'vinyle'], 'exemples' => 'Bâches, vinyle, micro-perforé'],
        'textile' => ['mots' => ['textile', 'dtf', 't-shirt', 'tee-shirt', 'polo'], 'exemples' => 'T-shirts, polos, tote bags'],
        'petit' => ['mots' => ['petit format', 'numérique', 'numerique', 'carte', 'flyer', 'papier'], 'exemples' => 'Cartes, flyers, autocollants'],
        'signaletique' => ['mots' => ['signalétique', 'signaletique', 'roll-up', 'enseigne', 'panneau'], 'exemples' => 'Roll-up, Door Shape, enseignes'],
    ];

    /** Photos de l'accueil (PhotosSite) : en haut de la page, en fond de la bande « Un projet à imprimer ? », dans la bande Studio textile. */
    public const PHOTO_ACCROCHE = 'enseigne-succes-imprim-bamako';
    public const PHOTO_PROJET = 'rubans-encre-couleurs';
    public const PHOTO_ATELIER = 't-shirts-dtf-succes-imprim';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/site', name: 'app_site_accueil', methods: ['GET'])]
    public function accueil(Request $request, CatalogueAtelier $atelier, RealisationRepository $realisations, PhotosSite $photos): Response
    {
        $univers = [];
        // Les catégories de démonstration portent « (démo) » : une seule carte par nom affiché.
        foreach ($this->em->getRepository(CategorieProduit::class)->findBy(['publie' => true], ['ordre' => 'ASC', 'nom' => 'ASC']) as $categorie) {
            $nom = trim(str_replace('(démo)', '', (string) $categorie->getNom()));
            if (isset($univers[$nom])) {
                continue;
            }
            $famille = self::famille($nom, \count($univers));
            $univers[$nom] = [
                'categorie' => $categorie,
                'nom' => $nom,
                'famille' => $famille,
                'exemples' => trim((string) $categorie->getDescription()) ?: self::FAMILLES[$famille]['exemples'],
            ];
            if (\count($univers) === self::UNIVERS_ACCUEIL) {
                break;
            }
        }

        // Une photo par univers, d'après sa famille, jamais deux fois la même dans la page.
        $univers = array_values($univers);
        $photosUnivers = $photos->pourFamilles(array_column($univers, 'famille'), [self::PHOTO_ACCROCHE, self::PHOTO_PROJET]);
        foreach (array_keys($univers) as $i) {
            $univers[$i]['photo'] = $photos->photo($photosUnivers[$i]);
        }

        return $this->cache($this->render('site/accueil.html.twig', [
            'univers' => $univers,
            // La bande Studio textile prend la photo des t-shirts si aucun univers ne la montre déjà.
            'photoAtelier' => \in_array(self::PHOTO_ATELIER, $photosUnivers, true) ? null : $photos->photo(self::PHOTO_ATELIER),
            // Bande « Atelier 3D » quand des articles y sont proposés (docs/atelier-3d.md).
            'atelier' => [] !== $atelier->modeles(),
            // Dernière réalisation avec ses photos « Avant » et « Après ».
            'avantApres' => AvantApres::plusRecente($realisations->publiees()),
        ]), $request);
    }

    /** Famille d'un univers d'après le nom de sa catégorie, sinon dans l'ordre des maquettes. */
    public static function famille(string $nom, int $rang = 0): string
    {
        $nom = mb_strtolower($nom);
        foreach (self::FAMILLES as $famille => $definition) {
            foreach ($definition['mots'] as $mot) {
                if (str_contains($nom, $mot)) {
                    return $famille;
                }
            }
        }
        $familles = array_keys(self::FAMILLES);

        return $familles[$rang % \count($familles)];
    }

    /**
     * Pages sans donnée personnelle : gardées 5 minutes par le navigateur.
     * « / » sert aussi le tableau de bord après connexion : le navigateur
     * redemande alors la page à chaque fois (réponse 304 si rien n'a changé).
     * Symfony rend privée toute page qui a regardé l'utilisateur connecté
     * (les abonnés de l'application le font à chaque requête) : on l'en
     * dispense ici, sauf si la session a servi (jeton CSRF d'un formulaire).
     */
    public function cache(Response $reponse, Request $request): Response
    {
        if ($request->hasSession(true) && $request->getSession()->isStarted()) {
            return $reponse;
        }
        $reponse->setPublic();
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        if ('/' === $request->getPathInfo()) {
            $reponse->headers->addCacheControlDirective('no-cache');
            $reponse->setVary('Cookie', false);
            $reponse->setEtag(hash('xxh128', (string) $reponse->getContent()));
            $reponse->isNotModified($request);
        } else {
            $reponse->setMaxAge(300);
        }

        return $reponse;
    }
}
