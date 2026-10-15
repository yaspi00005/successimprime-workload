<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Guide\CatalogueGuide;
use App\Service\Guide\Formation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Guide utilisateur de l'ERP local (docs/guide-utilisateur.md) : le
 * bouton « ? Aide » de l'en-tête ouvre les fiches que le rôle de la
 * personne peut suivre. Une fiche, son impression et ses captures
 * demandent les mêmes droits que l'écran qu'elle décrit ; l'adresse
 * d'une autre fiche tapée à la main est refusée. Fermé en ligne
 * (AideHorsLigneSubscriber).
 */
#[Route('/aide', name: 'app_aide_')]
#[IsGranted('ROLE_USER')]
final class AideController extends AbstractController
{
    /** Captures des fiches, hors du dossier public. */
    public const DOSSIER_CAPTURES = '/ressources/guide/captures';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $fiches = $this->visibles();
        $mesGroupes = $this->groupesAImprimer();

        return $this->render('aide/index.html.twig', [
            // Les groupes de la personne d'abord, puis ceux qu'elle ouvre aussi.
            'groupes' => CatalogueGuide::parGroupe($fiches, $mesGroupes),
            'pour_ecran' => CatalogueGuide::pourEcran($fiches, (string) $request->query->get('ecran', '')),
            'mes_groupes' => $mesGroupes,
        ]);
    }

    #[Route('/imprimer', name: 'imprimer', methods: ['GET'])]
    public function imprimer(Request $request): Response
    {
        $choix = (string) $request->query->get('groupe', '');
        $groupes = match (true) {
            'tout' === $choix => null,
            isset(CatalogueGuide::GROUPES[$choix]) => [$choix],
            default => $this->groupesAImprimer(),
        };
        $fiches = array_values(array_filter($this->visibles(), static fn (array $f): bool => null === $groupes || \in_array($f['groupe'], $groupes, true)));

        return $this->render('aide/imprimer.html.twig', [
            'groupes' => CatalogueGuide::parGroupe($fiches),
            'tailles' => $this->tailles($fiches),
            'choix' => $choix,
        ]);
    }

    /**
     * Fiches du formateur (docs/formation.md) : la demi-journée de chaque
     * rôle, les exercices sur les comptes de démonstration et le résultat
     * attendu. Pour la Direction, qui organise la formation.
     */
    #[Route('/formation', name: 'formation', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function formation(): Response
    {
        return $this->render('aide/formation.html.twig', [
            'plans' => Formation::plans(),
            'fiches' => array_column(CatalogueGuide::fiches(), null, 'cle'),
        ]);
    }

    #[Route('/capture/{nom}.webp', name: 'capture', requirements: ['nom' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function capture(string $nom): BinaryFileResponse
    {
        if (!isset(CatalogueGuide::captures()[$nom])) {
            throw $this->createNotFoundException();
        }
        $permise = false;
        foreach ($this->visibles() as $fiche) {
            foreach ($fiche['captures'] as $capture) {
                $permise = $permise || $capture['nom'] === $nom;
            }
        }
        if (!$permise) {
            throw $this->createAccessDeniedException('Cette image montre un écran que votre rôle n\'ouvre pas.');
        }
        $chemin = $this->dossierProjet.self::DOSSIER_CAPTURES.'/'.$nom.'.webp';
        if (!is_file($chemin)) {
            throw $this->createNotFoundException();
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', 'image/webp');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setPrivate();
        $reponse->setMaxAge(86400);

        return $reponse;
    }

    #[Route('/{cle}', name: 'fiche', requirements: ['cle' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function fiche(string $cle): Response
    {
        $fiche = CatalogueGuide::fiche($cle) ?? throw $this->createNotFoundException();
        if (!CatalogueGuide::autorisee($fiche, $this->isGranted(...), $this->roles())) {
            throw $this->createAccessDeniedException('Cette fiche décrit un écran que votre rôle n\'ouvre pas.');
        }

        // Fiches voisines du même groupe, pour lire le parcours à la suite.
        $groupe = CatalogueGuide::parGroupe($this->visibles())[$fiche['groupe']] ?? [];
        $rang = (int) array_search($cle, array_column($groupe, 'cle'), true);

        return $this->render('aide/fiche.html.twig', [
            'fiche' => $fiche,
            'tailles' => $this->tailles([$fiche]),
            'precedente' => $groupe[$rang - 1] ?? null,
            'suivante' => $groupe[$rang + 1] ?? null,
            'rang' => $rang + 1,
            'total' => \count($groupe),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visibles(): array
    {
        return CatalogueGuide::visibles($this->isGranted(...), $this->roles());
    }

    /**
     * @return list<string>
     */
    private function roles(): array
    {
        $moi = $this->getUser();

        return $moi instanceof User ? array_values($moi->getRoles()) : [];
    }

    /**
     * @return list<string>|null
     */
    private function groupesAImprimer(): ?array
    {
        $moi = $this->getUser();

        if (!$moi instanceof User) {
            return null;
        }

        return CatalogueGuide::groupesAImprimer($moi->getProfil()?->getCode(), $moi->getDroitsAjoutes());
    }

    /**
     * Largeur et hauteur des captures, pour réserver leur place dans la page.
     *
     * @param list<array<string, mixed>> $fiches
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function tailles(array $fiches): array
    {
        $tailles = [];
        foreach ($fiches as $fiche) {
            foreach ($fiche['captures'] as $capture) {
                $taille = @getimagesize($this->dossierProjet.self::DOSSIER_CAPTURES.'/'.$capture['nom'].'.webp');
                if (false !== $taille) {
                    $tailles[$capture['nom']] = [$taille[0], $taille[1]];
                }
            }
        }

        return $tailles;
    }
}
