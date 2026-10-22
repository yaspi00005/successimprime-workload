<?php

namespace App\Controller;

use App\Entity\PointPointage;
use App\Entity\User;
use App\Repository\PointPointageRepository;
use App\Service\Pointage\Pointages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Tablette de l'entrée (maquette E05, à droite) : pour les employés sans
 * accès à l'application, et pour qui a oublié son téléphone. On touche
 * son nom, la caméra s'ouvre, une photo et c'est enregistré. L'heure
 * est celle du serveur.
 *
 * Pas de connexion : la tablette est reconnue par le cookie que la RH a
 * posé en la branchant sur un point de pointage (Réglages du pointage).
 */
#[Route('/tablette', name: 'app_tablette_')]
final class TabletteController extends AbstractController
{
    public const COOKIE = 'si_tablette';
    private const CSRF = 'tablette';

    public function __construct(
        private readonly Pointages $pointages,
        private readonly PointPointageRepository $points,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $point = $this->point($request);
        $maintenant = new \DateTimeImmutable();

        return $this->render('tablette/index.html.twig', [
            'point' => $point,
            'maintenant' => $maintenant,
            'personnes' => $point === null ? [] : array_map(fn (User $u): array => [
                'id' => $u->getId(),
                'nom' => Pointages::nom($u),
                'app' => $u->getAcces() === User::ACCES_ACTIF,
                'prochain' => $this->pointages->prochain($u, $maintenant),
            ], $this->pointages->personnel()),
        ]);
    }

    #[Route('/{id}', name: 'pointer', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function pointer(User $utilisateur, Request $request): Response
    {
        $point = $this->point($request);
        if ($point === null || !\in_array($utilisateur, $this->pointages->personnel(), true)) {
            return $this->redirectToRoute('app_tablette_index');
        }
        $maintenant = new \DateTimeImmutable();
        $erreur = null;

        if ($request->isMethod('POST')) {
            $photo = null;
            if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string) $request->request->get('photo'), $m)) {
                $photo = base64_decode($m[1], true) ?: null;
            }
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } elseif ($photo === null) {
                $erreur = 'La photo est obligatoire sur la tablette.';
            } else {
                $resultat = $this->pointages->pointer($utilisateur, $point, $photo, null, null, $maintenant, tablette: true);
                if ($resultat['ok']) {
                    $this->addFlash('succes', \sprintf('%s · %s %s enregistrée.', Pointages::nom($utilisateur), $resultat['action'] === 'depart' ? 'départ' : 'arrivée', $maintenant->format('H:i')));

                    return $this->redirectToRoute('app_tablette_index');
                }
                $erreur = $resultat['message'] ?? 'Pointage impossible.';
            }
        }

        return $this->render('tablette/pointer.html.twig', [
            'point' => $point,
            'nom' => Pointages::nom($utilisateur),
            'utilisateur' => $utilisateur,
            'prochain' => $this->pointages->prochain($utilisateur, $maintenant),
            'maintenant' => $maintenant,
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }

    /** Point de pointage sur lequel cette tablette est branchée. */
    private function point(Request $request): ?PointPointage
    {
        $jeton = (string) $request->cookies->get(self::COOKIE, '');
        if (\strlen($jeton) < 32) {
            return null;
        }
        $point = $this->points->findOneBy(['tablette' => hash('sha256', $jeton)]);

        return $point !== null && $point->isActif() ? $point : null;
    }
}
