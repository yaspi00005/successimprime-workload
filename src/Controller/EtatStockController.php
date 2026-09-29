<?php

namespace App\Controller;

use App\Entity\Articles;
use App\Entity\StockEntrees;
use App\Entity\StockReservation;
use App\Entity\StockSorties;
use App\Repository\ArticlesRepository;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/stock/etat',
    name: 'app_stock_etat_'
)]
final class EtatStockController extends AbstractController
{
    /*
     * ============================================================
     * ÉTAT GLOBAL DU STOCK
     * ============================================================
     */
    #[Route(
        '/',
        name: 'index',
        methods: ['GET']
    )]
    public function index(
        Request $request,
        ArticlesRepository $articlesRepository,
        StockService $stockService
    ): Response {
        $recherche = trim(
            (string) $request->query->get(
                'q',
                ''
            )
        );

        $etat = trim(
            (string) $request->query->get(
                'etat',
                ''
            )
        );

        /*
         * ========================================================
         * ARTICLES
         * ========================================================
         */
        $qb = $articlesRepository
            ->createQueryBuilder('article');

        if ($recherche !== '') {
            $qb
                ->andWhere(
                    'LOWER(article.designation) LIKE LOWER(:recherche)'
                )
                ->setParameter(
                    'recherche',
                    '%' . $recherche . '%'
                );
        }

        $articles = $qb
            ->orderBy(
                'article.designation',
                'ASC'
            )
            ->getQuery()
            ->getResult();

        /*
         * ========================================================
         * CALCUL DES ÉTATS
         * ========================================================
         */
        $lignes = [];

        $statistiques = [
            'articles' => 0,
            'rupture' => 0,
            'alerte' => 0,
            'disponible' => 0,
            'stockPhysique' => 0.0,
            'stockReserve' => 0.0,
            'stockDisponible' => 0.0,
        ];

        foreach ($articles as $article) {
            if (!$article instanceof Articles) {
                continue;
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

            /*
             * Seuil facultatif.
             *
             * La page reste compatible même si Articles
             * ne possède pas encore getSeuilAlerte().
             */
            $seuilAlerte = null;

            if (
                method_exists(
                    $article,
                    'getSeuilAlerte'
                )
            ) {
                $valeurSeuil =
                    $article->getSeuilAlerte();

                if (
                    $valeurSeuil !== null
                    && is_numeric($valeurSeuil)
                ) {
                    $seuilAlerte =
                        max(
                            0,
                            (float) $valeurSeuil
                        );
                }
            }

            /*
             * ====================================================
             * DÉTERMINATION DE L'ÉTAT
             * ====================================================
             */
            if ($stockDisponible <= 0) {
                $etatArticle = 'rupture';
            } elseif (
                $seuilAlerte !== null
                && $stockDisponible <= $seuilAlerte
            ) {
                $etatArticle = 'alerte';
            } else {
                $etatArticle = 'disponible';
            }

            /*
             * Filtre d'état.
             */
            if (
                $etat !== ''
                && in_array(
                    $etat,
                    [
                        'rupture',
                        'alerte',
                        'disponible',
                    ],
                    true
                )
                && $etatArticle !== $etat
            ) {
                continue;
            }

            $lignes[] = [
                'article' =>
                    $article,

                'stockPhysique' =>
                    $stockPhysique,

                'stockReserve' =>
                    $stockReserve,

                'stockDisponible' =>
                    $stockDisponible,

                'seuilAlerte' =>
                    $seuilAlerte,

                'etat' =>
                    $etatArticle,
            ];

            ++$statistiques['articles'];

            ++$statistiques[
                $etatArticle
            ];

            $statistiques[
                'stockPhysique'
            ] += $stockPhysique;

            $statistiques[
                'stockReserve'
            ] += $stockReserve;

            $statistiques[
                'stockDisponible'
            ] += $stockDisponible;
        }

        return $this->render(
            'stock/etat/index.html.twig',
            [
                'lignes' =>
                    $lignes,

                'statistiques' =>
                    $statistiques,

                'filtres' => [
                    'q' =>
                        $recherche,

                    'etat' =>
                        $etat,
                ],
            ]
        );
    }


    /*
     * ============================================================
     * FICHE STOCK D'UN ARTICLE
     * ============================================================
     */
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
        StockService $stockService,
        EntityManagerInterface $em
    ): Response {
        /*
         * ========================================================
         * SOLDES
         * ========================================================
         */
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

        /*
         * ========================================================
         * ENTRÉES
         * ========================================================
         */
        $entrees = $em
            ->getRepository(
                StockEntrees::class
            )
            ->createQueryBuilder('e')
            ->andWhere(
                'e.article = :article'
            )
            ->setParameter(
                'article',
                $article
            )
            ->orderBy(
                'e.date',
                'DESC'
            )
            ->addOrderBy(
                'e.id',
                'DESC'
            )
            ->getQuery()
            ->getResult();

        /*
         * ========================================================
         * SORTIES
         * ========================================================
         */
        $sorties = $em
            ->getRepository(
                StockSorties::class
            )
            ->createQueryBuilder('s')
            ->leftJoin(
                's.commandeDetail',
                'detail'
            )
            ->addSelect(
                'detail'
            )
            ->andWhere(
                's.article = :article'
            )
            ->setParameter(
                'article',
                $article
            )
            ->orderBy(
                's.date',
                'DESC'
            )
            ->addOrderBy(
                's.id',
                'DESC'
            )
            ->getQuery()
            ->getResult();

        /*
         * ========================================================
         * RÉSERVATIONS
         * ========================================================
         */
        $reservations = $em
            ->getRepository(
                StockReservation::class
            )
            ->createQueryBuilder('r')
            ->leftJoin(
                'r.commandeDetail',
                'detail'
            )
            ->addSelect(
                'detail'
            )
            ->leftJoin(
                'detail.commande',
                'commande'
            )
            ->addSelect(
                'commande'
            )
            ->andWhere(
                'r.article = :article'
            )
            ->setParameter(
                'article',
                $article
            )
            ->orderBy(
                'r.dateReservation',
                'DESC'
            )
            ->addOrderBy(
                'r.id',
                'DESC'
            )
            ->getQuery()
            ->getResult();

        /*
         * ========================================================
         * SEUIL
         * ========================================================
         */
        $seuilAlerte = null;

        if (
            method_exists(
                $article,
                'getSeuilAlerte'
            )
        ) {
            $seuilAlerte =
                $article->getSeuilAlerte();
        }

        return $this->render(
            'stock/etat/show.html.twig',
            [
                'article' =>
                    $article,

                'stockPhysique' =>
                    $stockPhysique,

                'stockReserve' =>
                    $stockReserve,

                'stockDisponible' =>
                    $stockDisponible,

                'seuilAlerte' =>
                    $seuilAlerte,

                'entrees' =>
                    $entrees,

                'sorties' =>
                    $sorties,

                'reservations' =>
                    $reservations,
            ]
        );
    }
}