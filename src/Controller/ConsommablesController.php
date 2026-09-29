<?php

namespace App\Controller;

use App\Entity\Articles;
use App\Entity\StockSorties;
use App\Repository\ArticlesRepository;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Consommables de production (colle, encre, film...) : contrairement
 * à la nomenclature automatique d'un produit, un consommable manuel
 * n'est pas rattaché à un ordre de production précis. C'est un écran
 * indépendant, accessible depuis le menu, qui retire directement du
 * stock disponible.
 */
#[Route('/consommables', name: 'app_consommables_')]
final class ConsommablesController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        EntityManagerInterface $em,
        ArticlesRepository $articlesRepository
    ): Response {
        $consommables = $em->getRepository(StockSorties::class)->findBy(
            ['origine' => StockSorties::ORIGINE_MANUELLE],
            ['date' => 'DESC']
        );

        return $this->render('consommables/index.html.twig', [
            'consommables' => $consommables,
            'articlesConsommables' => $articlesRepository->findConsommables(),
        ]);
    }

    #[Route('/ajouter', name: 'ajouter', methods: ['POST'])]
    public function ajouter(
        Request $request,
        ArticlesRepository $articlesRepository,
        StockService $stockService
    ): Response {
        $this->verifierJeton($request, 'consommables_ajouter');

        try {
            $articleId = $request->request->getInt('article_id');
            $article = $articlesRepository->find($articleId);

            if (!$article instanceof Articles) {
                throw new \InvalidArgumentException(
                    'Veuillez sélectionner un article.'
                );
            }

            $quantite = $request->request->getInt('quantite');

            $reference = trim((string) $request->request->get('reference'));

            $stockService->enregistrerConsommableManuel(
                null,
                $article,
                $quantite,
                $reference !== '' ? $reference : null
            );

            $this->addFlash(
                'success',
                sprintf(
                    '%s retiré du stock (%d).',
                    (string) $article,
                    $quantite
                )
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_consommables_index');
    }

    #[Route('/{sortie}/supprimer', name: 'supprimer', requirements: ['sortie' => '\d+'], methods: ['POST'])]
    public function supprimer(
        StockSorties $sortie,
        Request $request,
        StockService $stockService
    ): Response {
        $this->verifierJeton($request, 'consommables_supprimer_' . $sortie->getId());

        try {
            if ($sortie->getOrigine() !== StockSorties::ORIGINE_MANUELLE) {
                throw new \LogicException(
                    'Cette sortie de stock n’est pas un consommable manuel.'
                );
            }

            $stockService->supprimerConsommableManuel($sortie);

            $this->addFlash(
                'success',
                'Le consommable a été retiré et le stock recrédité.'
            );
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_consommables_index');
    }

    private function verifierJeton(Request $request, string $id): void
    {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid($id, $token)) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }
    }
}
