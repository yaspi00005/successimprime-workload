<?php

namespace App\Controller;

use App\Entity\Machines;
use App\Entity\PageSite;
use App\Security\AccesEnLigne;
use App\Service\Site\ChiffresSite;
use App\Service\Site\ContenuSite;
use App\Service\Site\ImagesSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Contenu du site vitrine (Réglages › Site vitrine) : pages, réalisations,
 * avis clients, conseils, partenaires et chiffres.
 *
 * Se modifie sur l'ERP local seulement, puis descend au site avec la
 * synchronisation : en ligne, l'écran est fermé.
 */
#[Route('/reglages/site-vitrine', name: 'app_site_contenu_')]
#[IsGranted('ROLE_SITE_VITRINE')]
final class SiteContenuController extends AbstractController
{
    private const ONGLETS = [...ContenuSite::TYPES, 'chiffres' => ['libelle' => 'Chiffres']];

    private const TYPES = 'pages|realisations|avis|conseils|partenaires';

    public function __construct(
        private readonly ContenuSite $contenu,
        private readonly ChiffresSite $chiffres,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->acces->estEnLigne()) {
            return $this->render('site_contenu/index.html.twig', ['ferme' => true, 'onglets' => self::ONGLETS, 'onglet' => null]);
        }
        $onglet = (string) $request->query->get('onglet', 'pages');
        if (!isset(self::ONGLETS[$onglet])) {
            $onglet = 'pages';
        }

