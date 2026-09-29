<?php

namespace App\Controller;

use App\Entity\Articles;
use App\Form\ArticlesType;
use App\Repository\ArticlesRepository;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\StockEntrees;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;


#[Route(
    '/articles',
    name: 'app_article_'
)]
final class ArticlesController extends AbstractController
{
    #[Route(
        '/',
        name: 'index',
        methods: ['GET']
    )]
    public function index(
        Request $request,
        ArticlesRepository $repository,
        StockService $stockService
    ): Response {
        $recherche =
            trim(
                (string)
                $request->query->get(
                    'q',
                    ''
                )
            );

        $categorie =
            trim(
                (string)
                $request->query->get(
                    'categorie',
                    ''
                )
            );

        $qb =
            $repository
            ->createQueryBuilder('a');

        if ($recherche !== '') {
            $qb
                ->andWhere(
                    'LOWER(a.reference) LIKE LOWER(:q)
                    OR LOWER(a.designation) LIKE LOWER(:q)'
                )
                ->setParameter(
                    'q',
                    '%' . $recherche . '%'
                );
        }

        if ($categorie !== '') {
            $qb
                ->andWhere(
                    'a.categorie = :categorie'
                )
                ->setParameter(
                    'categorie',
                    $categorie
                );
        }

        $articles =
            $qb
            ->orderBy(
                'a.designation',
                'ASC'
            )
            ->getQuery()
            ->getResult();

        $lignes = [];

        foreach (
            $articles
            as $article
        ) {
            if (
                !$article
                    instanceof Articles
            ) {
                continue;
            }

            $physique =
                $stockService
                ->getStockPhysique(
                    $article
                );

            $reserve =
                $stockService
                ->getStockReserve(
                    $article
                );

            $disponible =
                $stockService
                ->getStockDisponible(
                    $article
                );

            $seuil =
                $article
                ->getStockMin();

            if ($disponible <= 0) {
                $etat = 'rupture';
            } elseif (
                $seuil > 0
                && $disponible <= $seuil
            ) {
                $etat = 'alerte';
            } else {
                $etat = 'ok';
            }

            $lignes[] = [
                'article' =>
                $article,

                'physique' =>
                $physique,

                'reserve' =>
                $reserve,

                'disponible' =>
                $disponible,

                'etat' =>
                $etat,
            ];
        }

        return $this->render(
            'articles/index.html.twig',
            [
                'lignes' =>
                $lignes,

                'q' =>
                $recherche,

                'categorie' =>
                $categorie,
            ]
        );
    }


    #[Route(
        '/nouveau',
        name: 'new',
        methods: ['GET', 'POST']
    )]
    public function new(
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $article =
            new Articles();

        $form =
            $this->createForm(
                ArticlesType::class,
                $article
            );

        $form->handleRequest(
            $request
        );

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $em->persist(
                $article
            );

            $em->flush();

            $this->addFlash(
                'success',
                'L’article a été créé avec succès.'
            );

            return $this->redirectToRoute(
                'app_article_index'
            );
        }

        return $this->render(
            'articles/form.html.twig',
            [
                'article' =>
                $article,

                'form' =>
                $form,

                'titre' =>
                'Nouvel article',

                'bouton_label' =>
                'Créer l’article',
            ]
        );
    }


    #[Route(
        '/{id}/modifier',
        name: 'edit',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET', 'POST']
    )]
    public function edit(
        Articles $article,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $form =
            $this->createForm(
                ArticlesType::class,
                $article
            );

        $form->handleRequest(
            $request
        );

        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            $em->flush();

            $this->addFlash(
                'success',
                'L’article a été modifié avec succès.'
            );

            return $this->redirectToRoute(
                'app_article_index'
            );
        }

        return $this->render(
            'articles/form.html.twig',
            [
                'article' =>
                $article,

                'form' =>
                $form,

                'titre' =>
                'Modifier l’article',

                'bouton_label' =>
                'Enregistrer',
            ]
        );
    }


    #[Route(
        '/{id}',
        name: 'show',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function show(
        Articles $article,
        StockService $stockService
    ): Response {
        return $this->render(
            'articles/show.html.twig',
            [
                'article' =>
                $article,

                'stockPhysique' =>
                $stockService
                    ->getStockPhysique(
                        $article
                    ),

                'stockReserve' =>
                $stockService
                    ->getStockReserve(
                        $article
                    ),

                'stockDisponible' =>
                $stockService
                    ->getStockDisponible(
                        $article
                    ),
            ]
        );
    }
    #[Route(
        '/{id}/entree-stock',
        name: 'entree_stock',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function entreeStock(
        Articles $article,
        Request $request,
        EntityManagerInterface $em
    ): RedirectResponse {
        if (
            !$this->isCsrfTokenValid(
                'entree_stock_' . $article->getId(),
                (string) $request->request->get(
                    '_token'
                )
            )
        ) {
            throw $this->createAccessDeniedException(
                'Jeton CSRF invalide.'
            );
        }

        $quantite = (float) str_replace(
            ',',
            '.',
            (string) $request
                ->request
                ->get(
                    'quantite',
                    '0'
                )
        );

        $prix = (int) $request
            ->request
            ->get(
                'prix',
                0
            );

        $dateSaisie = trim(
            (string) $request
                ->request
                ->get(
                    'date',
                    ''
                )
        );

        if ($quantite <= 0) {
            $this->addFlash(
                'error',
                'La quantité entrée doit être supérieure à zéro.'
            );

            return $this->redirectToRoute(
                'app_article_show',
                [
                    'id' => $article->getId(),
                ]
            );
        }

        if ($prix < 0) {
            $this->addFlash(
                'error',
                'Le prix d’achat ne peut pas être négatif.'
            );

            return $this->redirectToRoute(
                'app_article_show',
                [
                    'id' => $article->getId(),
                ]
            );
        }

        $date = new \DateTimeImmutable();

        if ($dateSaisie !== '') {
            $dateParse =
                \DateTimeImmutable::createFromFormat(
                    'Y-m-d\TH:i',
                    $dateSaisie
                );

            if (
                !$dateParse
                    instanceof \DateTimeImmutable
            ) {
                $this->addFlash(
                    'error',
                    'La date de l’entrée de stock est invalide.'
                );

                return $this->redirectToRoute(
                    'app_article_show',
                    [
                        'id' => $article->getId(),
                    ]
                );
            }

            $date = $dateParse;
        }

        $entree = new StockEntrees();

        $entree->setArticle(
            $article
        );

        /*
     * Ton StockService utilise actuellement
     * StockEntrees.quantites.
     */
        $entree->setQuantites(
            $quantite
        );

        /*
     * Ces deux champs existent déjà dans les vues
     * d'état de stock que nous avons préparées.
     */
        $entree->setPrix(
            $prix
        );

        $entree->setDate(
            $date
        );

        $em->persist(
            $entree
        );

        $em->flush();

        /*
     * Met à jour le prix indicatif d'achat de l'article.
     */
        if ($prix > 0) {
            $article->setPrixAchat(
                $prix
            );

            $em->flush();
        }

        $this->addFlash(
            'success',
            sprintf(
                'Entrée de stock enregistrée : %s %s pour « %s ».',
                number_format(
                    $quantite,
                    3,
                    ',',
                    ' '
                ),
                $article->getUnite(),
                $article->getDesignation()
            )
        );

        return $this->redirectToRoute(
            'app_article_show',
            [
                'id' => $article->getId(),
            ]
        );
    }
    #[Route(
        '/{id}/commande/ajax',
        name: 'commande_ajax',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function commandeAjax(
        Articles $article,
        StockService $stockService
    ): JsonResponse {
        if (!$article->isActif()) {
            return $this->json(
                [
                    'success' => false,
                    'message' => 'Cet article est désactivé.',
                ],
                404
            );
        }

        if (
            method_exists($article, 'isVendable')
            && !$article->isVendable()
        ) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                    'Cet article ne peut pas être vendu directement.',
                ],
                403
            );
        }

        $stockPhysique =
            $stockService->getStockPhysique(
                $article
            );

        $stockReserve =
            $stockService->getStockReserve(
                $article
            );

        $stockDisponible =
            $stockService->getStockDisponible(
                $article
            );

        return $this->json([
            'success' => true,

            'article' => [
                'id' =>
                $article->getId(),

                'reference' =>
                $article->getReference(),

                'designation' =>
                $article->getDesignation(),

                'unite' =>
                $article->getUnite(),

                'prixVente' =>
                $article->getPrixVente() ?? 0,

                'stockPhysique' =>
                round($stockPhysique, 3),

                'stockReserve' =>
                round($stockReserve, 3),

                'stockDisponible' =>
                round($stockDisponible, 3),

                'seuilAlerte' =>
                method_exists(
                    $article,
                    'getSeuilAlerte'
                )
                    ? $article->getSeuilAlerte()
                    : $article->getStockMin(),
            ],
        ]);
    }
}
