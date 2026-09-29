<?php

namespace App\Service;

use App\Entity\Articles;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ProduitArticleStock;
use App\Entity\StockEntrees;
use App\Entity\StockReservation;
use App\Entity\StockSorties;
use Doctrine\ORM\EntityManagerInterface;

final class StockService
{
    /**
     * Rôles prévenus quand un article passe sous son seuil d'alerte
     * après une sortie de stock (production ou saisie manuelle).
     */
    private const ROLES_ALERTE_STOCK_BAS = ['ROLE_ADMIN', 'ROLE_RESPONSABLE_GESTION'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Prévient les responsables si l'article vient de passer sous son
     * seuil d'alerte suite à une sortie de stock. N'appelle pas
     * flush() : fait partie de la même transaction que l'appelant.
     */
    private function verifierSeuilAlerte(
        Articles $article,
        float $stockAvant,
        float $quantiteSortie
    ): void {
        $stockApres = max(0.0, $stockAvant - $quantiteSortie);
        $seuil = $article->getSeuilAlerte();

        if ($stockApres > $seuil || $stockAvant <= $seuil) {
            /*
             * On ne notifie qu'au moment où le seuil est franchi
             * (pas à chaque sortie tant qu'on reste en dessous, pour
             * éviter le spam de notifications).
             */
            return;
        }

        $this->notificationService->notifierRoles(
            self::ROLES_ALERTE_STOCK_BAS,
            sprintf(
                'Stock bas : %s — reste %s (seuil %s).',
                $this->getDesignationArticle($article),
                $this->formaterQuantite($stockApres),
                $this->formaterQuantite($seuil)
            ),
            'app_stock_etat_index'
        );
    }

    /*
     * ============================================================
     * STOCK PHYSIQUE
     * ============================================================
     */

    public function getStockPhysique(
        Articles $article
    ): float {
        $entrees = $this->entityManager
            ->getRepository(StockEntrees::class)
            ->createQueryBuilder('e')
            ->select(
                'COALESCE(SUM(e.quantites), 0)'
            )
            ->andWhere(
                'e.article = :article'
            )
            ->setParameter(
                'article',
                $article
            )
            ->getQuery()
            ->getSingleScalarResult();

        $sorties = $this->entityManager
            ->getRepository(StockSorties::class)
            ->createQueryBuilder('s')
            ->select(
                'COALESCE(SUM(s.quantite), 0)'
            )
            ->andWhere(
                's.article = :article'
            )
            ->setParameter(
                'article',
                $article
            )
            ->getQuery()
            ->getSingleScalarResult();

        return max(
            0,
            (float) $entrees
                - (float) $sorties
        );
    }


    /*
     * ============================================================
     * STOCK RÉSERVÉ
     * ============================================================
     */

    public function getStockReserve(
        Articles $article
    ): float {
        $resultat = $this->entityManager
            ->getRepository(
                StockReservation::class
            )
            ->createQueryBuilder('r')
            ->select(
                'COALESCE(SUM(r.quantite), 0)'
            )
            ->andWhere(
                'r.article = :article'
            )
            ->andWhere(
                'r.statut = :statut'
            )
            ->setParameter(
                'article',
                $article
            )
            ->setParameter(
                'statut',
                StockReservation::STATUT_ACTIVE
            )
            ->getQuery()
            ->getSingleScalarResult();

        return max(
            0,
            (float) $resultat
        );
    }


    /*
     * ============================================================
     * STOCK DISPONIBLE GÉNÉRAL
     * ============================================================
     */

    public function getStockDisponible(
        Articles $article
    ): float {
        return max(
            0,
            $this->getStockPhysique(
                $article
            )
                - $this->getStockReserve(
                    $article
                )
        );
    }


    /*
     * ============================================================
     * RÉSERVÉ PAR LES AUTRES COMMANDES
     * ============================================================
     */

    private function getStockReserveHorsCommande(
        Articles $article,
        ?Commandes $commande = null
    ): float {
        $qb = $this->entityManager
            ->getRepository(
                StockReservation::class
            )
            ->createQueryBuilder('r')
            ->select(
                'COALESCE(SUM(r.quantite), 0)'
            )
            ->innerJoin(
                'r.commandeDetail',
                'detail'
            )
            ->andWhere(
                'r.article = :article'
            )
            ->andWhere(
                'r.statut = :statut'
            )
            ->setParameter(
                'article',
                $article
            )
            ->setParameter(
                'statut',
                StockReservation::STATUT_ACTIVE
            );

        /*
         * En modification :
         * on ignore les réservations appartenant
         * à la commande actuelle.
         */
        if (
            $commande !== null
            && $commande->getId() !== null
        ) {
            $qb
                ->andWhere(
                    'detail.commande != :commande'
                )
                ->setParameter(
                    'commande',
                    $commande
                );
        }

        return max(
            0,
            (float) $qb
                ->getQuery()
                ->getSingleScalarResult()
        );
    }


    public function getStockDisponiblePourCommande(
        Articles $article,
        ?Commandes $commande = null
    ): float {
        return max(
            0,
            $this->getStockPhysique(
                $article
            )
                - $this->getStockReserveHorsCommande(
                    $article,
                    $commande
                )
        );
    }


    /*
     * ============================================================
     * CALCUL DES BESOINS D'UN DÉTAIL
     * ============================================================
     */

    public function calculerBesoinsDetail(
    CommandesDetails $detail,
    ?float $quantiteReference = null
): array {
    /*
     * ========================================================
     * 1. VENTE DIRECTE D'UN ARTICLE
     * ========================================================
     */
    if (
        $detail->getTypeLigne()
        === CommandesDetails::TYPE_ARTICLE
    ) {
        $article =
            $detail->getArticle();

        if (!$article instanceof Articles) {
            return [];
        }

        $quantite =
            $quantiteReference
            ?? (float) $detail->getQuantite();

        if ($quantite <= 0) {
            return [];
        }

        return [
            (int) $article->getId() => [
                'article' =>
                    $article,

                'quantite' =>
                    $quantite,

                'obligatoire' =>
                    true,
            ],
        ];
    }


    /*
     * ========================================================
     * 2. SAISIE LIBRE
     * ========================================================
     *
     * Une ligne libre ne touche jamais automatiquement
     * au stock.
     */
    if (
        $detail->getTypeLigne()
        === CommandesDetails::TYPE_LIBRE
    ) {
        return [];
    }


    /*
     * ========================================================
     * 3. PRODUIT / PRESTATION
     * ========================================================
     */
    if (
        $detail->getTypeLigne()
        !== CommandesDetails::TYPE_PRODUIT
    ) {
        return [];
    }

    $produit =
        $detail->getProduit();

    if ($produit === null) {
        return [];
    }

    /*
     * Le produit ne consomme du stock que si
     * la gestion de stock est activée dans son catalogue.
     */
    if (!$produit->isGestionStock()) {
        return [];
    }


    /*
     * Quantité de référence.
     *
     * Exemple :
     * - commande = 10
     * - production partielle = 4
     *
     * Si quantiteReference = 4,
     * le besoin est calculé uniquement sur 4.
     */
    $quantite =
        $quantiteReference
        ?? (float) $detail->getQuantite();

    if ($quantite <= 0) {
        return [];
    }


    /*
     * ========================================================
     * CALCUL DES ARTICLES CONSOMMÉS PAR LE PRODUIT
     * ========================================================
     */
    $besoins = [];

    foreach (
        $produit->getArticlesStock()
        as $liaison
    ) {
        if (
            !$liaison
                instanceof ProduitArticleStock
        ) {
            continue;
        }

        if (!$liaison->isActif()) {
            continue;
        }


        $article =
            $liaison->getArticle();

        if (!$article instanceof Articles) {
            continue;
        }


        $coefficient =
            max(
                0,
                (float)
                $liaison->getCoefficient()
            );

        if ($coefficient <= 0) {
            continue;
        }


        /*
         * ====================================================
         * MODE DE CONSOMMATION
         * ====================================================
         */
        $besoin = match (
            $liaison->getModeCalcul()
        ) {
            /*
             * Exemple :
             *
             * Bâche 2m × 3m
             * Quantité 2
             * Coefficient 1
             *
             * 6 m² × 2 = 12 m² consommés
             */
            ProduitArticleStock::MODE_SURFACE =>
                max(
                    0,
                    (float) (
                        $detail->getSurface()
                        ?? 0
                    )
                )
                * $quantite
                * $coefficient,


            /*
             * Exemple :
             *
             * Longueur 2m
             * quantité 5
             * coefficient 1
             *
             * 10 mètres consommés
             */
            ProduitArticleStock::MODE_METRE =>
                max(
                    0,
                    (float) (
                        $detail->getLongueur()
                        ?? 0
                    )
                )
                * $quantite
                * $coefficient,


            /*
             * Consommation fixe indépendante
             * de la quantité commandée.
             */
            ProduitArticleStock::MODE_FORFAIT =>
                $coefficient,


            /*
             * Exemple :
             *
             * 1 support Kakémono
             * par produit vendu.
             */
            ProduitArticleStock::MODE_UNITE,
            ProduitArticleStock::MODE_QUANTITE =>
                $quantite
                * $coefficient,


            default =>
                0,
        };


        if ($besoin <= 0) {
            continue;
        }


        $articleId =
            (int) $article->getId();


        /*
         * Plusieurs liaisons peuvent éventuellement
         * pointer vers le même article.
         *
         * On regroupe donc les besoins par article.
         */
        if (
            !isset(
                $besoins[$articleId]
            )
        ) {
            $besoins[$articleId] = [
                'article' =>
                    $article,

                'quantite' =>
                    0.0,

                'obligatoire' =>
                    false,
            ];
        }


        $besoins[
            $articleId
        ]['quantite'] +=
            $besoin;


        /*
         * Si au moins une liaison est obligatoire,
         * l'article devient obligatoire pour ce détail.
         */
        if (
            $liaison->isObligatoire()
        ) {
            $besoins[
                $articleId
            ]['obligatoire'] =
                true;
        }
    }


    /*
     * ========================================================
     * ARTICLE ASSOCIÉ AU STOCK (LIEN SIMPLE)
     * ========================================================
     *
     * En plus des liaisons ProduitArticleStock (nomenclature avec
     * coefficient/mode de calcul), un produit peut aussi avoir un
     * simple "Article associé au stock" (Produits::$articleStock,
     * un champ direct du formulaire produit). Ce champ n'était
     * jusque-là lu nulle part : le lier ne déclenchait donc aucune
     * réservation/consommation de stock à la commande.
     *
     * On ne l'utilise qu'en repli, si aucune nomenclature
     * ProduitArticleStock n'a produit de besoin -- pour ne pas
     * doubler la consommation d'un produit déjà configuré avec une
     * vraie nomenclature. Consommation 1 pour 1 : pas de coefficient
     * associé à ce champ.
     */
    if ($besoins === []) {
        $articleAssocie =
            $produit->getArticleStock();

        if ($articleAssocie instanceof Articles) {
            $besoins[(int) $articleAssocie->getId()] = [
                'article' =>
                    $articleAssocie,

                'quantite' =>
                    $quantite,

                'obligatoire' =>
                    true,
            ];
        }
    }


    return $besoins;
}


    /*
     * ============================================================
     * DISPONIBILITÉ D'UN DÉTAIL
     * ============================================================
     */

    public function verifierDisponibiliteDetail(
        CommandesDetails $detail,
        ?float $quantiteReference = null
    ): array {
        $resultat = [
            'disponible' => true,
            'articles' => [],
            'manquants' => [],
        ];

        $besoins =
            $this->calculerBesoinsDetail(
                $detail,
                $quantiteReference
            );

        foreach (
            $besoins
            as $besoin
        ) {
            /** @var Articles $article */
            $article =
                $besoin['article'];

            $quantiteRequise =
                (float)
                $besoin['quantite'];

            $stock =
                $this->getStockDisponible(
                    $article
                );

            $suffisant =
                $stock
                >= $quantiteRequise;

            $ligne = [
                'article' =>
                $article,

                'articleId' =>
                $article->getId(),

                'designation' =>
                $this->getDesignationArticle(
                    $article
                ),

                'requis' =>
                $quantiteRequise,

                'stock' =>
                $stock,

                'manquant' =>
                max(
                    0,
                    $quantiteRequise
                        - $stock
                ),

                'obligatoire' =>
                (bool)
                $besoin['obligatoire'],

                'suffisant' =>
                $suffisant,
            ];

            $resultat['articles'][] = $ligne;

            if (
                !$suffisant
                && $besoin['obligatoire']
            ) {
                $resultat['disponible'] = false;

                $resultat['manquants'][] = $ligne;
            }
        }

        return $resultat;
    }


    public function construireMessageIndisponibilite(
        array $verification
    ): string {
        if (
            ($verification['disponible'] ?? true)
            === true
        ) {
            return '';
        }

        $messages = [];

        foreach (
            $verification['manquants'] ?? []
            as $manquant
        ) {
            $messages[] =
                sprintf(
                    '%s : requis %s, disponible %s, manque %s',
                    $manquant['designation'],
                    $this->formaterQuantite(
                        (float)
                        $manquant['requis']
                    ),
                    $this->formaterQuantite(
                        (float)
                        $manquant['stock']
                    ),
                    $this->formaterQuantite(
                        (float)
                        $manquant['manquant']
                    )
                );
        }

        return implode(
            "\n",
            $messages
        );
    }


    /*
     * ============================================================
     * RÉSERVER UNE COMMANDE
     * ============================================================
     */

    public function reserverPourCommande(
        Commandes $commande
    ): void {
        $besoinsParDetail = [];
        $besoinsGlobaux = [];

        /*
         * --------------------------------------------------------
         * Calcul
         * --------------------------------------------------------
         */
        foreach (
            $commande->getCommandesDetails()
            as $detail
        ) {
            if (
                !$detail
                    instanceof CommandesDetails
            ) {
                continue;
            }

            $besoins =
                $this->calculerBesoinsDetail(
                    $detail
                );

            if ($besoins === []) {
                continue;
            }

            $besoinsParDetail[] = [
                'detail' =>
                $detail,

                'besoins' =>
                $besoins,
            ];

            foreach (
                $besoins
                as $besoin
            ) {
                $article =
                    $besoin['article']
                    ?? null;

                if (
                    !$article
                        instanceof Articles
                ) {
                    continue;
                }

                $quantite =
                    max(
                        0,
                        (float) (
                            $besoin['quantite']
                            ?? 0
                        )
                    );

                if ($quantite <= 0) {
                    continue;
                }

                $articleId =
                    (int)
                    $article->getId();

                if (
                    !isset(
                        $besoinsGlobaux[$articleId]
                    )
                ) {
                    $besoinsGlobaux[$articleId] = [
                        'article' =>
                        $article,

                        'quantite' =>
                        0.0,

                        'obligatoire' =>
                        false,
                    ];
                }

                $besoinsGlobaux[$articleId]['quantite'] +=
                    $quantite;

                if (
                    (bool) (
                        $besoin['obligatoire']
                        ?? true
                    )
                ) {
                    $besoinsGlobaux[$articleId]['obligatoire'] =
                        true;
                }
            }
        }

        /*
         * Si la commande n'a plus de besoins,
         * libération des anciennes réservations.
         */
        if ($besoinsGlobaux === []) {
            $this
                ->libererReservationsCommande(
                    $commande
                );

            return;
        }

        /*
         * --------------------------------------------------------
         * Vérification AVANT modification
         * --------------------------------------------------------
         */
        $manquants = [];

        foreach (
            $besoinsGlobaux
            as $besoin
        ) {
            /** @var Articles $article */
            $article =
                $besoin['article'];

            if (
                !(bool)
                $besoin['obligatoire']
            ) {
                continue;
            }

            $requis =
                (float)
                $besoin['quantite'];

            $disponible =
                $this
                ->getStockDisponiblePourCommande(
                    $article,
                    $commande
                );

            if (
                $disponible
                >= $requis
            ) {
                continue;
            }

            $manquants[] =
                sprintf(
                    '%s : besoin %s, disponible %s, manque %s',
                    $this->getDesignationArticle(
                        $article
                    ),
                    $this->formaterQuantite(
                        $requis
                    ),
                    $this->formaterQuantite(
                        $disponible
                    ),
                    $this->formaterQuantite(
                        max(
                            0,
                            $requis
                                - $disponible
                        )
                    )
                );
        }

        if ($manquants !== []) {
            throw new \DomainException(
                "Stock insuffisant pour valider cette commande :\n"
                    . implode(
                        "\n",
                        $manquants
                    )
            );
        }

        /*
         * --------------------------------------------------------
         * Tout est OK :
         * libération anciennes réservations.
         * --------------------------------------------------------
         */
        $this->libererReservationsCommande(
            $commande
        );

        /*
         * --------------------------------------------------------
         * Nouvelles réservations
         * --------------------------------------------------------
         */
        foreach (
            $besoinsParDetail
            as $ligne
        ) {
            /** @var CommandesDetails $detail */
            $detail =
                $ligne['detail'];

            foreach (
                $ligne['besoins']
                as $besoin
            ) {
                $article =
                    $besoin['article']
                    ?? null;

                if (
                    !$article
                        instanceof Articles
                ) {
                    continue;
                }

                $quantite =
                    max(
                        0,
                        (float) (
                            $besoin['quantite']
                            ?? 0
                        )
                    );

                if ($quantite <= 0) {
                    continue;
                }

                $reservation =
                    new StockReservation();

                $reservation
                    ->setArticle(
                        $article
                    )
                    ->setCommandeDetail(
                        $detail
                    )
                    ->setQuantite(
                        $quantite
                    )
                    ->setStatut(
                        StockReservation::STATUT_ACTIVE
                    )
                    ->setObservation(
                        'Réservation automatique lors de la validation de la commande.'
                    );

                $this->entityManager
                    ->persist(
                        $reservation
                    );
            }
        }

        /*
         * Pas de flush ici.
         */
    }


    /*
     * ============================================================
     * LIBÉRER LES RÉSERVATIONS D'UNE COMMANDE
     * ============================================================
     */

    public function libererReservationsCommande(
        Commandes $commande
    ): void {
        foreach (
            $commande->getCommandesDetails()
            as $detail
        ) {
            if (
                !$detail
                    instanceof CommandesDetails
            ) {
                continue;
            }

            $this->libererReservationsDetail(
                $detail
            );
        }
    }


    /*
     * ============================================================
     * SORTIE EXISTANTE POUR UNE ORIGINE
     * ============================================================
     */

    private function trouverSortieExistante(
        CommandesDetails $detail,
        Articles $article,
        string $origine,
        string $referenceOrigine
    ): ?StockSorties {
        return $this->entityManager
            ->getRepository(
                StockSorties::class
            )
            ->findOneBy([
                'commandeDetail' =>
                $detail,

                'article' =>
                $article,

                'origine' =>
                $origine,

                'referenceOrigine' =>
                $referenceOrigine,
            ]);
    }


    /*
     * ============================================================
     * CONSOMMATION PHYSIQUE
     * ============================================================
     *
     * Exemple production :
     *
     * consommerPourDetail(
     *     $detail,
     *     'production',
     *     'OP-000025'
     * );
     *
     * Exemple livraison :
     *
     * consommerPourDetail(
     *     $detail,
     *     'livraison',
     *     'BL-2026-000015',
     *     4
     * );
     * ============================================================
     */

    public function consommerPourDetail(
        CommandesDetails $detail,
        string $origine,
        string $referenceOrigine,
        ?float $quantiteReference = null
    ): void {
        $origine =
            trim($origine);

        $referenceOrigine =
            trim($referenceOrigine);

        if ($origine === '') {
            throw new \DomainException(
                'L’origine de la sortie de stock est obligatoire.'
            );
        }

        if ($referenceOrigine === '') {
            throw new \DomainException(
                'La référence de la sortie de stock est obligatoire.'
            );
        }

        $originesAutorisees = [
            StockSorties::ORIGINE_PRODUCTION,
            StockSorties::ORIGINE_LIVRAISON,
            StockSorties::ORIGINE_MANUELLE,
        ];

        if (
            !in_array(
                $origine,
                $originesAutorisees,
                true
            )
        ) {
            throw new \DomainException(
                'Origine de sortie de stock invalide.'
            );
        }

        $besoins =
            $this->calculerBesoinsDetail(
                $detail,
                $quantiteReference
            );

        if ($besoins === []) {
            return;
        }

        /*
         * Réservations actives regroupées
         * par article.
         */
        $reservations =
            $this->entityManager
            ->getRepository(
                StockReservation::class
            )
            ->findBy([
                'commandeDetail' =>
                $detail,

                'statut' =>
                StockReservation::STATUT_ACTIVE,
            ]);

        $reservationsParArticle = [];

        foreach (
            $reservations
            as $reservation
        ) {
            if (
                !$reservation
                    instanceof StockReservation
            ) {
                continue;
            }

            $article =
                $reservation->getArticle();

            if (
                !$article
                    instanceof Articles
                || $article->getId() === null
            ) {
                continue;
            }

            $reservationsParArticle[(int)
                $article->getId()][] = $reservation;
        }

        /*
         * ========================================================
         * PREMIÈRE PASSE :
         * VALIDATION DE TOUTES LES SORTIES
         * ========================================================
         *
         * Important :
         * on évite de persister la moitié des articles
         * avant de découvrir qu'un autre manque.
         */
        $aCreer = [];

        foreach (
            $besoins
            as $besoin
        ) {
            $article =
                $besoin['article']
                ?? null;

            if (
                !$article
                    instanceof Articles
            ) {
                continue;
            }

            $quantite =
                max(
                    0,
                    (float) (
                        $besoin['quantite']
                        ?? 0
                    )
                );

            if ($quantite <= 0) {
                continue;
            }

            /*
             * Idempotence.
             *
             * Une même origine + même référence
             * ne peut sortir deux fois le même article
             * pour le même détail.
             */
            $existante =
                $this->trouverSortieExistante(
                    $detail,
                    $article,
                    $origine,
                    $referenceOrigine
                );

            if ($existante !== null) {
                /*
                 * L'opération a déjà été enregistrée.
                 * On ne refait rien.
                 */
                continue;
            }

            $stockPhysique =
                $this->getStockPhysique(
                    $article
                );

            if (
                $stockPhysique
                < $quantite
            ) {
                throw new \DomainException(
                    sprintf(
                        'Stock physique insuffisant pour %s : besoin %s, disponible %s.',
                        $this->getDesignationArticle(
                            $article
                        ),
                        $this->formaterQuantite(
                            $quantite
                        ),
                        $this->formaterQuantite(
                            $stockPhysique
                        )
                    )
                );
            }

            $aCreer[] = [
                'article' =>
                $article,

                'quantite' =>
                $quantite,

                'stockAvant' =>
                $stockPhysique,
            ];
        }

        /*
         * ========================================================
         * DEUXIÈME PASSE :
         * CRÉATION DES SORTIES
         * ========================================================
         */
        foreach (
            $aCreer
            as $donnees
        ) {
            /** @var Articles $article */
            $article =
                $donnees['article'];

            $quantite =
                (float)
                $donnees['quantite'];

            $sortie =
                new StockSorties();

            $sortie
                ->setArticle(
                    $article
                )
                ->setCommandeDetail(
                    $detail
                )
                ->setQuantite(
                    $quantite
                )
                ->setDate(
                    new \DateTimeImmutable()
                )
                ->setOrigine(
                    $origine
                )
                ->setReferenceOrigine(
                    $referenceOrigine
                );

            $this->entityManager
                ->persist(
                    $sortie
                );

            /*
             * Consomme maintenant la réservation
             * correspondante.
             */
            $this->consommerReservationArticle(
                $detail,
                $article,
                $quantite,
                $reservationsParArticle[(int)
                    $article->getId()] ?? []
            );

            $this->verifierSeuilAlerte(
                $article,
                (float) $donnees['stockAvant'],
                $quantite
            );
        }

        /*
         * Toujours pas de flush ici.
         *
         * Le contrôleur production/livraison fera
         * le flush dans la même transaction métier.
         */
    }


    /*
     * ============================================================
     * CONSOMMER UNE RÉSERVATION
     * ============================================================
     */

    private function consommerReservationArticle(
        CommandesDetails $detail,
        Articles $article,
        float $quantite,
        array $reservations
    ): void {
        $reste =
            max(
                0,
                $quantite
            );

        foreach (
            $reservations
            as $reservation
        ) {
            if (
                $reste <= 0
            ) {
                break;
            }

            if (
                !$reservation
                    instanceof StockReservation
                || !$reservation->isActive()
            ) {
                continue;
            }

            $reserve =
                max(
                    0,
                    (float)
                    $reservation->getQuantite()
                );

            if ($reserve <= 0) {
                continue;
            }

            /*
             * Toute la réservation est consommée.
             */
            if (
                $reste
                >= $reserve - 0.000001
            ) {
                $reste -=
                    $reserve;

                $reservation
                    ->marquerConsommee();

                continue;
            }

            /*
             * Consommation partielle.
             *
             * Exemple :
             * réservation active : 10
             * consommation       : 4
             *
             * ancienne ACTIVE    : 6
             * nouvelle CONSOMMEE : 4
             */
            $reservation
                ->setQuantite(
                    $reserve
                        - $reste
                );

            $consommee =
                new StockReservation();

            $consommee
                ->setArticle(
                    $article
                )
                ->setCommandeDetail(
                    $detail
                )
                ->setQuantite(
                    $reste
                )
                ->setStatut(
                    StockReservation::STATUT_CONSOMMEE
                )
                ->setDateConsommation(
                    new \DateTimeImmutable()
                )
                ->setObservation(
                    'Consommation partielle automatique de la réservation.'
                );

            $this->entityManager
                ->persist(
                    $consommee
                );

            $reste = 0;
        }

        /*
         * Si $reste > 0, cela signifie qu'une consommation
         * physique a été faite sans réservation suffisante.
         *
         * On ne bloque pas ici car le stock physique peut
         * légitimement être consommé après une réservation
         * partielle ou dans certains flux manuels.
         */
    }


    /*
     * ============================================================
     * CONSOMMER TOUTES LES RÉSERVATIONS D'UN DÉTAIL
     * ============================================================
     */

    public function consommerReservationsDetail(
        CommandesDetails $detail
    ): void {
        $reservations =
            $this->entityManager
            ->getRepository(
                StockReservation::class
            )
            ->findBy([
                'commandeDetail' =>
                $detail,

                'statut' =>
                StockReservation::STATUT_ACTIVE,
            ]);

        foreach (
            $reservations
            as $reservation
        ) {
            if (
                !$reservation
                    instanceof StockReservation
            ) {
                continue;
            }

            $reservation
                ->marquerConsommee();
        }
    }


    /*
     * ============================================================
     * CONSOMMABLE MANUEL (PRODUCTION)
     * ============================================================
     *
     * En plus de la nomenclature automatique (calculerBesoinsDetail),
     * un agent peut enregistrer à la main un consommable utilisé
     * pendant la production (colle, encre, film...) qui n'était pas
     * prévu dans la nomenclature du produit. Ça crée une sortie de
     * stock immédiate, retirée du stock disponible.
     */

    public function enregistrerConsommableManuel(
        ?CommandesDetails $detail,
        Articles $article,
        int $quantite,
        ?string $referenceOrigine = null
    ): StockSorties {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException(
                'La quantité doit être supérieure à zéro.'
            );
        }

        $stockAvant = $this->getStockPhysique($article);

        $sortie = new StockSorties();
        $sortie
            ->setArticle($article)
            ->setCommandeDetail($detail)
            ->setQuantite($quantite)
            ->setDate(new \DateTimeImmutable())
            ->setOrigine(StockSorties::ORIGINE_MANUELLE)
            ->setReferenceOrigine($referenceOrigine);

        $this->entityManager->persist($sortie);

        $this->verifierSeuilAlerte($article, $stockAvant, (float) $quantite);

        $this->entityManager->flush();

        return $sortie;
    }

