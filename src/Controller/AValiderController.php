<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\OperationsAValider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › À valider (maquette 29) : le responsable vérifie les
 * opérations provisoires et les valide ou les rejette par lot.
 */
#[Route('/finances/a-valider', name: 'app_a_valider_')]
#[IsGranted('ROLE_ADMIN')]
final class AValiderController extends AbstractController
{
    public function __construct(private readonly OperationsAValider $operations)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('a_valider/index.html.twig', [
            'operations' => $this->operations->enAttente(new \DateTimeImmutable()),
            'validees' => $this->operations->valideesRecemment(),
        ]);
    }

    #[Route('/traiter', name: 'traiter', methods: ['POST'])]
    public function traiter(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('a-valider', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $responsable = $this->getUser();
        if (!$responsable instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $valider = 'valider' === $request->request->get('decision');
        $motif = trim((string) $request->request->get('motif'));
        $cles = array_values(array_filter((array) $request->request->all('operations'), 'is_string'));
        if ([] === $cles) {
            $this->addFlash('warning', 'Cochez au moins une opération.');

            return $this->redirectToRoute('app_a_valider_index');
        }
        if (!$valider && '' === $motif) {
            $this->addFlash('warning', 'Le motif du rejet est obligatoire.');

            return $this->redirectToRoute('app_a_valider_index');
        }

        $comptes = (array) $request->request->all('compte');
        $faites = 0;
        foreach ($cles as $cle) {
            try {
                $compte = $comptes[$cle] ?? '';
                $this->operations->traiter($cle, $valider, $motif, $responsable, is_numeric($compte) ? (int) $compte : null);
                ++$faites;
            } catch (\InvalidArgumentException|\LogicException|\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }
        if ($faites > 0) {
            $this->addFlash('success', sprintf('%d opération%s %s.', $faites, $faites > 1 ? 's' : '', $valider ? ($faites > 1 ? 'validées' : 'validée') : ($faites > 1 ? 'rejetées' : 'rejetée')));
        }

        return $this->redirectToRoute('app_a_valider_index');
    }
}
