<?php

namespace App\Controller;

use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Form\MouvementTresorerieType;
use App\Repository\CompteTresorerieRepository;
use App\Repository\MouvementTresorerieRepository;
use App\Repository\UserRepository;
use App\Service\MouvementTresorerieService;
use App\Service\Paiement\ComptesOrangeMoney;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/*
 * Comptes et mouvements de trésorerie : la liste, le détail et la gestion
 * demandent le droit « Trésorerie et soldes » (ROLE_TRESORERIE_VOIR).
 *
 * Seul le formulaire de saisie (new) s'ouvre aussi sans ce droit, avec
 * celui de saisir (entrée, sortie) ou de faire un virement : la caisse y
 * verse par exemple sa caisse à la banque. Il ne montre alors aucun
 * solde, et l'enregistrement ramène à la caisse (ou aux dépenses).
 */
#[Route(
    '/gestion/tresorerie/mouvements',
    name: 'app_mouvement_tresorerie_'
)]
class MouvementTresorerieController extends AbstractController
{
    /*
     * ============================================================
     * INDEX
     * ============================================================
     */

    #[Route(
        '',
        name: 'index',
        methods: ['GET']
    )]
    #[IsGranted('ROLE_TRESORERIE_VOIR')]
    public function index(
        Request $request,
        MouvementTresorerieRepository $repository,
        CompteTresorerieRepository $compteRepository,
        UserRepository $userRepository
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Utilisateur non authentifié.'
            );
        }

        $estAdmin =
            $this->isGranted('ROLE_ADMIN');


        /*
         * ========================================================
         * FILTRES
         * ========================================================
         */

        $filtres = [
            'recherche' =>
                trim(
                    (string) $request
                        ->query
                        ->get(
                            'recherche',
                            ''
                        )
                ),

            'compte' =>
                $request
                    ->query
                    ->get('compte'),

            'agent' =>
                $request
                    ->query
                    ->get('agent'),

            'type' =>
                $request
                    ->query
                    ->get('type'),

            'statut' =>
                $request
                    ->query
                    ->get('statut'),

            'modePaiement' =>
                $request
                    ->query
                    ->get(
                        'modePaiement'
                    ),

            'dateDebut' =>
                $request
                    ->query
                    ->get(
                        'dateDebut'
                    ),

            'dateFin' =>
                $request
                    ->query
                    ->get(
                        'dateFin'
                    ),
        ];


        /*
         * ========================================================
         * COMPTES VISIBLES
         * ========================================================
         *
         * ADMIN :
         * tous les comptes.
         *
         * AUTRES :
         *
         * - leurs comptes personnels ;
         * - les comptes partagés.
         *
         * Jamais :
         * - compte personnel d'un autre agent ;
         * - compte administratif ;
         * - banque.
         * ========================================================
         */

        $tousLesComptes =
            $compteRepository->findBy(
                [
                    'actif' => true,
                ],
                [
                    'nom' => 'ASC',
                ]
            );


        if ($estAdmin) {
            $comptes =
                $tousLesComptes;
        } else {
            $comptes =
                array_values(
                    array_filter(
                        $tousLesComptes,
                        fn (
                            CompteTresorerie $compte
                        ): bool =>
                            $this
                                ->utilisateurPeutVoirCompte(
                                    $compte,
                                    $user
                                )
                    )
                );
        }


        /*
         * ========================================================
         * PROTECTION DU FILTRE COMPTE
         * ========================================================
         */

        if (
            !$estAdmin
            &&
            !empty(
                $filtres['compte']
            )
        ) {
            $compteDemande =
                $compteRepository->find(
                    (int)
                    $filtres['compte']
                );

            if (
                !$compteDemande
                ||
                !$this->utilisateurPeutVoirCompte(
                    $compteDemande,
                    $user
                )
            ) {
                $filtres['compte'] =
                    null;
            }
        }


        /*
         * ========================================================
         * PÉRIODE
         * ========================================================
         */

        $periode =
            trim(
                (string) $request
                    ->query
                    ->get(
                        'periode',
                        ''
                    )
            );


        $maintenant =
            new \DateTimeImmutable();


        $dateDebutDemandee =
            trim(
                (string) (
                    $filtres['dateDebut']
                    ?? ''
                )
            );


        $dateFinDemandee =
            trim(
                (string) (
                    $filtres['dateFin']
                    ?? ''
                )
            );


        /*
         * Mois courant par défaut.
         */
        if (
            $periode === ''
            &&
            $dateDebutDemandee === ''
            &&
            $dateFinDemandee === ''
        ) {
            $periode =
                'mois';
        }


        switch ($periode) {
            case 'aujourdhui':

                $filtres['dateDebut'] =
                    $maintenant
                        ->format('Y-m-d');

                $filtres['dateFin'] =
                    $maintenant
                        ->format('Y-m-d');

                break;


            case 'hier':

                $hier =
                    $maintenant
                        ->modify('-1 day');

                $filtres['dateDebut'] =
                    $hier
                        ->format('Y-m-d');

                $filtres['dateFin'] =
                    $hier
                        ->format('Y-m-d');

                break;


            case 'semaine':

                $filtres['dateDebut'] =
                    $maintenant
                        ->modify(
                            'monday this week'
                        )
                        ->format('Y-m-d');

                $filtres['dateFin'] =
                    $maintenant
                        ->modify(
                            'sunday this week'
                        )
                        ->format('Y-m-d');

                break;


            case 'mois':

                $filtres['dateDebut'] =
                    $maintenant
                        ->modify(
                            'first day of this month'
                        )
                        ->format('Y-m-d');

                $filtres['dateFin'] =
                    $maintenant
                        ->modify(
                            'last day of this month'
                        )
                        ->format('Y-m-d');

                break;


            case 'annee':

                $annee =
                    $maintenant
                        ->format('Y');

                $filtres['dateDebut'] =
                    $annee
                    . '-01-01';

                $filtres['dateFin'] =
                    $annee
                    . '-12-31';

                break;
        }


        /*
         * ========================================================
         * MOUVEMENTS
         * ========================================================
         */

        $mouvements =
            $repository
                ->rechercherAvecFiltres(
                    $filtres
                );


        /*
         * ========================================================
         * FILTRAGE DE SÉCURITÉ
         * ========================================================
         */

        if (!$estAdmin) {
            $mouvements =
                array_values(
                    array_filter(
                        $mouvements,
                        fn (
                            MouvementTresorerie $mouvement
                        ): bool =>
                            $this
                                ->mouvementEstVisiblePourUtilisateur(
                                    $mouvement,
                                    $user
                                )
                    )
                );
        }


        /*
         * ========================================================
         * TOTAUX
         * ========================================================
         *
         * Ils correspondent uniquement aux mouvements
         * réellement visibles.
         *
         * Les cartes globales sensibles doivent rester
         * protégées dans Twig.
         * ========================================================
         */

        $totaux = [
            'encaissements' => 0,
            'decaissements' => 0,
            'transferts' => 0,

            'nombre' =>
                count(
                    $mouvements
                ),

            'soldeNet' => 0,
        ];


        foreach (
            $mouvements
            as $mouvement
        ) {
            if (
                !$mouvement->isValide()
            ) {
                continue;
            }


            $montant =
                (int)
                $mouvement->getMontant();


            switch (
                $mouvement->getType()
            ) {
                case
                    MouvementTresorerie::TYPE_ENCAISSEMENT:

                    $totaux[
                        'encaissements'
                    ] += $montant;

                    break;


                case
                    MouvementTresorerie::TYPE_DECAISSEMENT:

                    $totaux[
                        'decaissements'
                    ] += $montant;

                    break;


                case
                    MouvementTresorerie::TYPE_TRANSFERT:

                    $totaux[
                        'transferts'
                    ] += $montant;

                    break;
            }
        }


        $totaux['soldeNet'] =
            $totaux['encaissements']
            -
            $totaux['decaissements'];


        /*
         * ========================================================
         * STATISTIQUES PERSONNELLES
         * ========================================================
         *
         * Important :
         *
         * ce sont les opérations réalisées PAR l'agent.
         *
         * Ce n'est PAS le solde de sa caisse.
         *
         * Sur Orange Money / Wave partagé :
         * on sait précisément quelle caissière a fait quoi
         * grâce à MouvementTresorerie::agent.
         * ========================================================
         */

        $filtresPersonnels =
            $filtres;


        /*
         * Le filtre Agent demandé dans l'URL
         * ne peut jamais modifier les statistiques personnelles.
         */
        $filtresPersonnels['agent'] =
            $user->getId();


        $mouvementsPersonnels =
            $repository
                ->rechercherAvecFiltres(
                    $filtresPersonnels
                );


        if (!$estAdmin) {
            $mouvementsPersonnels =
                array_values(
                    array_filter(
                        $mouvementsPersonnels,
                        fn (
                            MouvementTresorerie $mouvement
                        ): bool =>
                            $this
                                ->mouvementEstVisiblePourUtilisateur(
                                    $mouvement,
                                    $user
                                )
                    )
                );
        }


        $statistiquesPersonnelles = [
            'encaissements' => 0,
            'decaissements' => 0,
            'net' => 0,

            'especes' => 0,
            'orangeMoney' => 0,
            'wave' => 0,

            'nombreEncaissements' => 0,
            'nombreDecaissements' => 0,
        ];


        foreach (
            $mouvementsPersonnels
            as $mouvement
        ) {
            if (
                !$mouvement->isValide()
            ) {
                continue;
            }


            /*
             * Double sécurité :
             *
             * statistiques strictement personnelles.
             */
            if (
                $mouvement->getAgent()
                !== $user
            ) {
                continue;
            }


            $montant =
                (int)
                $mouvement->getMontant();


            /*
             * ====================================================
             * ENCAISSEMENT
             * ====================================================
             */

            if (
                $mouvement->getType()
                ===
                MouvementTresorerie::TYPE_ENCAISSEMENT
            ) {
                $statistiquesPersonnelles[
                    'encaissements'
                ] += $montant;


                ++$statistiquesPersonnelles[
                    'nombreEncaissements'
                ];


                $destination =
                    $mouvement
                        ->getCompteDestination();


                if (
                    $destination !== null
                ) {
                    switch (
                        $destination->getType()
                    ) {
                        case
                            CompteTresorerie::TYPE_CAISSE:

                            $statistiquesPersonnelles[
                                'especes'
                            ] += $montant;

                            break;


                        case
                            CompteTresorerie::TYPE_ORANGE_MONEY:

                            $statistiquesPersonnelles[
                                'orangeMoney'
                            ] += $montant;

                            break;


                        case
                            CompteTresorerie::TYPE_WAVE:

                            $statistiquesPersonnelles[
                                'wave'
                            ] += $montant;

                            break;
                    }
                }
            }


            /*
             * ====================================================
             * DÉCAISSEMENT
             * ====================================================
             */

            if (
                $mouvement->getType()
                ===
                MouvementTresorerie::TYPE_DECAISSEMENT
            ) {
                $statistiquesPersonnelles[
                    'decaissements'
                ] += $montant;


                ++$statistiquesPersonnelles[
                    'nombreDecaissements'
                ];
            }
        }


        $statistiquesPersonnelles['net'] =
            $statistiquesPersonnelles[
                'encaissements'
            ]
            -
            $statistiquesPersonnelles[
                'decaissements'
            ];


        /*
         * ========================================================
         * AGENTS
         * ========================================================
         *
         * Utiles notamment pour identifier les opérations
         * faites sur Orange Money / Wave partagé.
         * ========================================================
         */

        $agents =
            $userRepository->findBy(
                [
                    'actif' => true,
                ],
                [
                    'username' => 'ASC',
                ]
            );


        /*
         * ========================================================
         * SOLDES DES COMPTES ACCESSIBLES
         * ========================================================
         *
         * Pour la caissière :
         *
         * - sa caisse personnelle ;
         * - Orange Money partagé ;
         * - Wave partagé.
         *
         * Ce sont de vrais soldes de compte,
         * contrairement aux statistiques personnelles.
         * ========================================================
         */

        $soldesComptes = [];

        foreach (
            $comptes
            as $compte
        ) {
            $soldesComptes[] = [
                'compte' =>
                    $compte,

                'solde' =>
                    (int)
                    $compte->getSoldeActuel(),
            ];
        }