    public function supprimerConsommableManuel(
        StockSorties $sortie
    ): void {
        $this->entityManager->remove($sortie);
        $this->entityManager->flush();
    }


    /*
     * ============================================================
     * UTILITAIRES
     * ============================================================
     */

    private function getDesignationArticle(
        Articles $article
    ): string {
        $designation =
            method_exists(
                $article,
                'getDesignation'
            )
            ? trim(
                (string)
                $article->getDesignation()
            )
            : '';

        return $designation !== ''
            ? $designation
            : 'Article #'
            . $article->getId();
    }


    private function formaterQuantite(
        float $quantite
    ): string {
        if (
            abs(
                $quantite
                    - round($quantite)
            ) < 0.000001
        ) {
            return number_format(
                $quantite,
                0,
                ',',
                ' '
            );
        }

        return rtrim(
            rtrim(
                number_format(
                    $quantite,
                    3,
                    ',',
                    ' '
                ),
                '0'
            ),
            ','
        );
    }
    public function libererReservationsDetail(
        CommandesDetails $detail
    ): void {
        $reservations = $this->entityManager
            ->getRepository(
                StockReservation::class
            )
            ->findBy([
                'commandeDetail' =>
                $detail,

                'statut' =>
                StockReservation::STATUT_ACTIVE,
            ]);

        foreach (
            $reservations
            as $reservation
        ) {
            if (
                !$reservation
                    instanceof StockReservation
            ) {
                continue;
            }

            $reservation->liberer();
        }
    }
}
