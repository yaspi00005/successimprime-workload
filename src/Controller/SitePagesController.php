<?php

namespace App\Controller;

use App\Entity\CategorieProduit;
use App\Entity\PageSite;
use App\Entity\Realisation;
use App\Repository\ArticleConseilRepository;
use App\Repository\AvisClientRepository;
use App\Repository\PageSiteRepository;
use App\Repository\RealisationRepository;
use App\Service\Site\MarkdownSite;
use App\Twig\SiteContenuExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages de contenu du site vitrine (maquettes 04 à 09 et 12) : réalisations,
 * équipements, guide des fichiers, à propos, engagements, questions
 * fréquentes et conseils. Le contenu vient de Réglages › Site vitrine ;
 * une page non publiée répond « introuvable ».
 */
final class SitePagesController extends AbstractController
{
    public function __construct(
        private readonly SiteController $site,
        private readonly PageSiteRepository $pages,
    ) {
    }

    #[Route('/realisations', name: 'app_site_realisations', methods: ['GET'])]
    public function realisations(Request $request, RealisationRepository $realisations, EntityManagerInterface $em): Response
    {
        $categories = [];
        foreach ($em->getRepository(CategorieProduit::class)->findAll() as $categorie) {
            $categories[$categorie->getPublicId()->toRfc4122()] = $categorie;
        }

        $cartes = [];
        $filtres = [];
        foreach ($realisations->publiees() as $rang => $realisation) {
            $carte = self::carte($realisation, $categories[$realisation->getCategorieRef() ?? ''] ?? null, $rang);
            if (null !== $carte['filtre']) {
                $filtres[$carte['filtre']] ??= $carte['categorie'];
            }
            $cartes[] = $carte;
        }
        // Filtre choisi sans JavaScript (?categorie=…) : les autres réalisations sont masquées.
        $filtre = (string) $request->query->get('categorie', '');
        if (!isset($filtres[$filtre])) {
            $filtre = '';
        }

        return $this->site->cache($this->render('site/realisations.html.twig', [
            'cartes' => $cartes,
            'filtres' => $filtres,
            'filtre' => $filtre,
        ]), $request);
    }

    #[Route('/equipements', name: 'app_site_equipements', methods: ['GET'])]
    public function equipements(Request $request): Response
    {
        return $this->page('equipements', 'site/equipements.html.twig', $request);
    }

    #[Route('/guide-fichiers', name: 'app_site_guide', methods: ['GET'])]
    public function guide(Request $request): Response
    {
        return $this->page('guide-fichiers', 'site/guide.html.twig', $request);
    }

    #[Route('/a-propos', name: 'app_site_a_propos', methods: ['GET'])]
    public function aPropos(Request $request, AvisClientRepository $avis): Response
    {
        return $this->page('a-propos', 'site/a_propos.html.twig', $request, ['avis' => $avis->visibles()]);
    }

    #[Route('/nos-engagements', name: 'app_site_engagements', methods: ['GET'])]
    public function engagements(Request $request): Response
    {
        return $this->page('nos-engagements', 'site/engagements.html.twig', $request);
    }

    #[Route('/faq', name: 'app_site_faq', methods: ['GET'])]
    public function faq(Request $request): Response
    {
        $page = $this->pages->publiee('faq') ?? throw $this->createNotFoundException();
        $contenu = MarkdownSite::sections($page->getCorps());

        return $this->site->cache($this->render('site/faq.html.twig', [
            'page' => $page,
            'contenu' => $contenu,
            'donneesFaq' => self::donneesFaq($contenu),
        ]), $request);
    }

    #[Route('/conseils', name: 'app_site_conseils', methods: ['GET'])]
    public function conseils(Request $request, ArticleConseilRepository $articles): Response
    {
        return $this->site->cache($this->render('site/conseils.html.twig', [
            'articles' => $articles->visibles(new \DateTimeImmutable()),
        ]), $request);
    }

    #[Route('/conseils/{slug}', name: 'app_site_conseil', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function conseil(Request $request, string $slug, ArticleConseilRepository $articles): Response
    {
        $maintenant = new \DateTimeImmutable();
        $article = $articles->visible($slug, $maintenant) ?? throw $this->createNotFoundException();

        return $this->site->cache($this->render('site/conseil.html.twig', [
            'article' => $article,
            'autres' => $articles->visibles($maintenant, 3, $article),
        ]), $request);
    }

    /**
     * Ce que la page Réalisations montre d'une réalisation : jamais le nom
     * du client sans son accord.
     *
     * @return array{realisation: Realisation, titre: string, client: ?string, categorie: ?string, filtre: ?string, illustration: string}
     */
    public static function carte(Realisation $realisation, ?CategorieProduit $categorie, int $rang = 0): array
    {
        $nom = null === $categorie ? '' : SiteContenuExtension::sansDemo($categorie->getNom());
        $titre = SiteContenuExtension::sansDemo($realisation->getTitre());
        // Sans photo : une illustration aux couleurs de la famille (orange pour un événement).
        $illustration = preg_match('/[ée]v[ée]nement|festival/iu', $nom.' '.$titre)
            ? 'evenement'
            : SiteController::famille('' !== $nom ? $nom.' '.$titre : $titre, $rang);

        return [
            'realisation' => $realisation,
            'titre' => $realisation->getTitre(),
            'client' => $realisation->getClientAffiche(),
            'categorie' => '' === $nom ? null : $nom,
            'filtre' => '' === $nom ? null : (MarkdownSite::ancre($nom) ?: null),
            'illustration' => $illustration,
        ];
    }

    /**
     * Données structurées FAQPage : chaque « ### » est une question, le
     * texte qui suit sa réponse.
     *
     * @param array{sections: list<array{cartes: list<array{titre: string, blocs: list<array<string, mixed>>}>}>} $contenu
     *
     * @return array<string, mixed>|null
     */
    public static function donneesFaq(array $contenu): ?array
    {
        $questions = [];
        foreach ($contenu['sections'] as $section) {
            foreach ($section['cartes'] as $carte) {
                $reponse = MarkdownSite::texteDesBlocs($carte['blocs']);
                if ('' !== $carte['titre'] && '' !== $reponse) {
                    $questions[] = [
                        '@type' => 'Question',
                        'name' => $carte['titre'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $reponse],
                    ];
                }
            }
        }

        return [] === $questions ? null : ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions];
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function page(string $slug, string $gabarit, Request $request, array $variables = []): Response
    {
        $page = $this->pages->publiee($slug) ?? throw $this->createNotFoundException();

        return $this->site->cache($this->render($gabarit, [
            'page' => $page,
            'contenu' => MarkdownSite::sections($page->getCorps()),
            'definition' => PageSite::PAGES[$slug],
        ] + $variables), $request);
    }
}
