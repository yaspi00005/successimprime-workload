<?php

namespace App\Controller;

use App\Service\Sauvegarde\HorsSite;
use App\Service\Sauvegarde\Sauvegardes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Sécurité › Sauvegardes : état des sauvegardes automatiques.
 *
 * L'écran montre l'état et permet une sauvegarde de la base à la demande,
 * mais ne propose ni téléchargement (les fichiers contiennent toute la
 * base, salaires et clients compris) ni restauration : celle-ci se fait
 * en ligne de commande sur la machine (docs/sauvegardes.md).
 */
#[Route('/reglages/securite/sauvegardes', name: 'app_sauvegardes_')]
#[IsGranted('ROLE_ADMIN')]
final class SauvegardesController extends AbstractController
{
    public function __construct(private readonly Sauvegardes $sauvegardes, private readonly HorsSite $horsSite)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('sauvegardes/index.html.twig', [
            'etat' => $this->sauvegardes->etat(new \DateTimeImmutable()),
            'hors_site' => $this->horsSite->etat(new \DateTimeImmutable()),
            'liste' => \array_slice($this->sauvegardes->liste(), 0, 30),
            'echecs' => \array_slice(array_values(array_filter($this->sauvegardes->journal(), static fn (array $j): bool => $j['statut'] === 'echec')), 0, 5),
        ]);
    }

    /** La base seule : les fichiers peuvent peser plusieurs Go, ils restent à la tâche de nuit. */
    #[Route('/maintenant', name: 'maintenant', methods: ['POST'])]
    public function maintenant(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('sauvegarde', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        set_time_limit(600);
        try {
            $m = $this->sauvegardes->lancer(false, 'manuel', $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', 'Base sauvegardée ('.Sauvegardes::taille($m['base']['octets']).').'.($m['copie'] !== null && $m['copie'] !== 'ok' ? ' La copie sur le second disque a échoué : '.$m['copie'] : ''));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'La sauvegarde a échoué : '.$e->getMessage());
        }

        return $this->redirectToRoute('app_sauvegardes_index');
    }
}
