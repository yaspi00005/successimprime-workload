<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\User;
use App\Repository\BonLivraisonLigneRepository;
use App\Repository\BonLivraisonRepository;
use App\Repository\CommandesDetailsRepository;
use App\Entity\StockSorties;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/livraisons',
    name: 'app_livraisons_'
)]
final class LivraisonController extends AbstractController
{
    /*
     * ============================================================
     * LISTE DES LIVRAISONS
     * ============================================================
     *
     * Affiche :
     * - les lignes prêtes à livrer ;
     * - les lignes en livraison ;
     * - éventuellement les lignes déjà livrées.
     *
     * Filtres :
     * - recherche ;
     * - statut ;
     * - origine ;
     * - dates.
     * ============================================================
     */
    #[Route(
        '/',
        name: 'index',
        methods: ['GET']
    )]
    public function index(
        Request $request,
        CommandesDetailsRepository $commandesDetailsRepository
    ): Response {
        /*
         * --------------------------------------------------------
         * FILTRES
         * --------------------------------------------------------
         */
        $recherche = trim(
            (string) $request->query->get(
                'q',
                ''
            )
        );

        $statut = trim(
            (string) $request->query->get(
                'statut',
                ''
            )
        );

        $origine = trim(
            (string) $request->query->get(
                'origine',
                ''
            )
        );

        $dateDebut = trim(
            (string) $request->query->get(
                'date_debut',
                ''
            )
        );

        $dateFin = trim(
            (string) $request->query->get(
                'date_fin',
                ''
            )
        );

        $retrait = trim(
            (string) $request->query->get(
                'retrait',
                ''
            )
        );

        /*
         * --------------------------------------------------------
         * RECHERCHE ACTIVE ?
         * --------------------------------------------------------
         *
         * Il peut y avoir des centaines de commandes déjà livrées :
         * hors de tout critère de recherche, on ne les charge pas
         * (ni ne les affiche), pour garder la page rapide et
         * lisible. Elles restent consultables via la recherche.
         * --------------------------------------------------------
         */
        $rechercheActive =
            $recherche !== ''
            || $statut !== ''
            || $origine !== ''
            || $dateDebut !== ''
            || $dateFin !== ''
            || $retrait !== '';

        /*
         * --------------------------------------------------------
         * QUERY BUILDER
         * --------------------------------------------------------
         */
        $qb = $commandesDetailsRepository
            ->createQueryBuilder('detail');

        $statutsAffiches = $rechercheActive
            ? [
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                CommandesDetails::PRODUCTION_EN_LIVRAISON,
                CommandesDetails::PRODUCTION_LIVREE,
            ]
            : [
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                CommandesDetails::PRODUCTION_EN_LIVRAISON,
            ];

        $qb
            ->leftJoin(
                'detail.commande',
                'commande'
            )
            ->addSelect('commande')

            ->leftJoin(
                'detail.produit',
                'produit'
            )
            ->addSelect('produit')

            ->leftJoin(
                'detail.support',
                'support'
            )
            ->addSelect('support')

            ->leftJoin(
                'detail.machine',
                'machine'
            )
            ->addSelect('machine')

            ->andWhere(
                'detail.statutProduction IN (:statutsLivraison)'
            )
            ->setParameter(
                'statutsLivraison',
                $statutsAffiches
            );

        /*
         * --------------------------------------------------------
         * RECHERCHE TEXTE
         * --------------------------------------------------------
         */
        if ($recherche !== '') {
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(detail.designation) LIKE LOWER(:recherche)',
                        'LOWER(produit.nom) LIKE LOWER(:recherche)'
                    )
                )
                ->setParameter(
                    'recherche',
                    '%' . $recherche . '%'
                );
        }

        /*
         * --------------------------------------------------------
         * FILTRE STATUT
         * --------------------------------------------------------
         */
        $statutsAutorises = [
            CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
            CommandesDetails::PRODUCTION_EN_LIVRAISON,
            CommandesDetails::PRODUCTION_LIVREE,
        ];

        if (
            $statut !== ''
            && in_array(
                $statut,
                $statutsAutorises,
                true
            )
        ) {
            $qb
                ->andWhere(
                    'detail.statutProduction = :statut'
                )
                ->setParameter(
                    'statut',
                    $statut
                );
        }

        /*
         * --------------------------------------------------------
         * FILTRE ORIGINE
         * --------------------------------------------------------
         *
         * production :
         * productionNecessaire = true
         *
         * directe :
         * productionNecessaire = false
         * --------------------------------------------------------
         */
        if ($origine === 'production') {
            $qb->andWhere(
                'detail.productionNecessaire = true'
            );
        }

        if ($origine === 'directe') {
            $qb->andWhere(
                'detail.productionNecessaire = false'
            );
        }

        /*
         * --------------------------------------------------------
         * FILTRE DATE
         * --------------------------------------------------------
         *
         * Pour le moment, on utilise la date de fin de production.
         *
         * Plus tard, lorsqu'on ajoutera une vraie entité Livraison,
         * nous aurons :
         * - date préparation ;
         * - date départ ;
         * - date livraison.
         * --------------------------------------------------------
         */
        if ($dateDebut !== '') {
            try {
                $debut = new \DateTimeImmutable(
                    $dateDebut . ' 00:00:00'
                );

                $qb
                    ->andWhere(
                        'detail.productionTermineeLe >= :dateDebut'
                    )
                    ->setParameter(
                        'dateDebut',
                        $debut
                    );
            } catch (\Throwable) {
                // Date invalide ignorée.
            }
        }

        if ($dateFin !== '') {
            try {
                $fin = new \DateTimeImmutable(
                    $dateFin . ' 23:59:59'
                );

                $qb
                    ->andWhere(
                        'detail.productionTermineeLe <= :dateFin'
                    )
                    ->setParameter(
                        'dateFin',
                        $fin
                    );
            } catch (\Throwable) {
                // Date invalide ignorée.
            }
        }

        /*
         * --------------------------------------------------------
         * TRI
         * --------------------------------------------------------
         *
         * Les lignes encore à traiter doivent apparaître avant
         * l'historique livré.
         * --------------------------------------------------------
         */
        $qb
            ->addOrderBy(
                'CASE
                    WHEN detail.statutProduction = :prete THEN 1
                    WHEN detail.statutProduction = :enLivraison THEN 2
                    WHEN detail.statutProduction = :livree THEN 3
                    ELSE 4
                END',
                'ASC'
            )
            ->setParameter(
                'prete',
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON
            )
            ->setParameter(
                'enLivraison',
                CommandesDetails::PRODUCTION_EN_LIVRAISON
            )
            ->setParameter(
                'livree',
                CommandesDetails::PRODUCTION_LIVREE
            )
            ->addOrderBy(
                'detail.id',
                'DESC'
            );

        $livraisons = $qb
            ->getQuery()
            ->getResult();

        /*
         * --------------------------------------------------------
         * COMPTEURS
         * --------------------------------------------------------
         */
        $compteurs = [
            'prete' => 0,
            'en_livraison' => 0,
            'livree' => 0,
            'production' => 0,
            'directe' => 0,
        ];

        foreach ($livraisons as $detail) {
            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_PRETE_LIVRAISON
            ) {
                ++$compteurs['prete'];
            }

            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_EN_LIVRAISON
            ) {
                ++$compteurs['en_livraison'];
            }

            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_LIVREE
            ) {
                ++$compteurs['livree'];
            }

            if ($detail->isProductionNecessaire()) {
                ++$compteurs['production'];
            } else {
                ++$compteurs['directe'];
            }
        }

        /*
         * Le total livré est compté à part (requête légère, sans
         * charger les entités) : il reste exact même quand ces
         * lignes ne sont pas chargées dans $livraisons faute de
         * recherche active.
         */
        $compteurs['livree'] = (int) $commandesDetailsRepository
            ->createQueryBuilder('total')
            ->select('COUNT(total.id)')
            ->andWhere('total.statutProduction = :livree')
            ->setParameter('livree', CommandesDetails::PRODUCTION_LIVREE)
            ->getQuery()
            ->getSingleScalarResult();

        $groupes = $this->grouperParCommande($livraisons);

        /*
         * --------------------------------------------------------
         * FILTRE MODE DE RETRAIT
         * --------------------------------------------------------
         *
         * Propriété de la commande entière (pas d'une ligne) : le
         * filtre s'applique donc après le regroupement.
         * --------------------------------------------------------
         */
        if ($retrait === 'client' || $retrait === 'livreur') {
            $groupes = array_values(array_filter(
                $groupes,
                static function (array $groupe) use ($retrait): bool {
                    $recupereParClient = $groupe['commande']?->isRecupereParClient() ?? false;

                    return $retrait === 'client'
                        ? $recupereParClient
                        : !$recupereParClient;
                }
            ));
        }

        /*
         * --------------------------------------------------------
         * SÉPARATION LIVRÉES / NON LIVRÉES
         * --------------------------------------------------------
         */
        $groupesALivrer = array_values(array_filter(
            $groupes,
            static fn (array $groupe): bool => !$groupe['toutesLivrees']
        ));

        $groupesLivrees = array_values(array_filter(
            $groupes,
            static fn (array $groupe): bool => $groupe['toutesLivrees']
        ));

        return $this->render(
            'livraisons/index.html.twig',
            [
                'groupesALivrer' => $groupesALivrer,
                'groupesLivrees' => $groupesLivrees,
                'rechercheActive' => $rechercheActive,

                'compteurs' => $compteurs,

                'filtres' => [
                    'q' => $recherche,
                    'statut' => $statut,
                    'origine' => $origine,
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                    'retrait' => $retrait,
                ],
            ]
        );
    }

    /*
     * ============================================================
     * REGROUPEMENT DES LIGNES PAR COMMANDE
     * ============================================================
     *
     * Une commande peut avoir plusieurs lignes à livrer : les
     * afficher éparpillées dans une liste plate fait courir le
     * risque d'en oublier une. On les regroupe ici par commande,
     * en conservant l'ordre de tri déjà appliqué (prêtes puis en
     * livraison puis livrées, plus récentes d'abord).
     *
     * @param CommandesDetails[] $lignes
     *
     * @return array<int, array{
     *     commande: \App\Entity\Commandes|null,
     *     lignes: CommandesDetails[],
     *     nbLignes: int,
     *     nbLivrees: int,
     *     toutesLivrees: bool,
     *     aucuneLivree: bool,
     * }>
     */
    private function grouperParCommande(array $lignes): array
    {
        $groupes = [];

        foreach ($lignes as $detail) {
            $commande = $detail->getCommande();
            $cle = $commande?->getId() ?? 0;

            if (!isset($groupes[$cle])) {
                $groupes[$cle] = [
                    'commande' => $commande,
                    'lignes' => [],
                ];
            }

            $groupes[$cle]['lignes'][] = $detail;
        }

        foreach ($groupes as &$groupe) {
            $nbLignes = count($groupe['lignes']);

            $nbLivrees = count(array_filter(
                $groupe['lignes'],
                static fn (CommandesDetails $ligne): bool =>
                    $ligne->getStatutProduction() === CommandesDetails::PRODUCTION_LIVREE
            ));

            $groupe['nbLignes'] = $nbLignes;
            $groupe['nbLivrees'] = $nbLivrees;
            $groupe['toutesLivrees'] = $nbLivrees === $nbLignes;
            $groupe['aucuneLivree'] = $nbLivrees === 0;
        }
        unset($groupe);

        return array_values($groupes);
    }

    /*
     * ============================================================
     * FICHE D'UNE COMMANDE (TOUTES LES LIGNES + BONS DE LIVRAISON)
     * ============================================================
     *
     * Vue de consultation d'une livraison au niveau de la commande :
     * toutes les lignes livrables, avec leurs actions, et les bons
     * de livraison déjà créés pour cette commande.
     * ============================================================
     */
    #[Route(
        '/commande/{id}',
        name: 'commande',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function commande(
        Commandes $commande,
        CommandesDetailsRepository $commandesDetailsRepository,
        BonLivraisonRepository $bonLivraisonRepository
    ): Response {
        $lignes = $commandesDetailsRepository
            ->createQueryBuilder('detail')
            ->leftJoin('detail.produit', 'produit')
            ->addSelect('produit')
            ->leftJoin('detail.support', 'support')
            ->addSelect('support')
            ->leftJoin('detail.machine', 'machine')
            ->addSelect('machine')
            ->andWhere('detail.commande = :commande')
            ->andWhere(
                'detail.statutProduction IN (:statutsLivraison)'
            )
            ->setParameter('commande', $commande)
            ->setParameter(
                'statutsLivraison',
                [
                    CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                    CommandesDetails::PRODUCTION_EN_LIVRAISON,
                    CommandesDetails::PRODUCTION_LIVREE,
                ]
            )
            ->orderBy('detail.id', 'ASC')
            ->getQuery()
            ->getResult();

        $bons = $bonLivraisonRepository
            ->createQueryBuilder('bl')
            ->leftJoin('bl.creePar', 'creePar')
            ->addSelect('creePar')
            ->andWhere('bl.commande = :commande')
            ->setParameter('commande', $commande)
            ->orderBy('bl.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render(
            'livraisons/show_commande.html.twig',
            [
                'commande' => $commande,
                'lignes' => $lignes,
                'bons' => $bons,
            ]
        );
    }

    /*
     * ============================================================
     * FICHE D'UNE LIVRAISON
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
        CommandesDetails $detail
    ): Response {
        /*
         * On ne doit pas accéder à la vue Livraison
         * pour une ligne encore en production.
         */
        if (
            !in_array(
                $detail->getStatutProduction(),
                [
                    CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                    CommandesDetails::PRODUCTION_EN_LIVRAISON,
                    CommandesDetails::PRODUCTION_LIVREE,
                ],
                true
            )
        ) {
            throw $this->createNotFoundException(
                'Cette ligne de commande n’est pas disponible pour la livraison.'
            );
        }

        return $this->render(
            'livraisons/show.html.twig',
            [
                'detail' => $detail,
            ]
        );
    }

    /*
     * ============================================================
     * PASSER EN LIVRAISON
     * ============================================================
     */
    #[Route(
        '/{id}/demarrer',
        name: 'demarrer',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function demarrer(
        CommandesDetails $detail,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->verifierJeton(
            $request,
            'livraison_demarrer_' . $detail->getId()
        );

        try {
            /*
             * La méthode de l'entité vérifie déjà que
             * le statut est PRETE_LIVRAISON.
             */
            $detail->marquerEnLivraison();

            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'La livraison de « %s » a démarré.',
                    $detail->getDesignation()
                )
            );
        } catch (\LogicException $e) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'app_livraisons_show',
            [
                'id' => $detail->getId(),
            ]
        );
    }

    /*
     * ============================================================
     * DÉMARRER PLUSIEURS LIGNES D'UNE COMMANDE EN UNE FOIS
     * ============================================================
     *
     * Un seul formulaire/route pour deux usages :
     * - "Démarrer toutes les livraisons" (champ caché tout=1) ;
     * - "Démarrer la sélection" (cases à cocher lignes[]).
     * ============================================================
     */
    #[Route(
        '/commande/{id}/demarrer-masse',
        name: 'demarrer_masse',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function demarrerMasse(
        Commandes $commande,
        Request $request,
        CommandesDetailsRepository $commandesDetailsRepository,
        EntityManagerInterface $em
    ): Response {
        $this->verifierJeton(
            $request,
            'livraison_demarrer_masse_' . $commande->getId()
        );

        $qb = $commandesDetailsRepository
            ->createQueryBuilder('detail')
            ->andWhere('detail.commande = :commande')
            ->andWhere('detail.statutProduction = :statut')
            ->setParameter('commande', $commande)
            ->setParameter(
                'statut',
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON
            );

        if (!$request->request->getBoolean('tout')) {
            $ids = array_map(
                'intval',
                $request->request->all('lignes')
            );

            if ($ids === []) {
                $this->addFlash(
                    'error',
                    'Aucune ligne sélectionnée.'
                );

                return $this->redirectToRoute(
                    'app_livraisons_commande',
                    [
                        'id' => $commande->getId(),
                    ]
                );
            }

            $qb
                ->andWhere('detail.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        $lignes = $qb->getQuery()->getResult();

        $nombreDemarrees = 0;

        foreach ($lignes as $detail) {
            try {
                $detail->marquerEnLivraison();

                ++$nombreDemarrees;
            } catch (\LogicException) {
                continue;
            }
        }

        if ($nombreDemarrees > 0) {
            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    '%d livraison(s) démarrée(s).',
                    $nombreDemarrees
                )
            );
        } else {
            $this->addFlash(
                'error',
                'Aucune ligne n’a pu être démarrée.'
            );
        }

        return $this->redirectToRoute(
            'app_livraisons_commande',
            [
                'id' => $commande->getId(),
            ]
        );
    }

    /*
     * ============================================================
     * MARQUER PLUSIEURS LIGNES COMME LIVRÉES, EN UNE FOIS
     * ============================================================
     *
     * Le bon de livraison reste utile pour les clients (surtout les
     * entreprises) qui en ont besoin comme document, mais il ne doit
     * pas être une étape obligatoire pour livrer. La plupart des
     * commandes sont livrées d'un coup : ce bouton marque directement
     * les lignes comme livrées, sans passer par le circuit du bon.
     *
     * Une ligne déjà rattachée à un bon de livraison est ignorée ici
     * : elle doit être finalisée depuis son bon (pour ne pas compter
     * une sortie de stock deux fois).
     * ============================================================
     */
    #[Route(
        '/commande/{id}/livrer-masse',
        name: 'livrer_masse',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function livrerMasse(
        Commandes $commande,
        Request $request,
        CommandesDetailsRepository $commandesDetailsRepository,
        BonLivraisonLigneRepository $bonLivraisonLigneRepository,
        EntityManagerInterface $em,
        StockService $stockService
    ): Response {
        $this->verifierJeton(
            $request,
            'livraison_livrer_masse_' . $commande->getId()
        );

        $qb = $commandesDetailsRepository
            ->createQueryBuilder('detail')
            ->andWhere('detail.commande = :commande')
            ->andWhere('detail.statutProduction IN (:statuts)')
            ->setParameter('commande', $commande)
            ->setParameter(
                'statuts',
                [
                    CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                    CommandesDetails::PRODUCTION_EN_LIVRAISON,
                ]
            );

        if (!$request->request->getBoolean('tout')) {
            $ids = array_map(
                'intval',
                $request->request->all('lignes')
            );

            if ($ids === []) {
                $this->addFlash(
                    'error',
                    'Aucune ligne sélectionnée.'
                );

                return $this->redirectToRoute(
                    'app_livraisons_commande',
                    [
                        'id' => $commande->getId(),
                    ]
                );
            }

            $qb
                ->andWhere('detail.id IN (:ids)')
                ->setParameter('ids', $ids);
        }

        $lignes = $qb->getQuery()->getResult();

        $nombreLivrees = 0;
        $nombreIgnorees = 0;

        foreach ($lignes as $detail) {
            /*
             * Déjà rattachée à un bon de livraison :
             * on ne la touche pas ici.
             */
            $ligneBon = $bonLivraisonLigneRepository->findOneBy([
                'commandeDetail' => $detail,
            ]);

            if ($ligneBon !== null) {
                ++$nombreIgnorees;

                continue;
            }

            try {
                if (
                    $detail->getStatutProduction()
                    === CommandesDetails::PRODUCTION_PRETE_LIVRAISON
                ) {
                    $detail->marquerEnLivraison();
                }

                if (
                    $detail->getTypeLigne()
                    === CommandesDetails::TYPE_ARTICLE
                ) {
                    $article = $detail->getArticle();

                    $quantite = (float) $detail->getQuantite();

                    if ($article !== null && $quantite > 0) {
                        $stockService->consommerPourDetail(
                            $detail,
                            StockSorties::ORIGINE_LIVRAISON,
                            sprintf(
                                'LIV-DIRECT-%06d',
                                (int) $detail->getId()
                            ),
                            $quantite
                        );
                    }
                }

                $detail->marquerLivree();

                ++$nombreLivrees;
            } catch (
                \LogicException |
                \DomainException |
                \RuntimeException $e
            ) {
                continue;
            }
        }

        if ($nombreLivrees > 0) {
            if ($request->request->getBoolean('recupere_par_client')) {
                $commande->setRecupereParClient(true);
            }

            $em->flush();

            $message = sprintf(
                '%d ligne(s) marquée(s) comme livrée(s).',
                $nombreLivrees
            );

            if ($nombreIgnorees > 0) {
                $message .= sprintf(
                    ' %d ligne(s) rattachée(s) à un bon de livraison ont été ignorée(s) (à livrer depuis leur bon).',
                    $nombreIgnorees
                );
            }

            $this->addFlash('success', $message);
        } else {
            $this->addFlash(
                'error',
                'Aucune ligne n’a pu être marquée comme livrée.'
            );
        }

        return $this->redirectToRoute(
            'app_livraisons_commande',
            [
                'id' => $commande->getId(),
            ]
        );
    }

    /*
     * ============================================================
     * MARQUER COMME LIVRÉE
     * ============================================================
     */
   #[Route(
    '/{id}/livrer',
    name: 'livrer',
    requirements: [
        'id' => '\d+',
    ],
    methods: ['POST']
)]
public function livrer(
    CommandesDetails $detail,
    Request $request,
    EntityManagerInterface $em,
    StockService $stockService
): Response {
    $this->verifierJeton(
        $request,
        'livraison_livrer_' . $detail->getId()
    );

    try {
        /*
         * La ligne doit déjà être en livraison.
         */
        if (
            $detail->getStatutProduction()
            !== CommandesDetails::PRODUCTION_EN_LIVRAISON
        ) {
            throw new \LogicException(
                'La ligne doit être en livraison avant d’être confirmée.'
            );
        }


        /*
         * ========================================================
         * VENTE DIRECTE D'ARTICLE
         * ========================================================
         */
        if (
            $detail->getTypeLigne()
            === CommandesDetails::TYPE_ARTICLE
        ) {
            $article =
                $detail->getArticle();

            if ($article === null) {
                throw new \LogicException(
                    'Aucun article en stock n’est associé à cette ligne.'
                );
            }


            $quantite =
                (float) $detail->getQuantite();

            if ($quantite <= 0) {
                throw new \LogicException(
                    'La quantité à livrer est invalide.'
                );
            }


            /*
             * Référence stable pour éviter
             * une double sortie de stock.
             */
            $reference =
                sprintf(
                    'LIV-DIRECT-%06d',
                    (int) $detail->getId()
                );


            /*
             * Sortie de stock.
             */
            $stockService->consommerPourDetail(
                $detail,
                StockSorties::ORIGINE_LIVRAISON,
                $reference,
                $quantite
            );


            /*
             * Passage au statut livré.
             */
            $detail->marquerLivree();


            $em->flush();


            $this->addFlash(
                'success',
                sprintf(
                    '« %s » a été livré. La sortie de stock a été enregistrée.',
                    $detail->getDesignation()
                )
            );


            return $this->redirectToRoute(
                'app_livraisons_show',
                [
                    'id' => $detail->getId(),
                ]
            );
        }


        /*
         * ========================================================
         * AUTRES TYPES
         * ========================================================
         */
        throw new \LogicException(
            sprintf(
                'La ligne « %s » doit être livrée à partir d’un bon de livraison.',
                $detail->getDesignation()
            )
        );

    } catch (
        \LogicException |
        \RuntimeException |
        \DomainException $e
    ) {
        $this->addFlash(
            'error',
            $e->getMessage()
        );
    }


    return $this->redirectToRoute(
        'app_livraisons_show',
        [
            'id' => $detail->getId(),
        ]
    );
}

    /*
     * ============================================================
     * LIVRAISON DIRECTE
     * ============================================================
     *
     * Cette route peut être utilisée après validation d'une
     * commande pour un consommable/support qui ne nécessite
     * aucune production.
     * ============================================================
     */
    #[Route(
        '/{id}/preparer-directement',
        name: 'preparer_directement',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function preparerDirectement(
        CommandesDetails $detail,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->verifierJeton(
            $request,
            'livraison_directe_' . $detail->getId()
        );

        try {
            if ($detail->isProductionNecessaire()) {
                throw new \LogicException(
                    'Cette ligne nécessite une production et ne peut pas être envoyée directement en livraison.'
                );
            }

            /*
             * Une ligne fraîchement créée (vente directe ou saisie
             * libre) reste au statut par défaut "a_produire" tant
             * que personne ne l'a explicitement basculée : on le
             * fait ici avant de la préparer pour la livraison.
             */
            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_A_PRODUIRE
            ) {
                $detail->marquerProductionNonRequise();
            }

            $detail->marquerPreteLivraison();

            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    '« %s » est prêt pour la livraison.',
                    $detail->getDesignation()
                )
            );
        } catch (\LogicException $e) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'app_livraisons_index'
        );
    }

    /*
     * ============================================================
     * LIVRAISON DIRECTE EN UN CLIC
     * ============================================================
     *
     * Pour une ligne de vente directe (article en stock ou saisie
     * libre, sans fabrication), enchaîne en une seule action tout
     * le circuit (préparation, mise en livraison, sortie de stock
     * et livraison), pour éviter de faire naviguer l'utilisateur
     * entre plusieurs écrans pour un cas aussi simple.
     * ============================================================
     */
    #[Route(
        '/{id}/livrer-directement',
        name: 'livrer_directement',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function livrerDirectement(
        CommandesDetails $detail,
        Request $request,
        EntityManagerInterface $em,
        StockService $stockService
    ): Response {
        $this->verifierJeton(
            $request,
            'livraison_direct_' . $detail->getId()
        );

        try {
            /*
             * Bouton de secours : permet de fermer n'importe quelle
             * ligne (avec ou sans fabrication, avec ou sans contrôle
             * prépresse) quand elle a déjà été traitée/remise au
             * client en dehors du logiciel. On force donc le passage
             * à "non requise" si la production n'a pas encore
             * démarré, quel que soit le type de ligne.
             */
            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_A_PRODUIRE
            ) {
                $detail->marquerProductionNonRequise();
            }

            /*
             * Production déjà terminée (fabrication réellement
             * effectuée) : manquait à cette liste, ce qui bloquait
             * la livraison directe des lignes fabriquées avec
             * "Cette ligne n'est pas dans un état permettant une
             * livraison directe." alors que la production était
             * bien achevée.
             */
            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_TERMINEE
            ) {
                $detail->marquerPreteLivraison();
            }

            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_NON_REQUISE
            ) {
                $detail->marquerPreteLivraison();
            }

            if (
                $detail->getStatutProduction()
                === CommandesDetails::PRODUCTION_PRETE_LIVRAISON
            ) {
                $detail->marquerEnLivraison();
            }

            if (
                $detail->getStatutProduction()
                !== CommandesDetails::PRODUCTION_EN_LIVRAISON
            ) {
                throw new \LogicException(
                    'Cette ligne n’est pas dans un état permettant une livraison directe.'
                );
            }

            /*
             * Sortie de stock quel que soit le type de ligne :
             * calculerBesoinsDetail() sait determiner s'il y a
             * reellement une reservation a consommer (article de
             * vente directe, ou produit dont la gestion de stock est
             * activee) et ne fait rien sinon.
             */
            $quantite = (float) $detail->getQuantite();

            if ($quantite > 0) {
                $stockService->consommerPourDetail(
                    $detail,
                    StockSorties::ORIGINE_LIVRAISON,
                    sprintf(
                        'LIV-DIRECT-%06d',
                        (int) $detail->getId()
                    ),
                    $quantite
                );
            }

            $detail->marquerLivree();

            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    '« %s » a été marqué comme livré.',
                    $detail->getDesignation()
                )
            );
        } catch (\LogicException $e) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'app_commandes_show',
            [
                'id' => $detail->getCommande()?->getId(),
            ]
        );
    }

    /*
     * ============================================================
     * UTILISATEUR CONNECTÉ
     * ============================================================
     *
     * On l'utilisera ensuite lorsque nous ajouterons à Livraison :
     * - préparé par ;
     * - livré par ;
     * - réceptionné par ;
     * - dates ;
     * - signature.
     * ============================================================
     */
    private function utilisateurConnecte(): User
    {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException(
                'Utilisateur non authentifié.'
            );
        }

        return $utilisateur;
    }

    /*
     * ============================================================
     * VÉRIFICATION CSRF
     * ============================================================
     */
    private function verifierJeton(
        Request $request,
        string $identifiant
    ): void {
        $token = (string) $request->request->get(
            '_token',
            ''
        );

        if (
            !$this->isCsrfTokenValid(
                $identifiant,
                $token
            )
        ) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }
    }
}