        return $this->render('site_contenu/index.html.twig', [
            'ferme' => false,
            'onglets' => self::ONGLETS,
            'onglet' => $onglet,
            'elements' => match ($onglet) {
                'pages' => $this->contenu->pages(),
                'chiffres' => [],
                default => $this->contenu->liste($onglet),
            },
            'reglagesChiffres' => 'chiffres' === $onglet ? $this->chiffres->reglages() : null,
            'valeursChiffres' => 'chiffres' === $onglet ? $this->chiffres->chiffres() : null,
            'categories' => 'realisations' === $onglet ? self::nomsCategories($this->contenu->categories()) : [],
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/{type}/nouveau', name: 'nouveau', requirements: ['type' => self::TYPES], methods: ['GET', 'POST'])]
    public function nouveau(Request $request, string $type, EntityManagerInterface $em): Response
    {
        if (null !== $ferme = $this->fermeEnLigne()) {
            return $ferme;
        }
        try {
            $objet = $this->contenu->nouveau($type, $request->query->get('page'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('warning', $e->getMessage());

            return $this->redirectToRoute('app_site_contenu_index', ['onglet' => $type]);
        }

        return $this->fiche($request, $type, $objet, $em);
    }

    #[Route('/{type}/{id}', name: 'fiche', requirements: ['type' => self::TYPES, 'id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, string $type, int $id, EntityManagerInterface $em): Response
    {
        if (null !== $ferme = $this->fermeEnLigne()) {
            return $ferme;
        }
        $objet = $this->contenu->trouver($type, $id) ?? throw $this->createNotFoundException();

        return $this->fiche($request, $type, $objet, $em);
    }

    #[Route('/{type}/{id}/supprimer', name: 'supprimer', requirements: ['type' => self::TYPES, 'id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, string $type, int $id): Response
    {
        if (null !== $ferme = $this->fermeEnLigne()) {
            return $ferme;
        }
        $objet = $this->contenu->trouver($type, $id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('site-contenu-supprimer-'.$type.'-'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $this->contenu->supprimer($objet);
        $this->addFlash('success', ContenuSite::TYPES[$type]['supprime'].' Le site le retire à la prochaine synchronisation.');

        return $this->redirectToRoute('app_site_contenu_index', ['onglet' => $type]);
    }

    #[Route('/chiffres', name: 'chiffres', methods: ['POST'])]
    public function chiffres(Request $request): Response
    {
        if (null !== $ferme = $this->fermeEnLigne()) {
            return $ferme;
        }
        if (!$this->isCsrfTokenValid('site-contenu-chiffres', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $this->contenu->enregistrerChiffres($request->request->all());
        $this->addFlash('success', 'Réglages des chiffres enregistrés. Le site les recalcule dans l\'heure.');

        return $this->redirectToRoute('app_site_contenu_index', ['onglet' => 'chiffres']);
    }

    private function fiche(Request $request, string $type, object $objet, EntityManagerInterface $em): Response
    {
        $resultat = ['erreurs' => [], 'avertissements' => []];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('site-contenu-'.$type, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $nouveau = null === $objet->getId();
            $resultat = $this->contenu->enregistrer($objet, $request->request->all(), $request->files->all(), new \DateTimeImmutable());
            if ([] === $resultat['erreurs']) {
                foreach ($resultat['avertissements'] as $avertissement) {
                    $this->addFlash('warning', $avertissement);
                }
                $this->addFlash('success', $nouveau ? ContenuSite::TYPES[$type]['cree'] : 'Modifications enregistrées.');

                return $this->redirectToRoute('app_site_contenu_fiche', ['type' => $type, 'id' => $objet->getId()]);
            }
        }

        return $this->render('site_contenu/fiche.html.twig', [
            'type' => $type,
            'definition' => ContenuSite::TYPES[$type],
            'objet' => $objet,
            'erreurs' => $resultat['erreurs'],
            'avertissements' => $resultat['avertissements'],
            'categories' => 'realisations' === $type ? $this->contenu->categories() : [],
            'machines' => $objet instanceof PageSite && 'equipements' === $objet->getSlug() ? self::machines($em) : [],
            'imagesMax' => ContenuSite::IMAGES_MAX,
            // Ce que GD sait faire ici : l'aide des champs d'image le dit.
            'formats' => ['jpeg' => ImagesSite::lit(\IMAGETYPE_JPEG), 'webpLu' => ImagesSite::lit(\IMAGETYPE_WEBP), 'webp' => ImagesSite::ecritWebp()],
        ], new Response(status: [] === $resultat['erreurs'] ? 200 : 422));
    }

    /** En ligne, on ne modifie rien : le contenu vient de l'atelier. */
    private function fermeEnLigne(): ?Response
    {
        if (!$this->acces->estEnLigne()) {
            return null;
        }
        $this->addFlash('warning', 'Le contenu du site se modifie sur le serveur de l\'atelier, puis se synchronise.');

        return $this->redirectToRoute('app_site_contenu_index');
    }

    /**
     * Machines de l'atelier pour la page Équipements : nom, type et largeur
     * d'impression seulement (ni coût, ni compteur, ni adresse).
     *
     * @return list<array{nom: string, type: string, largeur: string}>
     */
    private static function machines(EntityManagerInterface $em): array
    {
        $machines = [];
        foreach ($em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']) as $machine) {
            $nom = trim((string) $machine->getNom()) ?: trim(implode(' ', array_filter([(string) $machine->getMarque(), (string) $machine->getModeles()])));
            if ('' === $nom) {
                continue;
            }
            $laize = $machine->getLaizeMetres();
            $machines[] = [
                'nom' => $nom,
                'type' => trim((string) $machine->getTypeMachine()),
                'largeur' => null === $laize ? '' : str_replace('.', ',', rtrim(rtrim(number_format($laize, 2, '.', ''), '0'), '.')).' m',
            ];
        }

        return $machines;
    }

    /**
     * @param list<\App\Entity\CategorieProduit> $categories
     *
     * @return array<string, string> identifiant public => nom
     */
    private static function nomsCategories(array $categories): array
    {
        $noms = [];
        foreach ($categories as $categorie) {
            $noms[$categorie->getPublicId()->toRfc4122()] = (string) $categorie->getNom();
        }

        return $noms;
    }
}