$maCaissePersonnelle = null;

foreach ($comptes as $compte) {
    if (
        $compte->estPersonnel()
        &&
        $compte->appartientA($user)
    ) {
        $maCaissePersonnelle = $compte;
        break;
    }
}
        return $this->render(
            'mouvement_tresorerie/index.html.twig',
            [
                'mouvements' =>
                    $mouvements,
                    'maCaissePersonnelle' =>
    $maCaissePersonnelle,

                'comptes' =>
                    $comptes,

                'soldesComptes' =>
                    $soldesComptes,

                'agents' =>
                    $agents,

                'filtres' =>
                    $filtres,

                'periode' =>
                    $periode,

                'totaux' =>
                    $totaux,

                'statistiquesPersonnelles' =>
                    $statistiquesPersonnelles,

                'estAdmin' =>
                    $estAdmin,
            ]
        );
    }


    /*
     * ============================================================
     * NOUVEAU MOUVEMENT
     * ============================================================
     */

    #[Route(
        '/nouveau',
        name: 'new',
        methods: ['GET', 'POST']
    )]
    #[IsGranted(new Expression('is_granted("ROLE_TRESORERIE_SAISIR") or is_granted("ROLE_TRESORERIE_TRANSFERER")'))]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        MouvementTresorerieRepository $repository,
        ComptesOrangeMoney $comptesOrangeMoney
    ): Response {
        $user =
            $this->getUser();


        if (!$user instanceof User) {
            throw $this
                ->createAccessDeniedException(
                    'Utilisateur non authentifié.'
                );
        }


        $estAdmin =
            $this->isGranted(
                'ROLE_ADMIN'
            );


        /*
         * Sans le droit « Trésorerie et soldes », le formulaire ne
         * montre aucun solde et l'enregistrement ramène à la caisse.
         */
        $voirSoldes =
            $this->isGranted(
                'ROLE_TRESORERIE_VOIR'
            );


        $peutSaisir =
            $this->isGranted(
                'ROLE_TRESORERIE_SAISIR'
            );


        $peutTransferer =
            $this->isGranted(
                'ROLE_TRESORERIE_TRANSFERER'
            );


        $retour =
            self::routeApresSaisie(
                $voirSoldes,
                $this->isGranted(
                    'ROLE_PAIEMENT_ENCAISSER'
                ),
                $peutSaisir
            );


        $mouvement =
            new MouvementTresorerie();


        $mouvement->setReference(
            $this->genererReference(
                $repository
            )
        );


        $mouvement->setDateOperation(
            new \DateTimeImmutable()
        );


        /*
         * Type choisi d'avance (bouton « Virement » de la caisse),
         * seulement parmi ceux que la personne peut saisir.
         */
        $typeDemande =
            (string) $request->query->get(
                'type',
                ''
            );

        if (
            \in_array(
                $typeDemande,
                MouvementTresorerieType::typesPermis(
                    $estAdmin,
                    $peutSaisir,
                    $peutTransferer
                ),
                true
            )
        ) {
            $mouvement->setType(
                $typeDemande
            );
        }


        /*
         * Compte source choisi d'avance (bouton « Virer » d'un compte
         * Orange Money), seulement s'il est permis pour ce type.
         */
        $sourceDemandee =
            $request->query->getInt(
                'source'
            );

        if (
            $sourceDemandee > 0
        ) {
            $compteDemande =
                $entityManager->find(
                    CompteTresorerie::class,
                    $sourceDemandee
                );

            if (
                $compteDemande instanceof CompteTresorerie
                &&
                $compteDemande->isActif()
                &&
                \in_array(
                    $mouvement->getType(),
                    MouvementTresorerieType::typesPourCompte(
                        $compteDemande,
                        true,
                        $estAdmin,
                        $peutSaisir,
                        $peutTransferer,
                        $user
                    ),
                    true
                )
            ) {
                $mouvement->setCompteSource(
                    $compteDemande
                );
            }
        }


        /*
         * Agent toujours imposé par la session.
         */
        $mouvement->setAgent(
            $user
        );


        /*
         * Non Admin :
         * jamais confidentiel.
         */
        if (!$estAdmin) {
            $mouvement->setConfidentiel(
                false
            );
        }


        $form =
            $this->createForm(
                MouvementTresorerieType::class,
                $mouvement,
                [
                    'est_admin' =>
                        $estAdmin,

                    'utilisateur' =>
                        $user,

                    'voir_soldes' =>
                        $voirSoldes,

                    'peut_saisir' =>
                        $peutSaisir,

                    'peut_transferer' =>
                        $peutTransferer,

                    /*
                     * Solde, frais à prélever par Orange et part
                     * transférable des comptes Orange Money : avec le
                     * droit de voir les soldes seulement.
                     */
                    'etats_orange' =>
                        $voirSoldes
                            ? $this->etatsOrangeMoney(
                                $entityManager,
                                $comptesOrangeMoney
                            )
                            : [],

                    'taux_orange' =>
                        $comptesOrangeMoney->taux(),
                ]
            );


        $form->handleRequest(
            $request
        );


        if (
            $form->isSubmitted()
        ) {
            /*
             * ====================================================
             * AGENT
             * ====================================================
             *
             * Protection contre modification HTML.
             * ====================================================
             */

            $mouvement->setAgent(
                $user
            );


            /*
             * ====================================================
             * CONFIDENTIALITÉ
             * ====================================================
             */

            if (!$estAdmin) {
                $mouvement->setConfidentiel(
                    false
                );
            }


            /*
             * ====================================================
             * DATE DE L'OPÉRATION
             * ====================================================
             *
             * Seul l'admin peut choisir une date différente
             * de maintenant. Protection contre modification HTML
             * même si le champ n'est pas censé être présent
             * dans le formulaire pour un non-admin.
             * ====================================================
             */

            if (!$estAdmin) {
                $mouvement->setDateOperation(
                    new \DateTimeImmutable()
                );
            }


            /*
             * ====================================================
             * TRANSFERT
             * ====================================================
             *
             * Toujours neutre financièrement.
             * ====================================================
             */

            if (
                $mouvement->getType()
                ===
                MouvementTresorerie::TYPE_TRANSFERT
            ) {
                $mouvement->setCategorie(
                    MouvementTresorerie
                        ::CATEGORIE_TRANSFERT_INTERNE
                );

                $mouvement->setImpactResultat(
                    false
                );
            }


            /*
             * ====================================================
             * CONTRÔLE DES DROITS SUR LES COMPTES
             * ====================================================
             */

            try {
                $this
                    ->verifierMouvementAutorise(
                        $mouvement,
                        $user
                    );
            } catch (
                \LogicException $exception
            ) {
                $this->addFlash(
                    'error',
                    $exception->getMessage()
                );

                return $this
                    ->redirectToRoute(
                        'app_mouvement_tresorerie_new'
                    );
            }


            /*
             * ====================================================
             * FRAIS PRÉLEVÉS PAR ORANGE
             * ====================================================
             *
             * Sortie d'un compte Orange Money (transfert ou
             * décaissement) : Orange débite le fonds de soutien et le
             * frais de retrait. Ils deviennent un mouvement à part sur
             * le compte Orange Money (ComptesOrangeMoney).
             * ====================================================
             */

            $fraisOrange =
                self::fraisOrangeSaisis(
                    $mouvement,
                    $form->has('fraisOrange')
                        ? $form->get('fraisOrange')->getData()
                        : null
                );

            if (
                $fraisOrange > 0
                &&
                $fraisOrange > (int) $mouvement->getMontant()
            ) {
                $form
                    ->get('fraisOrange')
                    ->addError(
                        new FormError(
                            'Les frais prélevés par Orange ne peuvent pas dépasser le montant transféré.'
                        )
                    );
            }


            if (
                $form->isValid()
            ) {
                $prelevements =
                    ComptesOrangeMoney::mouvementsPrelevement(
                        $mouvement,
                        $fraisOrange,
                        $comptesOrangeMoney->fraisAPrelever()[(int) $mouvement->getCompteSource()?->getId()] ?? 0,
                        $user
                    );

                try {
                    $entityManager
                        ->wrapInTransaction(
                            function (
                                EntityManagerInterface $entityManager
                            ) use (
                                $mouvement,
                                $prelevements
                            ): void {
                                /*
                                 * Les mouvements saisis ici
                                 * sont validés immédiatement.
                                 */
                                $mouvement
                                    ->setStatut(
                                        MouvementTresorerie
                                            ::STATUT_VALIDE
                                    );


                                $mouvement
                                    ->setDateValidation(
                                        new \DateTimeImmutable()
                                    );


                                /*
                                 * Modification réelle des soldes.
                                 */
                                $this
                                    ->appliquerMouvementAuxComptes(
                                        $mouvement
                                    );


                                $entityManager
                                    ->persist(
                                        $mouvement
                                    );


                                /*
                                 * Frais débités par Orange avec
                                 * cette sortie : sur le même compte,
                                 * après la sortie elle-même.
                                 */
                                foreach ($prelevements as $prelevement) {
                                    $prelevement
                                        ->setStatut(
                                            MouvementTresorerie
                                                ::STATUT_VALIDE
                                        );

                                    $prelevement
                                        ->setDateValidation(
                                            new \DateTimeImmutable()
                                        );

                                    $this
                                        ->appliquerMouvementAuxComptes(
                                            $prelevement
                                        );

                                    $entityManager
                                        ->persist(
                                            $prelevement
                                        );
                                }


                                $entityManager
                                    ->flush();
                            }
                        );


                    /*
                     * Sans le droit de voir la trésorerie : retour à
                     * la caisse (ou aux dépenses), avec un message
                     * qui ne donne aucun solde.
                     */
                    $this->addFlash(
                        'success',
                        ($voirSoldes
                            ? 'Le mouvement a été enregistré avec succès.'
                            : self::confirmationSansSolde(
                                $mouvement
                            ))
                        . self::confirmationFraisOrange(
                            $prelevements
                        )
                    );


                    return $this
                        ->redirectToRoute(
                            $retour
                        );

                } catch (
                    UniqueConstraintViolationException
                ) {
                    $mouvement
                        ->setReference(
                            $this
                                ->genererReference(
                                    $repository
                                )
                        );


                    $this->addFlash(
                        'error',
                        'La référence existe déjà. Veuillez recommencer.'
                    );

                } catch (
                    \InvalidArgumentException
                    | \LogicException
                    | \DomainException
                    | \RuntimeException
                    $exception
                ) {
                    $this->addFlash(
                        'error',
                        $exception->getMessage()
                    );

                } catch (
                    \Throwable
                ) {
                    $this->addFlash(
                        'error',
                        'Impossible d’enregistrer le mouvement de trésorerie.'
                    );
                }
            } else {
                $this->addFlash(
                    'error',
                    'Veuillez corriger les informations du formulaire.'
                );
            }
        }
        return $this->render(
            'mouvement_tresorerie/new.html.twig',
            [
                'mouvement' =>
                    $mouvement,

                'form' =>
                    $form->createView(),

                'estAdmin' =>
                    $estAdmin,

                'voirSoldes' =>
                    $voirSoldes,

                'peutSaisir' =>
                    $estAdmin || $peutSaisir,

                'peutTransferer' =>
                    $estAdmin || $peutTransferer,

                'retour' =>
                    $retour,
            ]
        );
    }


    /*
     * ============================================================
     * AFFICHAGE D'UN MOUVEMENT
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
    #[IsGranted('ROLE_TRESORERIE_VOIR')]
    public function show(
        MouvementTresorerie $mouvement
    ): Response {
        $user =
            $this->getUser();


        if (!$user instanceof User) {
            throw $this
                ->createAccessDeniedException(
                    'Utilisateur non authentifié.'
                );
        }


        $this
            ->verifierVisibiliteMouvement(
                $mouvement,
                $user
            );


        return $this->render(
            'mouvement_tresorerie/show.html.twig',
            [
                'mouvement' =>
                    $mouvement,

                'estAdmin' =>
                    $this->isGranted(
                        'ROLE_ADMIN'
                    ),
            ]
        );
    }


    /*
     * ============================================================
     * VALIDATION
     * ============================================================
     *
     * ADMIN UNIQUEMENT
     * ============================================================
     */

    #[Route(
        '/{id}/valider',
        name: 'validate',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_TRESORERIE_VOIR')]
    #[IsGranted('ROLE_ADMIN')]
    public function validateMovement(
        Request $request,
        MouvementTresorerie $mouvement,
        MouvementTresorerieService $service
    ): Response {
        if (
            !$this
                ->isCsrfTokenValid(
                    'validate-mouvement-'
                    . $mouvement->getId(),
                    (string)
                    $request
                        ->request
                        ->get('_token')
                )
        ) {
            $this->addFlash(
                'error',
                'Le jeton de sécurité est invalide.'
            );


            return $this
                ->redirectToRoute(
                    'app_mouvement_tresorerie_show',
                    [
                        'id' =>
                            $mouvement
                                ->getId(),
                    ]
                );
        }


        try {
            $service->valider(
                $mouvement
            );


            $this->addFlash(
                'success',
                'Le mouvement a été validé.'
            );

        } catch (
            \InvalidArgumentException
            | \LogicException
            $exception
        ) {
            $this->addFlash(
                'error',
                $exception->getMessage()
            );
        }


        return $this
            ->redirectToRoute(
                'app_mouvement_tresorerie_show',
                [
                    'id' =>
                        $mouvement
                            ->getId(),
                ]
            );
    }


    /*
     * ============================================================
     * VÉRIFICATION ADMIN
     * ============================================================
     *
     * Champ purement déclaratif : aucun impact sur les soldes
     * ni sur le statut du mouvement.
     * ============================================================
     */

    #[Route(
        '/{id}/verifier',
        name: 'toggle_verification',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_TRESORERIE_VOIR')]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleVerification(
        Request $request,
        MouvementTresorerie $mouvement,
        EntityManagerInterface $entityManager
    ): Response {
        $admin = $this->getUser();

        if (!$admin instanceof User) {
            throw $this->createAccessDeniedException(
                'Utilisateur non authentifié.'
            );
        }

        if (
            !$this->isCsrfTokenValid(
                'verifier-mouvement-'
                . $mouvement->getId(),
                (string)
                $request
                    ->request
                    ->get('_token')
            )
        ) {
            $this->addFlash(
                'error',
                'Le jeton de sécurité est invalide.'
            );

            return $this->redirectToRoute(
                'app_mouvement_tresorerie_show',
                ['id' => $mouvement->getId()]
            );
        }

        if ($mouvement->isVerifie()) {
            $mouvement->retirerVerification();

            $this->addFlash(
                'success',
                'La vérification a été retirée.'
            );
        } else {
            $mouvement->marquerCommeVerifie($admin);

            $this->addFlash(
                'success',
                'Le mouvement a été marqué comme vérifié.'
            );
        }

        $entityManager->flush();

        return $this->redirectToRoute(
            'app_mouvement_tresorerie_show',
            ['id' => $mouvement->getId()]
        );
    }


    /*
     * ============================================================
     * ANNULATION
     * ============================================================
     */

    #[Route(
        '/{id}/annuler',
        name: 'cancel',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_TRESORERIE_VOIR')]
    #[IsGranted('ROLE_TRESORERIE_SAISIR')]
    public function cancel(
        Request $request,
        MouvementTresorerie $mouvement,
        MouvementTresorerieService $service,
        MouvementTresorerieRepository $mouvements
    ): Response {
        $user =
            $this->getUser();


        if (!$user instanceof User) {
            throw $this
                ->createAccessDeniedException(
                    'Utilisateur non authentifié.'
                );
        }


        $this
            ->verifierDroitModificationMouvement(
                $mouvement,
                $user
            );


        if (
            !$this
                ->isCsrfTokenValid(
                    'cancel-mouvement-'
                    . $mouvement->getId(),
                    (string)
                    $request
                        ->request
                        ->get('_token')
                )
        ) {
            $this->addFlash(
                'error',
                'Le jeton de sécurité est invalide.'
            );


            return $this
                ->redirectToRoute(
                    'app_mouvement_tresorerie_show',
                    [
                        'id' =>
                            $mouvement
                                ->getId(),
                    ]
                );
        }


        $motif =
            trim(
                (string)
                $request
                    ->request
                    ->get(
                        'motif',
                        ''
                    )
            );


        if (
            $motif === ''
        ) {
            $this->addFlash(
                'error',
                'Le motif d’annulation est obligatoire.'
            );


            return $this
                ->redirectToRoute(
                    'app_mouvement_tresorerie_show',
                    [
                        'id' =>
                            $mouvement
                                ->getId(),
                    ]
                );
        }


        /*
         * ====================================================
         * MOUVEMENT DÉJÀ VALIDÉ
         * ====================================================
         *
         * Contrepasser un mouvement qui a déjà modifié les
         * soldes est réservé à l'administrateur : c'est le
         * mécanisme prévu pour corriger une erreur de saisie
         * après coup (annulation + nouvelle saisie correcte).
         * ====================================================
         */

        if (
            $mouvement->isValide()
            && !$this->isGranted('ROLE_ADMIN')
        ) {
            $this->addFlash(
                'error',
                'Seul un administrateur peut annuler un mouvement déjà validé.'
            );

            return $this
                ->redirectToRoute(
                    'app_mouvement_tresorerie_show',
                    [
                        'id' =>
                            $mouvement
                                ->getId(),
                    ]
                );
        }


        try {
            if ($mouvement->isValide()) {
                $service
                    ->annulerValide(
                        $mouvement,
                        $motif
                    );

                /*
                 * Les frais débités par Orange avec cette sortie
                 * d'un compte Orange Money repartent avec elle.
                 */
                foreach (
                    [
                        ComptesOrangeMoney::SUFFIXE_PASSAGE,
                        ComptesOrangeMoney::SUFFIXE_CHARGE,
                    ]
                    as $suffixe
                ) {
                    $frais =
                        $mouvements->findOneBy([
                            'reference' => $mouvement->getReference() . $suffixe,
                        ]);

                    if (
                        $frais instanceof MouvementTresorerie
                        &&
                        $frais->isValide()
                    ) {
                        $service
                            ->annulerValide(
                                $frais,
                                $motif
                            );
                    }
                }

                $this->addFlash(
                    'success',
                    'Le mouvement a été annulé et les soldes ont été corrigés en conséquence.'
                );
            } else {
                $service
                    ->annulerEnAttente(
                        $mouvement,
                        $motif
                    );


                $this->addFlash(
                    'success',
                    'Le mouvement a été annulé.'
                );
            }

        } catch (
            \InvalidArgumentException
            | \LogicException
            $exception
        ) {
            $this->addFlash(
                'error',
                $exception->getMessage()
            );
        }


        return $this
            ->redirectToRoute(
                'app_mouvement_tresorerie_show',
                [
                    'id' =>
                        $mouvement
                            ->getId(),
                ]
            );
    }


    /*
     * ============================================================
     * UTILISATEUR PEUT VOIR LE COMPTE ?
     * ============================================================
     */

    private function utilisateurPeutVoirCompte(
        CompteTresorerie $compte,
        User $user
    ): bool {
        /*
         * Admin :
         * visibilité totale.
         */
        if (
            $this->isGranted(
                'ROLE_ADMIN'
            )
        ) {
            return true;
        }


        /*
         * Compte personnel :
         * uniquement son propriétaire.
         */
        if (
            $compte->estPersonnel()
        ) {
            return
                $compte
                    ->appartientA(
                        $user
                    );
        }


        /*
         * Orange Money / Wave :
         * comptes partagés.
         */
        if (
            $compte->estPartage()
        ) {
            return true;
        }


        /*
         * Compte administratif :
         * invisible.
         */
        return false;
    }


    /*
     * ============================================================
     * UTILISATEUR PEUT EFFECTUER UN MOUVEMENT ORDINAIRE ?
     * ============================================================
     */

    private function utilisateurPeutFaireMouvementOrdinaire(
        CompteTresorerie $compte,
        User $user
    ): bool {
        /*
         * ========================================================
         * PERSONNEL
         * ========================================================
         *
         * Même ADMIN ne peut pas faire directement
         * un encaissement/décaissement sur la caisse
         * personnelle d'un autre utilisateur.
         * ========================================================
         */

        if (
            $compte->estPersonnel()
        ) {
            return
                $compte
                    ->appartientA(
                        $user
                    );
        }


        /*
         * ========================================================
         * PARTAGÉ
         * ========================================================
         *
         * Orange Money / Wave :
         *
         * utilisables par les personnes autorisées
         * à saisir de la trésorerie.
         * ========================================================
         */

        if (
            $compte->estPartage()
        ) {
            return
                $this->isGranted(
                    'ROLE_TRESORERIE_SAISIR'
                );
        }


        /*
         * ========================================================
         * ADMIN
         * ========================================================
         */

        if (
            $compte->estAdministratif()
        ) {
            return
                $this->isGranted(
                    'ROLE_ADMIN'
                );
        }


        return false;
    }


    /*
     * ============================================================
     * PEUT UTILISER LE COMPTE COMME SOURCE D'UN TRANSFERT ?
     * ============================================================
     */

    private function utilisateurPeutTransfererDepuisCompte(
        CompteTresorerie $compte,
        User $user
    ): bool {
        /*
         * Compte personnel :
         *
         * seul son propriétaire peut faire sortir l'argent.
         *
         * Même Admin ne peut pas retirer directement
         * l'argent d'une caisse personnelle qui ne lui
         * appartient pas.
         */
        if (
            $compte->estPersonnel()
        ) {
            return
                $compte
                    ->appartientA(
                        $user
                    );
        }


        /*
         * Compte partagé :
         *
         * utilisateur avec droit de transfert.
         */
        if (
            $compte->estPartage()
        ) {
            return
                $this->isGranted(
                    'ROLE_TRESORERIE_TRANSFERER'
                );
        }


        /*
         * Compte Admin :
         * Admin uniquement.
         */
        if (
            $compte->estAdministratif()
        ) {
            return
                $this->isGranted(
                    'ROLE_ADMIN'
                );
        }


        return false;
    }


    /*
     * ============================================================
     * PEUT RECEVOIR UN TRANSFERT ?
     * ============================================================
     */

    private function utilisateurPeutTransfererVersCompte(
        CompteTresorerie $compte,
        User $user
    ): bool {
        /*
         * ========================================================
         * PERSONNEL
         * ========================================================
         *
         * Un transfert entrant vers une caisse personnelle est
         * toujours possible, y compris vers la caisse d'un
         * collègue (remise en main propre entre deux agents) : le
         * formulaire (MouvementTresorerieType) propose déjà
         * n'importe quelle caisse personnelle comme destination, et
         * le droit général d'effectuer un transfert a été vérifié
         * plus haut (ROLE_TRESORERIE_TRANSFERER ou ROLE_ADMIN).
         *
         * Exemples :
         *
         * Caisse Administration → Caisse Mariam (approvisionnement)
         * Caisse Mariam → Caisse Ahmed (remise en main propre)
         * ========================================================
         */

        if (
            $compte->estPersonnel()
        ) {
            return true;
        }


        /*
         * ========================================================
         * PARTAGÉ
         * ========================================================
         */

        if (
            $compte->estPartage()
        ) {
            return
                $this->isGranted(
                    'ROLE_TRESORERIE_TRANSFERER'
                )
                ||
                $this->isGranted(
                    'ROLE_ADMIN'
                );
        }


        /*
         * ========================================================
         * ADMIN
         * ========================================================
         *
         * Une caissière peut remettre sa caisse
         * vers un compte administratif si elle possède
         * ROLE_TRESORERIE_TRANSFERER.
         *
         * Exemple :
         *
         * Caisse Mariam
         * → Caisse Administration
         *
         * ou
         *
         * Caisse Mariam
         * → Banque.
         * ========================================================
         */

        if (
            $compte->estAdministratif()
        ) {
            return
                $this->isGranted(
                    'ROLE_TRESORERIE_TRANSFERER'
                )
                ||
                $this->isGranted(
                    'ROLE_ADMIN'
                );
        }


        return false;
    }


    /*
     * ============================================================
     * MOUVEMENT VISIBLE ?
     * ============================================================
     */

    private function mouvementEstVisiblePourUtilisateur(
        MouvementTresorerie $mouvement,
        User $user
    ): bool {
        /*
         * ========================================================
         * CONFIDENTIEL
         * ========================================================
         *
         * Admin uniquement.
         * ========================================================
         */

        if (
            $mouvement->isConfidentiel()
        ) {
            return
                $this->isGranted(
                    'ROLE_ADMIN'
                );
        }


        /*
         * Admin :
         * tout.
         */
        if (
            $this->isGranted(
                'ROLE_ADMIN'
            )
        ) {
            return true;
        }


        $source =
            $mouvement
                ->getCompteSource();


        $destination =
            $mouvement
                ->getCompteDestination();


        /*
         * ========================================================
         * ENCAISSEMENT
         * ========================================================
         */

        if (
            $mouvement->getType()
            ===
            MouvementTresorerie::TYPE_ENCAISSEMENT
        ) {
            return
                $destination !== null
                &&
                $this
                    ->utilisateurPeutVoirCompte(
                        $destination,
                        $user
                    );
        }


        /*
         * ========================================================
         * DÉCAISSEMENT
         * ========================================================
         */

        if (
            $mouvement->getType()
            ===
            MouvementTresorerie::TYPE_DECAISSEMENT
        ) {
            return
                $source !== null
                &&
                $this
                    ->utilisateurPeutVoirCompte(
                        $source,
                        $user
                    );
        }


        /*
         * ========================================================
         * TRANSFERT
         * ========================================================
         *
         * Visible si le mouvement touche :
         *
         * - son compte personnel ;
         * - un compte partagé.
         *
         * Exemple :
         *
         * Caisse Mariam → Administration
         *
         * Mariam voit son transfert.
         *
         * Mais elle ne voit pas les autres mouvements
         * du compte Administration.
         * ========================================================
         */

        if (
            $mouvement->getType()
            ===
            MouvementTresorerie::TYPE_TRANSFERT
        ) {
            $sourceVisible =
                $source !== null
                &&
                $this
                    ->utilisateurPeutVoirCompte(
                        $source,
                        $user
                    );


            $destinationVisible =
                $destination !== null
                &&
                $this
                    ->utilisateurPeutVoirCompte(
                        $destination,
                        $user
                    );


            /*
             * Si l'utilisateur est l'agent du transfert,
             * on lui permet également de revoir son transfert
             * vers un compte Admin.
             */
            $estSonOperation =
                $mouvement
                    ->getAgent()
                === $user;


            return
                $sourceVisible
                ||
                $destinationVisible
                ||
                $estSonOperation;
        }


        return false;
    }


    /*
     * ============================================================
     * VÉRIFIER VISIBILITÉ
     * ============================================================
     */

    private function verifierVisibiliteMouvement(
        MouvementTresorerie $mouvement,
        User $user
    ): void {
        if (
            !$this
                ->mouvementEstVisiblePourUtilisateur(
                    $mouvement,
                    $user
                )
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Vous n’avez pas accès à ce mouvement de trésorerie.'
                );
        }
    }


    /*
     * ============================================================
     * DROIT DE MODIFICATION
     * ============================================================
     */

    private function verifierDroitModificationMouvement(
        MouvementTresorerie $mouvement,
        User $user
    ): void {
        /*
         * Mouvement confidentiel :
         * Admin uniquement.
         */
        if (
            $mouvement->isConfidentiel()
            &&
            !$this->isGranted(
                'ROLE_ADMIN'
            )
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Vous n’avez pas accès à ce mouvement.'
                );
        }


        /*
         * Admin :
         * autorisé pour la gestion administrative.
         */
        if (
            $this->isGranted(
                'ROLE_ADMIN'
            )
        ) {
            return;
        }


        /*
         * Autres utilisateurs :
         * uniquement leurs propres opérations.
         */
        if (
            $mouvement->getAgent()
            !== $user
        ) {
            throw $this
                ->createAccessDeniedException(
                    'Vous ne pouvez agir que sur les mouvements que vous avez enregistrés.'
                );
        }


        $this
            ->verifierVisibiliteMouvement(
                $mouvement,
                $user
            );
    }


    /*
     * ============================================================
     * VÉRIFIER LE MOUVEMENT AVANT ENREGISTREMENT
     * ============================================================
     */

    private function verifierMouvementAutorise(
        MouvementTresorerie $mouvement,
        User $user
    ): void {
        $type =
            $mouvement->getType();


        /*
         * ========================================================
         * CONFIDENTIEL
         * ========================================================
         */

        if (
            $mouvement->isConfidentiel()
            &&
            !$this->isGranted(
                'ROLE_ADMIN'
            )
        ) {
            throw new \LogicException(
                'Seul un administrateur peut enregistrer un mouvement confidentiel.'
            );
        }


        /*
         * ========================================================
         * ENTRÉE OU SORTIE : DROIT DE SAISIE
         * ========================================================
         *
         * Le formulaire s'ouvre aussi avec le seul droit de
         * virement : il ne permet alors que le transfert.
         * ========================================================
         */

        if (
            \in_array(
                $type,
                [
                    MouvementTresorerie::TYPE_ENCAISSEMENT,
                    MouvementTresorerie::TYPE_DECAISSEMENT,
                ],
                true
            )
            &&
            !$this->isGranted(
                'ROLE_TRESORERIE_SAISIR'
            )
            &&
            !$this->isGranted(
                'ROLE_ADMIN'
            )
        ) {
            throw new \LogicException(
                'Vous n’êtes pas autorisé à enregistrer une entrée ou une sortie d’argent.'
            );
        }


        /*
         * ========================================================
         * ENCAISSEMENT
         * ========================================================
         */

        if (
            $type
            ===
            MouvementTresorerie::TYPE_ENCAISSEMENT
        ) {
            $destination =
                $mouvement
                    ->getCompteDestination();


            if (
                $destination === null
            ) {
                throw new \LogicException(
                    'Le compte destination est obligatoire.'
                );
            }


            if (
                !$destination->isActif()
            ) {
                throw new \LogicException(
                    'Le compte destination est désactivé.'
                );
            }


            if (
                !$this
                    ->utilisateurPeutFaireMouvementOrdinaire(
                        $destination,
                        $user
                    )
            ) {
                throw new \LogicException(
                    sprintf(
                        'Vous n’êtes pas autorisé à enregistrer un encaissement sur le compte "%s".',
                        $destination->getNom()
                    )
                );
            }


            /*
             * Nettoyage.
             */
            $mouvement
                ->setCompteSource(
                    null
                );


            return;
        }


        /*
         * ========================================================
         * DÉCAISSEMENT
         * ========================================================
         */

        if (
            $type
            ===
            MouvementTresorerie::TYPE_DECAISSEMENT
        ) {
            $source =
                $mouvement
                    ->getCompteSource();


            if (
                $source === null
            ) {
                throw new \LogicException(
                    'Le compte source est obligatoire.'
                );
            }


            if (
                !$source->isActif()
            ) {
                throw new \LogicException(
                    'Le compte source est désactivé.'
                );
            }


            if (
                !$this
                    ->utilisateurPeutFaireMouvementOrdinaire(
                        $source,
                        $user
                    )
            ) {
                throw new \LogicException(
                    sprintf(
                        'Vous n’êtes pas autorisé à effectuer un décaissement depuis le compte "%s".',
                        $source->getNom()
                    )
                );
            }


            /*
             * Vérification du solde.
             */
            if (
                !$source
                    ->peutEtreDebite(
                        (int)
                        $mouvement
                            ->getMontant()
                    )
            ) {
                throw new \LogicException(
                    sprintf(
                        'Solde insuffisant sur le compte "%s".',
                        $source->getNom()
                    )
                );
            }


            /*
             * Nettoyage.
             */
            $mouvement
                ->setCompteDestination(
                    null
                );


            return;
        }


        /*
         * ========================================================
         * TRANSFERT
         * ========================================================
         */

        if (
            $type
            ===
            MouvementTresorerie::TYPE_TRANSFERT
        ) {
            if (
                !$this->isGranted(
                    'ROLE_TRESORERIE_TRANSFERER'
                )
                &&
                !$this->isGranted(
                    'ROLE_ADMIN'
                )
            ) {
                throw new \LogicException(
                    'Vous n’êtes pas autorisé à effectuer un transfert.'
                );
            }


            $source =
                $mouvement
                    ->getCompteSource();


            $destination =
                $mouvement
                    ->getCompteDestination();


            if (
                $source === null
                ||
                $destination === null
            ) {
                throw new \LogicException(
                    'Les comptes source et destination sont obligatoires.'
                );
            }


            if (
                $source ===
                $destination
            ) {
                throw new \LogicException(
                    'Les comptes source et destination doivent être différents.'
                );
            }


            if (
                !$source->isActif()
                ||
                !$destination->isActif()
            ) {
                throw new \LogicException(
                    'Les comptes utilisés pour le transfert doivent être actifs.'
                );
            }


            /*
             * ====================================================
             * SOURCE
             * ====================================================
             *
             * Règle essentielle :
             *
             * personne ne peut faire sortir de l'argent
             * d'une caisse personnelle qui ne lui appartient pas.
             * ====================================================
             */

            if (
                !$this
                    ->utilisateurPeutTransfererDepuisCompte(
                        $source,
                        $user
                    )
            ) {
                throw new \LogicException(
                    sprintf(
                        'Vous n’êtes pas autorisé à transférer de l’argent depuis le compte "%s".',
                        $source->getNom()
                    )
                );
            }


            /*
             * ====================================================
             * DESTINATION
             * ====================================================
             */

            if (
                !$this
                    ->utilisateurPeutTransfererVersCompte(
                        $destination,
                        $user
                    )
            ) {
                throw new \LogicException(
                    sprintf(
                        'Vous n’êtes pas autorisé à transférer de l’argent vers le compte "%s".',
                        $destination->getNom()
                    )
                );
            }


            /*
             * ====================================================
             * SOLDE SOURCE
             * ====================================================
             */

            if (
                !$source
                    ->peutEtreDebite(
                        (int)
                        $mouvement
                            ->getMontant()
                    )
            ) {
                throw new \LogicException(
                    sprintf(
                        'Solde insuffisant sur le compte "%s".',
                        $source->getNom()
                    )
                );
            }


            /*
             * ====================================================
             * TRANSFERT = NEUTRE
             * ====================================================
             */

            $mouvement
                ->setCategorie(
                    MouvementTresorerie
                        ::CATEGORIE_TRANSFERT_INTERNE
                );


            $mouvement
                ->setImpactResultat(
                    false
                );


            return;
        }


        throw new \LogicException(
            'Ce type de mouvement n’est pas autorisé.'
        );
    }


    /*
     * ============================================================
     * APPLIQUER AUX SOLDES
     * ============================================================
     */

    private function appliquerMouvementAuxComptes(
        MouvementTresorerie $mouvement
    ): void {
        $montant =
            (int)
            $mouvement
                ->getMontant();


        if (
            $montant <= 0
        ) {
            throw new \LogicException(
                'Le montant doit être supérieur à zéro.'
            );
        }


        /*
         * ========================================================
         * ENCAISSEMENT
         * ========================================================
         */

        if (
            $mouvement->getType()
            ===
            MouvementTresorerie::TYPE_ENCAISSEMENT
        ) {
            $destination =
                $mouvement
                    ->getCompteDestination();


            if (
                $destination === null
            ) {
                throw new \LogicException(
                    'Le compte destination est obligatoire.'
                );
            }


            $destination
                ->crediter(
                    $montant
                );


            return;
        }


        /*
         * ========================================================
         * DÉCAISSEMENT
         * ========================================================
         */

        if (
            $mouvement->getType()
            ===
            MouvementTresorerie::TYPE_DECAISSEMENT
        ) {
            $source =
                $mouvement
                    ->getCompteSource();


            if (
                $source === null
            ) {
                throw new \LogicException(
                    'Le compte source est obligatoire.'
                );
            }


            $source
                ->debiter(
                    $montant
                );


            return;
        }


        /*
         * ========================================================
         * TRANSFERT
         * ========================================================
         */

        if (
            $mouvement->getType()
            ===
            MouvementTresorerie::TYPE_TRANSFERT
        ) {
            $source =
                $mouvement
                    ->getCompteSource();


            $destination =
                $mouvement
                    ->getCompteDestination();


            if (
                $source === null
                ||
                $destination === null
            ) {
                throw new \LogicException(
                    'Les comptes source et destination sont obligatoires.'
                );
            }


            if (
                $source ===
                $destination
            ) {
                throw new \LogicException(
                    'Les comptes source et destination doivent être différents.'
                );
            }


            $source
                ->debiter(
                    $montant
                );


            $destination
                ->crediter(
                    $montant
                );


            return;
        }


        throw new \LogicException(
            'Le type de mouvement est invalide.'
        );
    }


    /*
     * ============================================================
     * FRAIS PRÉLEVÉS PAR ORANGE
     * ============================================================
     */

    /**
     * Frais saisis sur la ligne « Fonds de soutien et frais de retrait
     * prélevés par Orange » : comptés seulement quand l'argent sort d'un
     * compte Orange Money (transfert ou décaissement), jamais négatifs.
     */
    public static function fraisOrangeSaisis(
        MouvementTresorerie $mouvement,
        mixed $saisie
    ): int {
        $source =
            $mouvement->getCompteSource();

        if (
            $source === null
            ||
            !$source->estOrangeMoney()
            ||
            !\in_array(
                $mouvement->getType(),
                [
                    MouvementTresorerie::TYPE_TRANSFERT,
                    MouvementTresorerie::TYPE_DECAISSEMENT,
                ],
                true
            )
        ) {
            return 0;
        }

        $chiffres =
            preg_replace(
                '/\D/',
                '',
                (string) $saisie
            ) ?? '';

        return $chiffres === ''
            ? 0
            : (int) $chiffres;
    }


    /**
     * Fin du message de confirmation : les frais débités par Orange
     * (le montant saisi, jamais un solde).
     *
     * @param list<MouvementTresorerie> $prelevements
     */
    public static function confirmationFraisOrange(
        array $prelevements
    ): string {
        $total =
            array_sum(
                array_map(
                    static fn (MouvementTresorerie $m): int => (int) $m->getMontant(),
                    $prelevements
                )
            );

        return $total > 0
            ? sprintf(
                ' Frais prélevés par Orange : %s F.',
                number_format(
                    $total,
                    0,
                    ',',
                    ' '
                )
            )
            : '';
    }


    /**
     * @return array<int, array{solde: int, a_prelever: int, transferable: int}>
     */
    private function etatsOrangeMoney(
        EntityManagerInterface $entityManager,
        ComptesOrangeMoney $comptesOrangeMoney
    ): array {
        $etats = [];

        foreach (
            $entityManager
                ->getRepository(CompteTresorerie::class)
                ->findBy([
                    'type' => CompteTresorerie::TYPE_ORANGE_MONEY,
                    'actif' => true,
                ])
            as $compte
        ) {
            $etats[(int) $compte->getId()] =
                $comptesOrangeMoney->etat(
                    $compte
                );
        }

        return array_filter($etats);
    }


    /*
     * ============================================================
     * APRÈS LA SAISIE
     * ============================================================
     *
     * Avec le droit de voir la trésorerie : la liste des
     * mouvements. Sans : une page que la personne ouvre, la caisse
     * d'abord, puis les dépenses, sinon l'accueil.
     * ============================================================
     */

    public static function routeApresSaisie(
        bool $voirTresorerie,
        bool $encaisser,
        bool $saisir
    ): string {
        return match (true) {
            $voirTresorerie => 'app_mouvement_tresorerie_index',
            $encaisser => 'app_caisse_index',
            $saisir => 'app_finances_depenses',
            default => 'app_home',
        };
    }


    /**
     * Message de confirmation pour qui ne voit pas la trésorerie :
     * le montant saisi et les comptes choisis, jamais un solde.
     */
    public static function confirmationSansSolde(
        MouvementTresorerie $mouvement
    ): string {
        $montant =
            number_format(
                $mouvement->getMontant(),
                0,
                ',',
                ' '
            );

        $nom =
            static fn (?CompteTresorerie $compte): string => $compte?->getNom() ?? '?';

        return match ($mouvement->getType()) {
            MouvementTresorerie::TYPE_TRANSFERT => sprintf(
                'Virement de %s F de « %s » vers « %s » enregistré (%s).',
                $montant,
                $nom($mouvement->getCompteSource()),
                $nom($mouvement->getCompteDestination()),
                $mouvement->getReference()
            ),
            MouvementTresorerie::TYPE_ENCAISSEMENT => sprintf(
                'Entrée de %s F sur « %s » enregistrée (%s).',
                $montant,
                $nom($mouvement->getCompteDestination()),
                $mouvement->getReference()
            ),
            default => sprintf(
                'Sortie de %s F de « %s » enregistrée (%s).',
                $montant,
                $nom($mouvement->getCompteSource()),
                $mouvement->getReference()
            ),
        };
    }


    /*
     * ============================================================
     * GÉNÉRER RÉFÉRENCE
     * ============================================================
     */

    private function genererReference(
        MouvementTresorerieRepository $repository
    ): string {
        do {
            $reference =
                sprintf(
                    'MVT-%s-%04d',
                    (
                        new \DateTimeImmutable()
                    )->format(
                        'YmdHis'
                    ),
                    random_int(
                        1,
                        9999
                    )
                );
        } while (
            $repository
                ->referenceExiste(
                    $reference
                )
        );


        return $reference;
    }
}