<?php

namespace App\Controller;

use App\Entity\CategorieProduit;
use App\EventSubscriber\ApostrophesSiteSubscriber;
use App\Repository\AvisClientRepository;
use App\Repository\RealisationRepository;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\Parametres\Parametres;
use App\Service\Site\AtelierDirect;
use App\Service\Site\ContactSite;
use App\Service\Site\EngagementsSite;
use App\Service\Site\EtatCatalogue;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\Site\AvantApres;
use App\Service\Site\CatalogueSite;
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
        'textile' => ['mots' => ['textile', 'dtf', 't-shirt', 'tee-shirt', 'polo', 'événementiel', 'evenementiel', 'événement', 'evenement'], 'exemples' => 'T-shirts, polos, tote bags'],
        'petit' => ['mots' => ['petit format', 'numérique', 'numerique', 'carte', 'flyer', 'papier', 'papeterie'], 'exemples' => 'Cartes, flyers, autocollants'],
        'signaletique' => ['mots' => ['signalétique', 'signaletique', 'roll-up', 'enseigne', 'panneau', 'communication', 'kakémono', 'kakemono', 'publicité', 'publicite'], 'exemples' => 'Roll-up, Door Shape (support de porte), enseignes'],
    ];

    /** Photos de l'accueil (PhotosSite) : en haut de la page, en fond de la bande « Un projet à imprimer ? », dans la bande Studio textile. */
    public const PHOTO_ACCROCHE = 'enseigne-succes-imprim-bamako';
    public const PHOTO_PROJET = 'rubans-encre-couleurs';
    public const PHOTO_ATELIER = 't-shirts-dtf-succes-imprim';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/site', name: 'app_site_accueil', methods: ['GET'])]
    public function accueil(
        Request $request,
        CatalogueAtelier $atelier,
        RealisationRepository $realisations,
        AvisClientRepository $avis,
        PhotosSite $photos,
        CatalogueSite $catalogue,
        EtatCatalogue $etat,
        DelaisCommande $delais,
        Parametres $parametres,
        AtelierDirect $direct,
        EngagementsSite $engagements,
        ContactSite $contact,
    ): Response {
        $maintenant = new \DateTimeImmutable();
        $calculateur = $etat->aUnCalculateur();
        $atelierOuvert = [] !== $atelier->modeles();

        // Les plus demandés (maquette SW02) : vrais produits avec un prix de départ, sinon rien.
        $heures = $parametres->heuresTravail();
        $limite = $delais->reglages()['limite'];
        $phares = [];
        foreach ($catalogue->phares() as $p) {
            $pret = null !== $p['delai'] && $p['delai'] > 0 ? DelaisCommande::calculerPretLe($heures, (int) $p['delai'], $limite, $maintenant)['pret'] : null;
            // Sans photo du produit (bâche, banderole…) : une vraie photo de sa famille (PhotosSite::FAMILLES).
            $phares[] = $p + ['pret' => null === $pret ? null : self::jourCourt($pret), 'photoSecours' => PhotosSite::FAMILLES[$p['famille']][0] ?? null];
        }
        // Les autres familles en pastilles : produits publiés qui ne sont pas déjà en carte.
        $autres = [];
        $pris = array_column($phares, 'id');
        foreach ($catalogue->univers() as $u) {
            foreach ($u['produits'] as $p) {
                if (!\in_array($p['id'], $pris, true) && \count($autres) < 10) {
                    $autres[] = ['nom' => $p['nom'], 'configuration' => $p['configuration'], 'cle' => $u['cle']];
                }
            }
        }

        // Sans produit à prix : les univers (catégories publiées), chacun avec la photo qui lui ressemble.
        $univers = [] === $phares ? $this->univers($photos, $atelierOuvert) : [];

        // Vraies réalisations publiées, avec une photo (4 au plus).
        $travaux = [];
        foreach ($realisations->publiees() as $r) {
            if (null !== $r->getImagePrincipale() && \count($travaux) < 4) {
                $travaux[] = $r;
            }
        }

        $horaires = $contact->horaires($maintenant);
        $standard = $delais->reglages()['standard'];

        return $this->cache($this->render('site/accueil.html.twig', [
            'calculateur' => $calculateur,
            'phares' => $phares,
            'autres' => $autres,
            'univers' => $univers,
            'atelier' => $atelierOuvert,
            'realisations' => $travaux,
            'avantApres' => AvantApres::plusRecente($realisations->publiees()),
            'avis' => $avis->visibles(3),
            // « En direct de l'atelier » : seulement atelier ouvert et chiffres frais (AtelierDirect).
            'direct' => $horaires['ouvert'] ? $direct->chiffres($maintenant) : null,
            'chiffresEngagements' => $engagements->chiffresPublies(),
            'promesses' => \array_slice($engagements->promesses(), 0, 3),
            'delaiVisuel' => $engagements->delaiVisuel(),
            'packs' => $engagements->packs(),
            'largeurMax' => $contact->largeurMax(),
            'delaiStandard' => $standard,
            'position' => $contact->position(),
        ]), $request);
    }

    /**
     * Univers de l'accueil quand aucun produit n'a encore de prix : les
     * catégories publiées (une carte par nom affiché), photo choisie d'après
     * leur nom (PhotosSite::pourUnivers), et la carte Textile (vers le Studio
     * textile) quand il propose des articles et qu'aucune catégorie ne
     * s'appelle déjà ainsi.
     *
     * @return list<array<string, mixed>>
     */
    private function univers(PhotosSite $photos, bool $atelierOuvert): array
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
                'nom' => $nom,
                // Clé d'adresse de l'univers (?categorie=grand-format), jamais l'identifiant.
                'cle' => CatalogueSite::cle($nom),
                'famille' => $famille,
                'exemples' => trim((string) $categorie->getDescription()) ?: self::FAMILLES[$famille]['exemples'],
                'textile' => false,
            ];
            if (\count($univers) === self::UNIVERS_ACCUEIL) {
                break;
            }
        }
        $univers = array_values($univers);
        $textile = array_filter($univers, static fn (array $u): bool => (bool) preg_match('/textile|t-shirt|dtf/iu', $u['nom']));
        if ($atelierOuvert && [] === $textile) {
            if (\count($univers) === self::UNIVERS_ACCUEIL) {
                array_pop($univers);
            }
            $univers[] = ['nom' => 'Textile', 'cle' => null, 'famille' => 'textile', 'exemples' => self::FAMILLES['textile']['exemples'], 'textile' => true];
        }
        // Une photo par univers, jamais deux fois la même dans la page.
        foreach ($photos->pourUnivers($univers, [self::PHOTO_ACCROCHE]) as $i => $nom) {
            $univers[$i]['photo'] = null === $nom ? null : $photos->photo($nom);
        }

        return $univers;
    }

    /** « mer. 21/10 » (date « prête le » des cartes produits). */
    public static function jourCourt(\DateTimeImmutable $jour): string
    {
        return ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'][(int) $jour->format('N')].' '.$jour->format('d/m');
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
            // Même contenu que celui envoyé, apostrophes comprises (ApostrophesSiteSubscriber).
            $reponse->setEtag(hash('xxh128', ApostrophesSiteSubscriber::apostrophes((string) $reponse->getContent())));
            $reponse->isNotModified($request);
        } else {
            $reponse->setMaxAge(300);
        }

        return $reponse;
    }
}
