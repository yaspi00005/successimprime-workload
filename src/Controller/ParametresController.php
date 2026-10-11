<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran Paramètres en tuiles (maquettes S01 à S04).
 */
#[Route('/reglages/parametres', name: 'app_reglages_parametres_')]
final class ParametresController extends AbstractController
{
    private const SESSION_RECENTS = 'si_parametres_recents';
    private const CSRF = 'parametres';

    public function __construct(private readonly Parametres $parametres)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $ouvert = (string) $request->query->get('tuile', '');
        if (!$this->parametres->peutVoir($ouvert)) {
            return $this->page($request);
        }

        $this->noterRecent($request, $ouvert);

        return $this->page($request, $this->parametres->panneau($ouvert));
    }

    #[Route('/journal', name: 'journal', methods: ['GET'])]
    public function journal(): Response
    {
        if ($this->parametres->visibles() === []) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('parametres/journal.html.twig', [
            'lignes' => $this->parametres->journal(),
        ]);
    }

    #[Route('/{tuile}', name: 'panneau', methods: ['GET'], requirements: ['tuile' => '[a-z_]+'])]
    public function panneau(string $tuile, Request $request): Response
    {
        $this->autoriser($tuile);
        $this->noterRecent($request, $tuile);

        return $this->render('parametres/_panneau.html.twig', [
            'panneau' => $this->parametres->panneau($tuile),
            'erreurs' => [],
        ]);
    }

    #[Route('/{tuile}', name: 'enregistrer', methods: ['POST'], requirements: ['tuile' => '[a-z_]+'])]
    public function enregistrer(string $tuile, Request $request): Response
    {
        $this->autoriser($tuile);
        $this->verifierJeton($request);

        $champs = CatalogueParametres::tuiles()[$tuile]['champs'];
        $lecture = CatalogueParametres::lireSaisie($champs, $request->request->all());

        if ($lecture['erreurs'] !== []) {
            $panneau = $this->parametres->panneau($tuile);
            $panneau['valeurs'] = $lecture['valeurs'] + $panneau['valeurs'];

            return $this->page($request, $panneau, $lecture['erreurs'], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $libelle = CatalogueParametres::tuiles()[$tuile]['libelle'];
        $resultat = $this->parametres->enregistrer($tuile, $lecture['valeurs'], $this->utilisateur());

        match ($resultat) {
            'applique' => $this->addFlash('success', $libelle.' : réglage enregistré.'),
            'propose' => $this->addFlash('warning', $libelle.' : changement envoyé, il sera appliqué après validation sur l\'ERP local.'),
            default => $this->addFlash('info', $libelle.' : rien n\'a changé.'),
        };

        return $this->redirectToRoute('app_reglages_parametres_index');
    }

    #[Route('/{tuile}/valider', name: 'valider', methods: ['POST'], requirements: ['tuile' => '[a-z_]+'])]
    public function valider(string $tuile, Request $request): Response
    {
        $this->autoriser($tuile);
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->verifierJeton($request);

        $libelle = CatalogueParametres::tuiles()[$tuile]['libelle'];
        $this->parametres->valider($tuile, $this->utilisateur())
            ? $this->addFlash('success', $libelle.' : changement validé et appliqué.')
            : $this->addFlash('danger', $libelle.' : aucun changement à valider ici.');

        return $this->redirectToRoute('app_reglages_parametres_index');
    }

    #[Route('/{tuile}/refuser', name: 'refuser', methods: ['POST'], requirements: ['tuile' => '[a-z_]+'])]
    public function refuser(string $tuile, Request $request): Response
    {
        $this->autoriser($tuile);
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $this->verifierJeton($request);

        $libelle = CatalogueParametres::tuiles()[$tuile]['libelle'];
        $this->parametres->refuser($tuile, $this->utilisateur())
            ? $this->addFlash('success', $libelle.' : changement refusé.')
            : $this->addFlash('danger', $libelle.' : aucun changement à refuser ici.');

        return $this->redirectToRoute('app_reglages_parametres_index');
    }

    /**
     * @param array<string, mixed>|null $panneau  panneau ouvert d'office
     * @param array<string, string>     $erreurs
     */
    private function page(Request $request, ?array $panneau = null, array $erreurs = [], ?Response $reponse = null): Response
    {
        $familles = $this->parametres->familles();
        if ($familles === []) {
            throw $this->createAccessDeniedException();
        }

        $catalogue = CatalogueParametres::tuiles();
        $visibles = $this->parametres->visibles();
        $recents = array_values(array_filter(
            (array) $request->getSession()->get(self::SESSION_RECENTS, []),
            static fn (string $t): bool => \in_array($t, $visibles, true),
        ));

        return $this->render('parametres/index.html.twig', [
            'familles' => $familles,
            'recents' => array_map(static fn (string $t): array => ['cle' => $t, 'libelle' => $catalogue[$t]['libelle']], $recents),
            'index_recherche' => $this->parametres->indexRecherche($familles),
            'panneau' => $panneau,
            'erreurs' => $erreurs,
        ], $reponse);
    }

    private function autoriser(string $tuile): void
    {
        if (!$this->parametres->peutVoir($tuile)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function noterRecent(Request $request, string $tuile): void
    {
        $session = $request->getSession();
        $session->set(self::SESSION_RECENTS, CatalogueParametres::ajouterRecent((array) $session->get(self::SESSION_RECENTS, []), $tuile));
    }

    private function utilisateur(): ?User
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof User ? $utilisateur : null;
    }
}
