<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Service\Livraisons\PreparationLivraison;
use App\Entity\BonLivraisonLigne;
use App\Entity\Commandes;
use App\Entity\StockSorties;
use App\Entity\CommandesDetails;
use App\Entity\User;
use App\Repository\BonLivraisonRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/bons-livraison',
    name: 'app_bons_livraison_'
)]
final class BonLivraisonController extends AbstractController
{
    /*
     * ============================================================
     * LISTE DES BONS DE LIVRAISON
     * ============================================================
     */
    #[Route(
        '/',
        name: 'index',
        methods: ['GET']
    )]
    public function index(
        Request $request,
        BonLivraisonRepository $bonLivraisonRepository
    ): Response {
        $recherche = trim(
            (string) $request->query->get('q', '')
        );

        $statut = trim(
            (string) $request->query->get('statut', '')
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

        $qb = $bonLivraisonRepository
            ->createQueryBuilder('bl');

        $qb
            ->leftJoin(
                'bl.commande',
                'commande'
            )
            ->addSelect('commande')

            ->leftJoin(
                'bl.creePar',
                'creePar'
            )
            ->addSelect('creePar');

        /*
         * Recherche.
         */
        if ($recherche !== '') {
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'LOWER(bl.numero) LIKE LOWER(:recherche)'
                    )
                )
                ->setParameter(
                    'recherche',
                    '%' . $recherche . '%'
                );
        }

        /*
         * Filtre statut.
         */
        $statutsAutorises = [
            BonLivraison::STATUT_BROUILLON,
            BonLivraison::STATUT_VALIDE,
            BonLivraison::STATUT_LIVRE,
            BonLivraison::STATUT_ANNULE,
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
                    'bl.statut = :statut'
                )
                ->setParameter(
                    'statut',
                    $statut
                );
        }

        /*
         * Date début.
         */
        if ($dateDebut !== '') {
            try {
                $debut = new \DateTimeImmutable(
                    $dateDebut . ' 00:00:00'
                );

                $qb
                    ->andWhere(
                        'bl.creeLe >= :dateDebut'
                    )
                    ->setParameter(
                        'dateDebut',
                        $debut
                    );
            } catch (\Throwable) {
            }
        }

        /*
         * Date fin.
         */
        if ($dateFin !== '') {
            try {
                $fin = new \DateTimeImmutable(
                    $dateFin . ' 23:59:59'
                );

                $qb
                    ->andWhere(
                        'bl.creeLe <= :dateFin'
                    )
                    ->setParameter(
                        'dateFin',
                        $fin
                    );
            } catch (\Throwable) {
            }
        }

        $bons = $qb
            ->orderBy(
                'bl.id',
                'DESC'
            )
            ->getQuery()
            ->getResult();

        $compteurs = [
            'brouillon' => 0,
            'valide' => 0,
            'livre' => 0,
            'annule' => 0,
        ];

        foreach ($bons as $bon) {
            if ($bon->getStatut() === BonLivraison::STATUT_BROUILLON) {
                ++$compteurs['brouillon'];
            }

            if ($bon->getStatut() === BonLivraison::STATUT_VALIDE) {
                ++$compteurs['valide'];
            }

            if ($bon->getStatut() === BonLivraison::STATUT_LIVRE) {
                ++$compteurs['livre'];
            }

            if ($bon->getStatut() === BonLivraison::STATUT_ANNULE) {
                ++$compteurs['annule'];
            }
        }

        return $this->render(
            'bons_livraison/index.html.twig',
            [
                'bons' => $bons,

                'compteurs' => $compteurs,

                'filtres' => [
                    'q' => $recherche,
                    'statut' => $statut,
                    'date_debut' => $dateDebut,
                    'date_fin' => $dateFin,
                ],
            ]
        );
    }

    /*
     * ============================================================
     * CRÉER UN BON DE LIVRAISON DEPUIS UNE COMMANDE
     * ============================================================
     */
    /*
 * ============================================================
 * CRÉER UN BON DE LIVRAISON DEPUIS UNE COMMANDE
 * ============================================================
 *
 * Gère :
 * - livraison totale ;
 * - livraison partielle ;
 * - quantités déjà livrées ;
 * - quantités réservées dans d'autres BL ouverts ;
 * - production et vente directe.
 * ============================================================
 */
    #[Route(
        '/creer/commande/{id}',
        name: 'creer_commande',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function creerDepuisCommande(
        Commandes $commande,
        Request $request,
        PreparationLivraison $preparation
    ): Response {
        $utilisateur = $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'bon_livraison_creer_commande_' . $commande->getId()
        );

        // Lignes prêtes, quantités disponibles, livraison programmée par le client : PreparationLivraison.
        try {
            ['bon' => $bon, 'lignes' => $nombreLignesAjoutees] = $preparation->creerDepuisCommande($commande, $utilisateur);

            $this->addFlash(
                'success',
                sprintf(
                    'Le bon de livraison %s a été créé avec %d ligne(s).',
                    $bon->getNumero(),
                    $nombreLignesAjoutees
                )
            );

            return $this->redirectToRoute(
                'app_bons_livraison_show',
                [
                    'id' => $bon->getId(),
                ]
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
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
     * AFFICHER LE BON
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
        BonLivraison $bon
    ): Response {
        return $this->render(
            'bons_livraison/show.html.twig',
            [
                'bon' => $bon,
            ]
        );
    }

    /*
     * ============================================================
     * MODIFIER LES INFORMATIONS DE LIVRAISON
     * ============================================================
     */
    #[Route(
        '/{id}/modifier-informations',
        name: 'modifier_informations',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function modifierInformations(
        BonLivraison $bon,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->verifierJeton(
            $request,
            'bon_livraison_modifier_' . $bon->getId()
        );

        try {
            if (!$bon->estBrouillon()) {
                throw new \LogicException(
                    'Seul un bon de livraison en brouillon peut être modifié.'
                );
            }

            /*
         * ========================================================
         * INFORMATIONS GÉNÉRALES
         * ========================================================
         */
            $bon
                ->setNomReceptionnaire(
                    $request->request->get(
                        'nom_receptionnaire'
                    )
                )
                ->setTelephoneReceptionnaire(
                    $request->request->get(
                        'telephone_receptionnaire'
                    )
                )
                ->setAdresseLivraison(
                    $request->request->get(
                        'adresse_livraison'
                    )
                )
                ->setObservation(
                    $request->request->get(
                        'observation'
                    )
                );

            /*
         * ========================================================
         * QUANTITÉS
         * ========================================================
         *
         * Formulaire attendu :
         *
         * quantites[ID_LIGNE] = quantité
         * ========================================================
         */
            $quantites = $request->request->all(
                'quantites'
            );

            foreach ($bon->getLignes() as $ligne) {
                $ligneId = $ligne->getId();

                if (
                    $ligneId === null
                    || !array_key_exists(
                        $ligneId,
                        $quantites
                    )
                ) {
                    continue;
                }

                $detail = $ligne->getCommandeDetail();

                if ($detail === null) {
                    throw new \LogicException(
                        'Une ligne du bon n’est plus liée à son détail de commande.'
                    );
                }

                $quantiteDemandee =
                    (int) $quantites[$ligneId];

                if ($quantiteDemandee < 1) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'La quantité livrée pour « %s » doit être supérieure à zéro.',
                            $ligne->getDesignation()
                        )
                    );
                }

                /*
             * Quantité restant réellement à livrer.
             */
                $quantiteRestante =
                    $detail->getQuantiteRestanteLivraison();

                /*
             * Quantité réservée dans LES AUTRES BL.
             *
             * Le BL courant doit être exclu,
             * sinon sa propre ancienne quantité serait
             * comptée comme réservation.
             */
                $quantiteReserveeAutresBons =
                    $this->calculerQuantiteReserveeLivraison(
                        $detail,
                        $em,
                        $bon
                    );

                $maximumDisponible = max(
                    0,
                    $quantiteRestante
                        - $quantiteReserveeAutresBons
                );

                if ($quantiteDemandee > $maximumDisponible) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Impossible de livrer %d unité(s) de « %s ». '
                                . 'La quantité maximale actuellement disponible est %d.',
                            $quantiteDemandee,
                            $ligne->getDesignation(),
                            $maximumDisponible
                        )
                    );
                }

                /*
             * Maintenant seulement, on applique la valeur.
             */
                $ligne->setQuantiteLivree(
                    $quantiteDemandee
                );
            }

            $em->flush();

            $this->addFlash(
                'success',
                'Les informations du bon de livraison ont été enregistrées.'
            );
        } catch (
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'app_bons_livraison_show',
            [
                'id' => $bon->getId(),
            ]
        );
    }


    /*
     * ============================================================
     * VALIDER LE BON
     * ============================================================
     */
    #[Route(
        '/{id}/valider',
        name: 'valider',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function valider(
        BonLivraison $bon,
        Request $request,
        PreparationLivraison $preparation
    ): Response {
        $utilisateur =
            $this->utilisateurConnecte();

        $this->verifierJeton(
            $request,
            'bon_livraison_valider_' . $bon->getId()
        );

        // Contrôles, stock des ventes directes seulement, lignes « en livraison » : PreparationLivraison.
        try {
            $preparation->valider($bon, $utilisateur);

            $this->addFlash(
                'success',
                sprintf(
                    'Le bon de livraison %s a été validé.',
                    $bon->getNumero()
                )
            );
        } catch (
            \DomainException |
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'app_bons_livraison_show',
            [
                'id' => $bon->getId(),
            ]
        );
    }

    /*
     * ============================================================
     * CONFIRMER LA LIVRAISON
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
    BonLivraison $bon,
    Request $request,
    EntityManagerInterface $em
): Response {
    $utilisateur =
        $this->utilisateurConnecte();

    $this->verifierJeton(
        $request,
        'bon_livraison_livrer_' . $bon->getId()
    );

    try {
        $bon->marquerLivre(
            $utilisateur
        );

        $em->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Le bon de livraison %s a été marqué comme livré.',
                $bon->getNumero()
            )
        );
    } catch (
        \LogicException |
        \InvalidArgumentException $e
    ) {
        $this->addFlash(
            'error',
            $e->getMessage()
        );
    }

    return $this->redirectToRoute(
        'app_bons_livraison_show',
        [
            'id' => $bon->getId(),
        ]
    );
}

    /*
     * ============================================================
     * ANNULER
     * ============================================================
     */
    #[Route(
        '/{id}/annuler',
        name: 'annuler',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function annuler(
        BonLivraison $bon,
        Request $request,
        EntityManagerInterface $em
    ): Response {
        $this->verifierJeton(
            $request,
            'bon_livraison_annuler_' . $bon->getId()
        );

        try {
            $nombreSorties = (int) $em
                ->getRepository(
                    StockSorties::class
                )
                ->createQueryBuilder('s')
                ->select('COUNT(s.id)')
                ->andWhere(
                    's.origine = :origine'
                )
                ->andWhere(
                    's.referenceOrigine = :reference'
                )
                ->setParameter(
                    'origine',
                    StockSorties::ORIGINE_LIVRAISON
                )
                ->setParameter(
                    'reference',
                    $bon->getNumero()
                )
                ->getQuery()
                ->getSingleScalarResult();

            if ($nombreSorties > 0) {
                throw new \LogicException(
                    'Ce bon de livraison a déjà généré une sortie physique de stock. Il ne peut pas être annulé directement. Une opération de retour de stock est nécessaire.'
                );
            }
            $bon->annuler();

            /*
             * Si le BL avait déjà été validé,
             * on remet ses lignes à PRETE_LIVRAISON
             * tant qu'elles ne sont pas livrées.
             */
            foreach ($bon->getLignes() as $ligne) {
                $detail = $ligne->getCommandeDetail();

                if ($detail === null) {
                    continue;
                }

                if (
                    $detail->getStatutProduction()
                    === CommandesDetails::PRODUCTION_EN_LIVRAISON
                ) {
                    $detail->setStatutProduction(
                        CommandesDetails::PRODUCTION_PRETE_LIVRAISON
                    );
                }
            }

            $em->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'Le bon de livraison %s a été annulé.',
                    $bon->getNumero()
                )
            );
        } catch (
            \DomainException |
            \LogicException |
            \InvalidArgumentException $e
        ) {
            $this->addFlash(
                'error',
                $e->getMessage()
            );
        }

        return $this->redirectToRoute(
            'app_bons_livraison_show',
            [
                'id' => $bon->getId(),
            ]
        );
    }

    /*
     * ============================================================
     * IMPRESSION A4
     * ============================================================
     */
    #[Route(
        '/{id}/imprimer',
        name: 'imprimer',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function imprimer(
        BonLivraison $bon
    ): Response {
        return $this->render(
            'bons_livraison/print.html.twig',
            [
                'bon' => $bon,
            ]
        );
    }

    /*
     * ============================================================
     * GÉNÉRATION DU NUMÉRO
     * ============================================================
     *
     * Format :
     *
     * BL-2026-000001
     * BL-2026-000002
     * ...
     * ============================================================
     */

    /*
 * ============================================================
 * QUANTITÉ RÉSERVÉE DANS LES BL OUVERTS
 * ============================================================
 *
 * Une quantité est considérée comme réservée lorsqu'elle se
 * trouve dans un BL :
 *
 * - BROUILLON ;
 * - VALIDÉ.
 *
 * Les BL :
 * - LIVRÉS : sont déjà comptabilisés dans quantiteLivree ;
 * - ANNULÉS : ne réservent plus rien.
 * ============================================================
 */
    private function calculerQuantiteReserveeLivraison(
        CommandesDetails $detail,
        EntityManagerInterface $em,
        ?BonLivraison $bonExclu = null
    ): int {
        $qb = $em
            ->getRepository(BonLivraisonLigne::class)
            ->createQueryBuilder('ligne');

        $qb
            ->select(
                'COALESCE(SUM(ligne.quantiteLivree), 0)'
            )
            ->innerJoin(
                'ligne.bonLivraison',
                'bl'
            )
            ->andWhere(
                'ligne.commandeDetail = :detail'
            )
            ->andWhere(
                'bl.statut IN (:statuts)'
            )
            ->setParameter(
                'detail',
                $detail
            )
            ->setParameter(
                'statuts',
                [
                    BonLivraison::STATUT_BROUILLON,
                    BonLivraison::STATUT_VALIDE,
                ]
            );

        /*
     * Lorsqu'on modifie un BL existant,
     * on ne doit pas compter ses propres lignes
     * comme réservées.
     */
        if (
            $bonExclu !== null
            && $bonExclu->getId() !== null
        ) {
            $qb
                ->andWhere(
                    'bl.id != :bonExclu'
                )
                ->setParameter(
                    'bonExclu',
                    $bonExclu->getId()
                );
        }

        $resultat = $qb
            ->getQuery()
            ->getSingleScalarResult();

        return max(
            0,
            (int) $resultat
        );
    }
    /*
     * ============================================================
     * UTILISATEUR CONNECTÉ
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
     * CSRF
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
