<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\Paiements;
use App\Entity\User;
use App\Entity\MouvementTresorerie;
use App\Form\CommandesType;
use App\Form\PaiementsType;
use App\Repository\CommandesRepository;
use App\Repository\ClientsRepository;
use App\Repository\CommandeDetailFichierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\CommandeDetailFinition;
use App\Entity\ProduitConfigurationFinition;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use App\Entity\CommandesDetails;
use App\Service\NotificationService;
use App\Service\StockService;
use App\Repository\FacturesRepository;
use App\Repository\ParametresPaiementRepository;
use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/commandes')]
final class CommandesController extends AbstractController
{
    private const CLES_FILTRES_COMMANDES = [
        'q',
        'client',
        'statut',
        'etat',
        'paiement',
        'date_debut',
        'date_fin',
        'montant_min',
        'montant_max',
        'affichage',
        'tri',
    ];

    private const FILTRES_COMMANDES_PAR_DEFAUT = [
        'q' => '',
        'client' => '',
        'statut' => '',
        'etat' => '',
        'paiement' => '',
        'date_debut' => '',
        'date_fin' => '',
        'montant_min' => '',
        'montant_max' => '',
        'affichage' => 'actives',
        'tri' => 'recent',
    ];

    #[Route('/', name: 'app_commandes_index', methods: ['GET'])]
    public function index(
        Request $request,
        CommandesRepository $commandesRepository,
        ClientsRepository $clientsRepository
    ): Response {
        $session = $request->getSession();

        /*
     * ============================================================
     * RECHERCHE GARDÉE EN SESSION
     * ============================================================
     *
     * Tant qu'aucune recherche n'a été explicitement réinitialisée
     * (bouton "Réinitialiser", ?reset=1), on retrouve les derniers
     * filtres appliqués même en revenant sur la liste sans
     * paramètre d'URL (ex. via le menu).
     */
        if ($request->query->getBoolean('reset')) {
            $session->remove('commandes_filtres');
        }

        $requeteContientUnFiltre = false;

        foreach (self::CLES_FILTRES_COMMANDES as $cle) {
            if ($request->query->get($cle) !== null) {
                $requeteContientUnFiltre = true;

                break;
            }
        }

        if ($requeteContientUnFiltre) {
            $filtres = [
                'q' => trim((string) $request->query->get('q', '')),
                'client' => (string) $request->query->get('client', ''),
                'statut' => (string) $request->query->get('statut', ''),
                'etat' => (string) $request->query->get('etat', ''),
                'paiement' => (string) $request->query->get('paiement', ''),
                'date_debut' => (string) $request->query->get('date_debut', ''),
                'date_fin' => (string) $request->query->get('date_fin', ''),
                'montant_min' => (string) $request->query->get('montant_min', ''),
                'montant_max' => (string) $request->query->get('montant_max', ''),
                'affichage' => (string) $request->query->get(
                    'affichage',
                    'actives'
                ),
                'tri' => (string) $request->query->get('tri', 'recent'),
            ];

            $session->set('commandes_filtres', $filtres);
        } else {
            $filtres = $session->get(
                'commandes_filtres',
                self::FILTRES_COMMANDES_PAR_DEFAUT
            );
        }

        /*
         * Sans filtre, le repository affiche uniquement les commandes
         * dont le paiement est en attente OU les travaux sont en cours.
         */
        $commandes = $commandesRepository->rechercherPourIndex($filtres);

        $totalPaye = 0;
        $totalReste = 0;
        $totalCommandes = 0;

        foreach ($commandes as $commande) {
            /*
         * Une commande entièrement annulée ne doit pas fausser les
         * totaux affichés en haut de la liste (même règle que sur
         * la fiche client).
         */
            if ($commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $total = (int) ($commande->getTotalTtc() ?? 0);

            $paye = 0;

            foreach ($commande->getPaiements() as $paiement) {
                $paye += (int) $paiement->getMontant();
            }

            $reste = max(
                0,
                $total - $paye
            );

            $totalCommandes += $total;
            $totalPaye += $paye;
            $totalReste += $reste;
        }

        return $this->render('commandes/index.html.twig', [
            'commandes' => $commandes,
            'clients' => $clientsRepository->findBy([], [
                'nom' => 'ASC',
            ]),
            'filtres' => $filtres,
            'totalCommandes' => $totalCommandes,
            'totalPaye' => $totalPaye,
            'totalReste' => $totalReste,
        ]);
    }


    #[Route(
        '/new',
        name: 'app_commandes_new',
        methods: ['GET', 'POST']
    )]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        CommandeDetailFichierRepository $fichierRepository,
        StockService $stockService,
        CommandesRepository $commandesRepository,
        NotificationService $notificationService
    ): Response {
        $commande = new Commandes();

        $form = $this->createForm(
            CommandesType::class,
            $commande
        );

        /*
     * ============================================================
     * RESTAURATION D'UN BROUILLON
     * ============================================================
     *
     * Pré-remplit le formulaire à partir d'une saisie sauvegardée
     * automatiquement en session (voir BrouillonSaisieController),
     * sans jamais déclencher l'enregistrement : on affiche juste le
     * formulaire pré-rempli, l'agent doit re-soumettre lui-même.
     */
        if (
            $request->isMethod('GET')
            && $request->query->get('restaurer') === '1'
        ) {
            $brouillon = $request->getSession()->get('brouillon_commande');

            if (is_array($brouillon) && !empty($brouillon['champs'])) {
                $form->submit($brouillon['champs'], false);
            }

            return $this->render(
                'commandes/new.html.twig',
                [
                    'commande' => $commande,
                    'form' => $form,
                ]
            );
        }

        $form->handleRequest($request);

        /*
     * ============================================================
     * PREMIÈRE PHASE :
     * VALIDATION / SYNCHRONISATION / CONTRÔLE STOCK
     * ============================================================
     */
        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            try {
                $this->synchroniserDetailsEtFinitions(
                    $commande,
                    $form,
                    $entityManager
                );

                $this->rattacherFichiers(
                    $commande,
                    $fichierRepository
                );

                /*
             * Contrôle du stock uniquement si
             * la commande est validée.
             */
            } catch (
                \DomainException |
                \RuntimeException $exception
            ) {
                $form->addError(
                    new FormError(
                        $exception->getMessage()
                    )
                );
            }
        }

        /*
     * ============================================================
     * DEUXIÈME PHASE :
     * ENREGISTREMENT
     * ============================================================
     */
        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {
            try {
                $maintenant =
                    new \DateTimeImmutable();

                $commande->setDateCommande(
                    $maintenant
                );

                $commande->setDateLivraison(
                    $this->ajouterHeuresOuvrees(
                        $maintenant,
                        48
                    )
                );

                $utilisateur =
                    $this->getUser();

                if (
                    !$utilisateur
                        instanceof User
                ) {
                    throw $this
                        ->createAccessDeniedException(
                            'Vous devez être connecté pour enregistrer une commande.'
                        );
                }

                $commande->setAgents(
                    $utilisateur
                );

                /*
             * ====================================================
             * PROTECTION CONTRE LES DOUBLONS
             * ====================================================
             *
             * Un double clic ou une double soumission du
             * formulaire peut créer deux commandes identiques.
             * On refuse l'enregistrement si le même agent a déjà
             * enregistré, il y a moins de 30 secondes, une
             * commande pour le même client avec le même montant.
             * ====================================================
             */
                if ($commande->getClients() !== null) {
                    $doublon = $commandesRepository
                        ->trouverDoublonRecent(
                            $utilisateur,
                            $commande->getClients(),
                            $commande->getTotalTtc(),
                            $maintenant->modify('-30 seconds')
                        );

                    if ($doublon !== null) {
                        /*
                     * ================================================
                     * JOURNAL D'ACTIVITÉ
                     * ================================================
                     *
                     * La création est refusée avant tout persist, donc
                     * l'audit automatique (AuditSubscriber) ne voit
                     * jamais passer cette tentative. On la trace donc
                     * explicitement pour garder une trace de qui a
                     * tenté d'enregistrer un doublon, quand et sur
                     * quelle commande d'origine.
                     */
                        $journal = new \App\Entity\JournalActivite();
                        $journal
                            ->setEntite('Commandes')
                            ->setEntiteId($doublon->getId())
                            ->setAction(\App\Entity\JournalActivite::ACTION_DOUBLON_BLOQUE)
                            ->setDonneesApres([
                                'commandeOrigineId' => $doublon->getId(),
                                'commandeOrigineNumero' => $doublon->getNumero(),
                                'clientId' => $commande->getClients()?->getId(),
                                'totalTtc' => $commande->getTotalTtc(),
                            ])
                            ->setUtilisateur($utilisateur);

                        $entityManager->persist($journal);
                        $entityManager->flush();

                        $this->addFlash(
                            'warning',
                            sprintf(
                                'Cette commande semble déjà avoir été enregistrée à l’instant (commande %s). Pour éviter un doublon, elle n’a pas été enregistrée une seconde fois.',
                                $doublon->getNumero()
                                    ?? ('CMD-' . $doublon->getId())
                            )
                        );

                        return $this->redirectToRoute(
                            'app_commandes_show',
                            ['id' => $doublon->getId()],
                            Response::HTTP_SEE_OTHER
                        );
                    }
                }

                /*
             * ====================================================
             * ROUTAGE MÉTIER
             * ====================================================
             */
                if (
                    $this->commandeEstValidee(
                        $commande
                    )
                ) {
                    $this->preparerCircuitCommande(
                        $commande
                    );
                }

                /*
             * ====================================================
             * PREMIER PERSIST
             * ====================================================
             *
             * Nécessaire pour que les détails de commande
             * soient gérés par Doctrine avant création des
             * réservations.
             */
                $entityManager->persist(
                    $commande
                );

                /*
             * ====================================================
             * RÉSERVATION DU STOCK
             * ====================================================
             *
             * Aucune réservation pour un brouillon.
             */
                if (
                    $this->commandeEstValidee(
                        $commande
                    )
                ) {
                    $stockService
                        ->reserverPourCommande(
                            $commande
                        );
                }

                /*
             * Commande + détails + réservations
             * sont enregistrés ensemble.
             */
                $entityManager->flush();

                /*
             * ====================================================
             * NUMÉRO DE COMMANDE
             * ====================================================
             */
                $commande->setNumero(
                    sprintf(
                        'CMD-%06d-%s',
                        $commande->getId(),
                        $maintenant->format(
                            'm-Y'
                        )
                    )
                );

                $notificationService->notifierRoles(
                    ['ROLE_ADMIN'],
                    sprintf(
                        'Nouvelle commande %s créée par %s.',
                        $commande->getNumero(),
                        $commande->getAgents()?->getUsername() ?? 'un agent'
                    ),
                    'app_commandes_show',
                    ['id' => $commande->getId()],
                    $this->getUser()
                );

                /*
             * ====================================================
             * ALERTE PRÉPRESSE
             * ====================================================
             *
             * Prévient les infographistes (et admins) dès qu'une
             * commande validée contient au moins une ligne nécessitant
             * un contrôle prépresse (voir CommandesDetails::isPrePresseNecessaire(),
             * vrai par défaut pour toute ligne Produit).
             */
                if ($this->commandeEstValidee($commande)) {
                    foreach ($commande->getCommandesDetails() as $detailCommande) {
                        if ($detailCommande->isPrePresseNecessaire()) {
                            $notificationService->notifierRoles(
                                ['ROLE_ADMIN', 'ROLE_GRAPHISTE'],
                                sprintf(
                                    'Nouvelle tâche prépresse : commande %s.',
                                    $commande->getNumero()
                                ),
                                'app_controle_pre_presse_index',
                                [],
                                $this->getUser()
                            );

                            break;
                        }
                    }
                }

                $entityManager->flush();

                $request->getSession()->remove('brouillon_commande');

                $this->addFlash(
                    'success',
                    sprintf(
                        'La commande %s a été enregistrée avec succès.',
                        $commande->getNumero()
                    )
                );

                return $this->redirectToRoute(
                    'app_commandes_show',
                    [
                        'id' =>
                        $commande->getId(),
                    ]
                );
            } catch (
                \DomainException |
                \RuntimeException $exception
            ) {
                /*
             * Si la réservation échoue à ce stade,
             * aucun enregistrement ne doit continuer.
             */
                $form->addError(
                    new FormError(
                        $exception->getMessage()
                    )
                );
            }
        }

        return $this->render(
            'commandes/new.html.twig',
            [
                'commande' => $commande,
                'form' => $form,
            ]
        );
    }

    #[Route('/{id}', name: 'app_commandes_show', methods: ['GET'])]
    public function show(Commandes $commande): Response
    {
        return $this->render('commandes/show.html.twig', [
            'commande' => $commande,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'app_commandes_edit',
        methods: ['GET', 'POST']
    )]
    public function edit(
        Request $request,
        Commandes $commande,
        EntityManagerInterface $entityManager,
        CommandeDetailFichierRepository $fichierRepository,
        StockService $stockService
    ): Response {

        /*
     * ============================================================
     * UTILISATEUR
     * ============================================================
     */
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté pour modifier une commande.'
            );
        }


        /*
     * ============================================================
     * COMMANDE ANNULÉE
     * ============================================================
     *
     * Une commande annulée n'a plus lieu d'être modifiée, même par
     * un administrateur : ses lignes ne sont plus "en circuit"
     * (elles sont toutes PRODUCTION_ANNULEE), donc le verrouillage
     * ci-dessous ne suffirait pas seul à la protéger.
     */
        if ($commande->getStatutTravaux() === 'annulee') {

            $this->addFlash(
                'error',
                'Cette commande est annulée et ne peut plus être modifiée.'
            );

            return $this->redirectToRoute(
                'app_commandes_show',
                [
                    'id' =>
                    $commande->getId(),
                ],
                Response::HTTP_SEE_OTHER
            );
        }


        /*
     * ============================================================
     * ÉTAT AVANT MODIFICATION
     * ============================================================
     */

        $commandeEtaitValidee =
            $this->commandeEstValidee(
                $commande
            );


        $circuitDejaCommence =
            $this->commandeACommenceSonCircuit(
                $commande
            );


        /*
     * ============================================================
     * VERROUILLAGE APRÈS DÉMARRAGE PRODUCTION / LIVRAISON
     * ============================================================
     *
     * RÈGLE :
     *
     * dès que la commande a commencé son circuit,
     * seul ROLE_ADMIN peut encore accéder à l'édition.
     */
        if (
            $circuitDejaCommence
            && !$this->isGranted('ROLE_ADMIN')
        ) {

            $this->addFlash(
                'warning',
                'Cette commande est verrouillée car la production ou la livraison a déjà commencé. '
            );

            return $this->redirectToRoute(
                'app_commandes_show',
                [
                    'id' =>
                    $commande->getId(),
                ],
                Response::HTTP_SEE_OTHER
            );
        }


        /*
     * ============================================================
     * EMPREINTE DES LIGNES AVANT MODIFICATION
     * ============================================================
     */
        $empreinteDetailsAvant =
            $this->creerEmpreinteDetails(
                $commande
            );


        /*
     * ============================================================
     * FORMULAIRE
     * ============================================================
     */
        $form =
            $this->createForm(
                CommandesType::class,
                $commande
            );


        $form->handleRequest(
            $request
        );


        /*
     * ============================================================
     * PREMIÈRE PHASE :
     * SYNCHRONISATION / CONTRÔLES
     * ============================================================
     */
        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {

            try {

                /*
             * ========================================================
             * EMPREINTE APRÈS SAISIE
             * ========================================================
             */
                $empreinteDetailsApres =
                    $this->creerEmpreinteDetails(
                        $commande
                    );


                /*
             * ========================================================
             * CIRCUIT DÉJÀ COMMENCÉ
             * ========================================================
             *
             * Un admin peut modifier les lignes même après le
             * démarrage de la production/livraison ; un utilisateur
             * non-admin reste bloqué sur les changements structurels.
             */
                if ($circuitDejaCommence && !$this->isGranted('ROLE_ADMIN')) {

                    $this
                        ->verifierModificationStructurelleAutorisee(
                            $commande
                        );


                    if (
                        $empreinteDetailsAvant
                        !== $empreinteDetailsApres
                    ) {

                        /*
                     * Si tu veux que l'admin puisse modifier AUSSI
                     * les lignes après démarrage production,
                     * supprime ce bloc.
                     *
                     * Dans la version actuelle :
                     * l'admin peut entrer dans l'édition,
                     * mais les changements structurels restent protégés.
                     */
                        throw new \DomainException(
                            'Les lignes de cette commande ne peuvent plus être modifiées car la production ou la livraison a déjà commencé.'
                        );
                    }
                }


                /*
             * ========================================================
             * SYNCHRONISATION DES DÉTAILS / FINITIONS
             * ========================================================
             */
                $this
                    ->synchroniserDetailsEtFinitions(
                        $commande,
                        $form,
                        $entityManager
                    );


                /*
             * ========================================================
             * FICHIERS
             * ========================================================
             */
                $this
                    ->rattacherFichiers(
                        $commande,
                        $fichierRepository
                    );
            } catch (
                \DomainException
                | \RuntimeException
                | \LogicException $exception
            ) {

                $form->addError(
                    new FormError(
                        $exception->getMessage()
                    )
                );
            }
        }


        /*
     * ============================================================
     * DEUXIÈME PHASE :
     * ENREGISTREMENT
     * ============================================================
     */
        if (
            $form->isSubmitted()
            && $form->isValid()
        ) {

            try {

                $commandeEstValideeMaintenant =
                    $this->commandeEstValidee(
                        $commande
                    );


                /*
             * ========================================================
             * CAS 1 :
             * BROUILLON -> VALIDÉE
             * ========================================================
             */
                if (
                    !$commandeEtaitValidee
                    && $commandeEstValideeMaintenant
                ) {

                    /*
                 * Préparation initiale du circuit.
                 */
                    $this
                        ->preparerCircuitCommande(
                            $commande
                        );


                    /*
                 * Réservation du stock.
                 */
                    $stockService
                        ->reserverPourCommande(
                            $commande
                        );
                }


                /*
             * ========================================================
             * CAS 2 :
             * VALIDÉE -> VALIDÉE
             * ========================================================
             */ elseif (
                    $commandeEtaitValidee
                    && $commandeEstValideeMaintenant
                ) {

                    /*
                 * Tant que le circuit n'a pas commencé,
                 * les réservations peuvent être recalculées.
                 */
                    if (!$circuitDejaCommence) {

                        $stockService
                            ->reserverPourCommande(
                                $commande
                            );
                    }

                    /*
                 * Si le circuit a déjà commencé :
                 *
                 * - pas de preparerCircuitCommande()
                 * - pas de recréation des réservations
                 * - pas de réinitialisation des statuts
                 */
                }


                /*
             * ========================================================
             * CAS 3 :
             * VALIDÉE -> BROUILLON
             * ========================================================
             */ elseif (
                    $commandeEtaitValidee
                    && !$commandeEstValideeMaintenant
                ) {

                    if ($circuitDejaCommence) {

                        throw new \DomainException(
                            'Cette commande a déjà commencé son circuit de production ou de livraison. Elle ne peut plus être repassée en brouillon.'
                        );
                    }


                    /*
                 * Libération des réservations.
                 */
                    $stockService
                        ->libererReservationsCommande(
                            $commande
                        );
                }


                /*
             * ========================================================
             * CAS 4 :
             * BROUILLON -> BROUILLON
             * ========================================================
             *
             * Aucun traitement particulier.
             */


                /*
             * ========================================================
             * ENREGISTREMENT
             * ========================================================
             */
                $commande
                    ->setModifieLe(new \DateTimeImmutable())
                    ->setModifiePar($user);

                $entityManager->flush();


                $this->addFlash(
                    'success',
                    'La commande a été modifiée avec succès.'
                );


                return $this->redirectToRoute(
                    'app_commandes_show',
                    [
                        'id' =>
                        $commande->getId(),
                    ],
                    Response::HTTP_SEE_OTHER
                );
            } catch (
                \DomainException
                | \RuntimeException
                | \LogicException $exception
            ) {

                $form->addError(
                    new FormError(
                        $exception->getMessage()
                    )
                );
            }
        }


        /*
     * ============================================================
     * AFFICHAGE
     * ============================================================
     */
        return $this->render(
            'commandes/edit.html.twig',
            [
                'commande' =>
                $commande,

                'form' =>
                $form,

                'circuitDejaCommence' =>
                $circuitDejaCommence,
            ]
        );
    }

    #[Route('/{id}', name: 'app_commandes_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Commandes $commande, EntityManagerInterface $entityManager): Response
    {
        /*
         * Suppression douce (Commandes::$deleted) plutôt qu'un
         * remove() en base : une commande a en général déjà des
         * paiements, réservations de stock, entrées de journal
         * d'activité... liés — un remove() direct échouerait sur les
         * contraintes de clé étrangère ou effacerait des données
         * qu'on veut garder pour l'historique. Le champ deleted est
         * déjà respecté par les requêtes de statistiques
         * (CommandesRepository) ; rechercherPourIndex() est corrigée
         * dans le même correctif pour l'exclure de la liste.
         */
        if ($this->isCsrfTokenValid('delete' . $commande->getId(), $request->getPayload()->getString('_token'))) {
            $commande->setDeleted(true);
            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'La commande %s a été supprimée.',
                    $commande->getNumero()
                )
            );
        }

        return $this->redirectToRoute('app_commandes_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * Annule une commande : tant que la production/livraison n'a pas
     * commencé, n'importe quel utilisateur ROLE_COMMANDE peut le
     * faire ; une fois le circuit commencé (même terminé/livré),
     * seul un administrateur le peut encore.
     *
     * Choix assumé : aucune reprise automatique du stock déjà
     * consommé, des factures ou des paiements existants — comme pour
     * l'annulation d'un ordre de production déjà terminé, on se
     * contente de tracer le fait et de prévenir l'utilisateur ;
     * les régularisations éventuelles restent manuelles.
     */
    #[Route('/{id}/annuler', name: 'app_commandes_annuler', methods: ['POST'])]
    public function annuler(Request $request, Commandes $commande, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour annuler une commande.');
        }

        if (!$this->isCsrfTokenValid('annuler-commande-' . $commande->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
        }

        if ($commande->getStatutTravaux() === 'annulee') {
            $this->addFlash('warning', 'Cette commande est déjà annulée.');

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
        }

        if ($this->commandeACommenceSonCircuit($commande) && !$this->isGranted('ROLE_ADMIN')) {
            $this->addFlash(
                'error',
                'Cette commande a déjà commencé sa production ou sa livraison. Seul un administrateur peut encore l’annuler.'
            );

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
        }

        $stockDejaConsomme = false;

        foreach ($commande->getCommandesDetails() as $detail) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            if (
                in_array(
                    $detail->getStatutProduction(),
                    [
                        CommandesDetails::PRODUCTION_TERMINEE,
                        CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                        CommandesDetails::PRODUCTION_EN_LIVRAISON,
                        CommandesDetails::PRODUCTION_LIVREE,
                    ],
                    true
                )
            ) {
                $stockDejaConsomme = true;
            }

            $detail->setStatutProductionAvantAnnulation($detail->getStatutProduction());
            $detail->setStatutProduction(CommandesDetails::PRODUCTION_ANNULEE);
        }

        $note = sprintf(
            '[Commande annulée le %s par %s]',
            (new \DateTimeImmutable())->format('d/m/Y H:i'),
            $user->getUserIdentifier()
        );

        $observationExistante = $commande->getObservation();
        $commande->setObservation(
            $observationExistante !== null && trim($observationExistante) !== ''
                ? $observationExistante . "\n\n" . $note
                : $note
        );

        $entityManager->flush();

        $message = sprintf('La commande %s a été annulée.', $commande->getNumero() ?? ('#' . $commande->getId()));

        if ($stockDejaConsomme) {
            $message .= ' Attention : du stock avait déjà été consommé pour cette commande et n’a pas été recrédité automatiquement.';
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Restaure une commande annulée : remet chaque ligne dans le
     * statut de production qu'elle avait juste avant l'annulation
     * (mémorisé par annuler() dans $statutProductionAvantAnnulation).
     *
     * Réservé à ROLE_ADMIN, quel que soit l'avancement qu'avait la
     * commande avant son annulation : contrairement à l'annulation
     * (ouverte à tout ROLE_COMMANDE tant que le circuit n'a pas
     * commencé), la restauration peut faire réapparaître une commande
     * dont le stock, les factures ou les paiements ont déjà pu évoluer
     * entre-temps — une vérification humaine par un administrateur est
     * donc toujours exigée.
     */
    #[Route('/{id}/restaurer', name: 'app_commandes_restaurer', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function restaurer(Request $request, Commandes $commande, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Vous devez être connecté pour restaurer une commande.');
        }

        if ($commande->getStatutTravaux() !== 'annulee') {
            $this->addFlash('warning', 'Cette commande n’est pas annulée.');

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('restaurer-commande-' . $commande->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
        }

        $circuitAvaitCommence = false;

        foreach ($commande->getCommandesDetails() as $detail) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            $statutAvant = $detail->getStatutProductionAvantAnnulation();

            if (
                in_array(
                    $statutAvant,
                    [
                        CommandesDetails::PRODUCTION_EN_COURS,
                        CommandesDetails::PRODUCTION_TERMINEE,
                        CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                        CommandesDetails::PRODUCTION_EN_LIVRAISON,
                        CommandesDetails::PRODUCTION_LIVREE,
                    ],
                    true
                )
            ) {
                $circuitAvaitCommence = true;
            }

            $detail->setStatutProduction($statutAvant ?? CommandesDetails::PRODUCTION_A_PRODUIRE);
            $detail->setStatutProductionAvantAnnulation(null);
        }

        $note = sprintf(
            '[Commande restaurée le %s par %s]',
            (new \DateTimeImmutable())->format('d/m/Y H:i'),
            $user->getUserIdentifier()
        );

        $observationExistante = $commande->getObservation();
        $commande->setObservation(
            $observationExistante !== null && trim($observationExistante) !== ''
                ? $observationExistante . "\n\n" . $note
                : $note
        );

        $entityManager->flush();

        $message = sprintf('La commande %s a été restaurée.', $commande->getNumero() ?? ('#' . $commande->getId()));

        if ($circuitAvaitCommence) {
            $message .= ' Attention : la production ou la livraison avait déjà commencé avant l’annulation — vérifiez le stock et les documents liés avant de reprendre le circuit.';
        }

        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_commandes_show', ['id' => $commande->getId()], Response::HTTP_SEE_OTHER);
    }

    private function rattacherFichiers(
        Commandes $commande,
        CommandeDetailFichierRepository $fichierRepository
    ): void {
        foreach ($commande->getCommandesDetails() as $detail) {
            $valeurJetons = trim(
                (string) $detail->getJetonsFichiers()
            );

            if ($valeurJetons === '') {
                continue;
            }

            $jetons = array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'trim',
                            explode(',', $valeurJetons)
                        )
                    )
                )
            );

            foreach ($jetons as $jeton) {
                if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) {
                    throw new \RuntimeException(
                        'Un jeton de fichier est invalide.'
                    );
                }

                $fichier = $fichierRepository->findOneBy([
                    'jetonUpload' => $jeton,
                    'statut' => 'TERMINE',
                ]);

                if ($fichier === null) {
                    throw new \RuntimeException(
                        sprintf(
                            'Le fichier correspondant au jeton %s est introuvable ou incomplet.',
                            $jeton
                        )
                    );
                }

                $detailActuel = $fichier->getCommandeDetail();

                if (
                    $detailActuel !== null
                    && $detailActuel !== $detail
                ) {
                    throw new \RuntimeException(
                        'Ce fichier est déjà rattaché à un autre travail.'
                    );
                }

                $detail->addFichier($fichier);
            }

            // Évite de retraiter les mêmes jetons lors d’une modification.
            $detail->setJetonsFichiers('');
        }
    }


    private function convertirDimension(
        int|float|string|null $valeur
    ): ?float {
        if ($valeur === null) {
            return null;
        }

        $valeurNormalisee = str_replace(
            ',',
            '.',
            trim((string) $valeur)
        );

        if (
            $valeurNormalisee === ''
            || !is_numeric($valeurNormalisee)
        ) {
            return null;
        }

        return max(
            0.0,
            (float) $valeurNormalisee
        );
    }
    private function ajouterHeuresOuvrees(
        \DateTimeImmutable $dateDepart,
        int $heures
    ): \DateTimeImmutable {
        $date = $dateDepart;
        $heuresRestantes = $heures;

        while ($heuresRestantes > 0) {
            $date = $date->modify('+1 hour');

            // 7 = dimanche : aucune heure n’est comptabilisée
            if ((int) $date->format('N') !== 7) {
                --$heuresRestantes;
            }
        }

        return $date;
    }

    private function synchroniserDetailsEtFinitions(
        Commandes $commande,
        FormInterface $form,
        EntityManagerInterface $entityManager
    ): void {
        $detailsForm =
            $form->get('commandesDetails');

        $clientB2B =
            $commande->getClients()?->isB2B()
            ?? false;

        foreach ($detailsForm as $detailForm) {
            $detail =
                $detailForm->getData();

            if (
                !$detail instanceof CommandesDetails
            ) {
                continue;
            }

            /*
         * ====================================================
         * TYPE : ARTICLE EN STOCK
         * ====================================================
         *
         * Vente directe d'un article physique.
         * Aucun produit, aucune configuration,
         * aucun prépresse, aucune production.
         */
            if (
                $detail->getTypeLigne()
                === CommandesDetails::TYPE_ARTICLE
            ) {
                $article =
                    $detail->getArticle();

                if ($article === null) {
                    throw new \DomainException(
                        sprintf(
                            'Veuillez sélectionner un article pour la ligne « %s ».',
                            $detail->getDesignation()
                                ?: 'Article en stock'
                        )
                    );
                }

                if (
                    method_exists(
                        $article,
                        'isActif'
                    )
                    && !$article->isActif()
                ) {
                    throw new \DomainException(
                        sprintf(
                            'L’article « %s » est désactivé.',
                            $article->getDesignation()
                        )
                    );
                }

                if (
                    method_exists(
                        $article,
                        'isVendable'
                    )
                    && !$article->isVendable()
                ) {
                    throw new \DomainException(
                        sprintf(
                            'L’article « %s » n’est pas autorisé à la vente directe.',
                            $article->getDesignation()
                        )
                    );
                }

                /*
             * Nettoyage de toutes les informations
             * propres à une ligne Produit.
             */
                $detail->setProduit(null);

                $detail->setProduitConfiguration(
                    null
                );

                $detail->setTypeImpression(null);

                $detail->setSupport(null);

                $detail->setFormat(null);

                /*
             * Pas de traitement technique.
             */
                $detail->setPrePresseNecessaire(
                    false
                );

                $detail->setProductionNecessaire(
                    false
                );

                /*
             * Vente directe = calcul par quantité/unité.
             *
             * Garde cette ligne seulement si ton setter existe.
             */
                if (
                    method_exists(
                        $detail,
                        'setModeCalcul'
                    )
                ) {
                    $detail->setModeCalcul(
                        'unite'
                    );
                }

                /*
             * Si aucune désignation n'est saisie,
             * on utilise celle de l'article.
             */
                if (
                    trim(
                        (string)
                        $detail->getDesignation()
                    ) === ''
                ) {
                    $detail->setDesignation(
                        $article->getDesignation()
                    );
                }

                /*
             * Pas de synchronisation ProduitConfiguration
             * pour une vente directe d'article.
             */

                $detail->calculerTotaux(
                    $clientB2B
                );

                continue;
            }


            /*
         * ====================================================
         * TYPE : SAISIE LIBRE
         * ====================================================
         */
            if (
                $detail->getTypeLigne()
                === CommandesDetails::TYPE_LIBRE
            ) {
                /*
             * Une ligne libre ne doit être reliée
             * ni à un article, ni à un produit.
             */
                $detail->setArticle(null);

                $detail->setProduit(null);

                $detail->setProduitConfiguration(
                    null
                );

                $detail->setTypeImpression(null);

                $detail->setSupport(null);

                $detail->setFormat(null);

                /*
             * Par défaut :
             * livraison directe.
             */
                $detail->setPrePresseNecessaire(
                    false
                );

                $detail->setProductionNecessaire(
                    false
                );

                $this->synchroniserDetailLibre(
                    $detail,
                    $entityManager
                );

                $detail->calculerTotaux(
                    $clientB2B
                );

                continue;
            }


            /*
         * ====================================================
         * TYPE : PRODUIT / PRESTATION
         * ====================================================
         */
            if (
                $detail->getTypeLigne()
                !== CommandesDetails::TYPE_PRODUIT
            ) {
                throw new \DomainException(
                    'Le type de ligne du travail est invalide.'
                );
            }

            /*
         * Une ligne Produit ne doit pas conserver
         * un Article de vente directe.
         */
            $detail->setArticle(null);


            /*
         * ====================================================
         * MODE DE CONFIGURATION DU PRODUIT
         * ====================================================
         *
         * La procédure a été simplifiée : il n'y a plus qu'une
         * seule façon de configurer une ligne Produit (le choix
         * libre parmi les type/support/format liés au produit).
         * On force donc "manuel", quelle que soit la valeur reçue
         * du formulaire ou déjà enregistrée pour une ancienne
         * ligne créée avant cette simplification.
         */
            $detail->setModeConfiguration('manuel');

            if (
                $detail->isConfigurationAutomatique()
            ) {
                $this->synchroniserDetailAutomatique(
                    $detail,
                    $detailForm,
                    $entityManager
                );
            } elseif (
                $detail->isConfigurationManuelle()
            ) {
                $this->synchroniserDetailManuel(
                    $detail,
                    $detailForm,
                    $entityManager
                );
            } elseif (
                $detail->isSaisieLibre()
            ) {
                /*
             * Compatibilité temporaire avec ton ancien
             * modeConfiguration = libre.
             *
             * À terme, TYPE_LIBRE suffit et cette branche
             * pourra être retirée.
             */
                $this->synchroniserDetailLibre(
                    $detail,
                    $entityManager
                );
            } else {
                throw new \DomainException(
                    'Le mode de saisie du travail est invalide.'
                );
            }

            $detail->calculerTotaux(
                $clientB2B
            );
        }
    }


    private function synchroniserDetailAutomatique(
        CommandesDetails $detail,
        FormInterface $detailForm,
        EntityManagerInterface $entityManager
    ): void {
        $configuration = $detail->getProduitConfiguration();

        if ($configuration === null) {
            throw new \DomainException(
                'Veuillez sélectionner une configuration de produit.'
            );
        }

        if (!$configuration->isActive()) {
            throw new \DomainException(
                'La configuration sélectionnée est inactive.'
            );
        }

        /*
     * Les choix faits dans finitionsSelectionnees.
     */
        $finitionsSelectionnees = [];

        if ($detailForm->has('finitionsSelectionnees')) {
            $valeurs = $detailForm
                ->get('finitionsSelectionnees')
                ->getData();

            if (is_iterable($valeurs)) {
                foreach ($valeurs as $configurationFinition) {
                    if (
                        $configurationFinition
                        instanceof ProduitConfigurationFinition
                    ) {
                        $finitionsSelectionnees[$configurationFinition->getId()] = $configurationFinition;
                    }
                }
            }
        }

        /*
     * Ajoute automatiquement les finitions obligatoires,
     * même si leur case n’a pas été cochée.
     */
        foreach (
            $configuration->getConfigurationFinitions()
            as $configurationFinition
        ) {
            if (!$configurationFinition->isActive()) {
                continue;
            }

            if ($configurationFinition->isObligatoire()) {
                $finitionsSelectionnees[$configurationFinition->getId()] = $configurationFinition;
            }
        }

        /*
     * Supprime les anciennes finitions du détail.
     */
        foreach ($detail->getFinitions()->toArray() as $ancienneFinition) {
            $detail->removeFinition($ancienneFinition);

            if ($ancienneFinition->getId() !== null) {
                $entityManager->remove($ancienneFinition);
            }
        }

        $quantiteDetail = max(
            1,
            (int) ($detail->getQuantite() ?? 1)
        );

        foreach (
            $finitionsSelectionnees
            as $configurationFinition
        ) {
            /*
         * Vérifie que la finition appartient réellement
         * à la configuration sélectionnée.
         */
            if (
                $configurationFinition
                ->getProduitConfiguration()
                !== $configuration
            ) {
                throw new \DomainException(
                    'Une finition sélectionnée ne correspond pas '
                        . 'à la configuration du produit.'
                );
            }

            if (!$configurationFinition->isActive()) {
                continue;
            }

            $prix = (int) (
                $configurationFinition->getPrix() ?? 0
            );

            $modeCalcul = (string) (
                $configurationFinition->getModeCalcul()
                ?: 'forfait'
            );

            $quantiteFinition = match ($modeCalcul) {
                'forfait' => 1,

                'unite',
                'exemplaire',
                'feuille',
                'face',
                'point' => $quantiteDetail,

                default => $quantiteDetail,
            };

            $montant = $modeCalcul === 'forfait'
                ? $prix
                : $prix * $quantiteFinition;

            $finition = new CommandeDetailFinition();

            $finition->setConfigurationFinition(
                $configurationFinition
            );

            /*
         * Nécessaire si CommandeDetailFinition possède aussi
         * une relation directe vers Finition.
         */
            $finition->setFinition(
                $configurationFinition->getFinition()
            );

            $finition->setPrixApplique($prix);
            $finition->setModeCalcul($modeCalcul);
            $finition->setQuantite($quantiteFinition);
            $finition->setMontant($montant);

            $detail->addFinition($finition);
        }
    }
    private function synchroniserDetailManuel(
        CommandesDetails $detail,
        FormInterface $detailForm,
        EntityManagerInterface $entityManager
    ): void {
        foreach ($detail->getFinitions() as $finition) {
            if ($finition->getFinition() === null) {
                throw new \DomainException(
                    'Veuillez sélectionner la finition manuelle.'
                );
            }

            $prix = max(
                0,
                (int) ($finition->getPrixApplique() ?? 0)
            );

            $quantite = max(
                1,
                (int) ($finition->getQuantite() ?? 1)
            );

            $modeCalcul = $finition->getModeCalcul()
                ?: 'forfait';

            $montant = $modeCalcul === 'forfait'
                ? $prix
                : $prix * $quantite;

            $finition->setPrixApplique($prix);
            $finition->setQuantite($quantite);
            $finition->setModeCalcul($modeCalcul);
            $finition->setMontant($montant);
        }
    }

    private function synchroniserDetailLibre(
        CommandesDetails $detail,
        EntityManagerInterface $entityManager
    ): void {
        if (trim((string) $detail->getDesignation()) === '') {
            throw new \DomainException(
                'La désignation de la saisie libre est obligatoire.'
            );
        }

        if ((int) $detail->getQuantite() < 1) {
            throw new \DomainException(
                'La quantité doit être supérieure à zéro.'
            );
        }

        if ((int) $detail->getPrixUnitaire() < 0) {
            throw new \DomainException(
                'Le prix unitaire ne peut pas être négatif.'
            );
        }

        /*
     * Une saisie libre ne dépend pas d’une configuration.
     */
        $detail->setProduitConfiguration(null);

        foreach ($detail->getFinitions()->toArray() as $finition) {
            $detail->removeFinition($finition);

            if ($finition->getId() !== null) {
                $entityManager->remove($finition);
            }
        }
    }
    #[Route(
        '/{id}/ajuster-livraison',
        name: 'app_commandes_ajuster_livraison',
        methods: ['POST']
    )]
    public function ajusterLivraison(
        Request $request,
        Commandes $commande,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_PRODUCTION');

        if (!$this->isCsrfTokenValid(
            'ajuster-livraison-' . $commande->getId(),
            (string) $request->request->get('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }

        $dateSaisie = $request->request->get('dateLivraison');

        if (!$dateSaisie) {
            $this->addFlash('error', 'La nouvelle date est obligatoire.');

            return $this->redirectToRoute('app_commandes_show', [
                'id' => $commande->getId(),
            ]);
        }

        try {
            $nouvelleDate = new \DateTimeImmutable($dateSaisie);
        } catch (\Throwable) {
            $this->addFlash('error', 'La date de livraison est invalide.');

            return $this->redirectToRoute('app_commandes_show', [
                'id' => $commande->getId(),
            ]);
        }

        if ($nouvelleDate <= new \DateTimeImmutable()) {
            $this->addFlash(
                'error',
                'La date de livraison doit être postérieure à maintenant.'
            );

            return $this->redirectToRoute('app_commandes_show', [
                'id' => $commande->getId(),
            ]);
        }

        $commande->setDateLivraison($nouvelleDate);
        $entityManager->flush();

        $this->addFlash(
            'success',
            'Le délai de livraison a été ajusté.'
        );

        return $this->redirectToRoute('app_commandes_show', [
            'id' => $commande->getId(),
        ]);
    }

    #[Route(
        '/{id}/paiement',
        name: 'app_commandes_paiement',
        methods: ['GET', 'POST']
    )]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function paiement(
        Commandes $commande,
        Request $request,
        EntityManagerInterface $entityManager,
        FacturesRepository $facturesRepository,
        ParametresPaiementRepository $parametresPaiementRepository,
        NotificationService $notificationService
    ): Response {
        /*
     * ============================================================
     * UTILISATEUR CONNECTÉ
     * ============================================================
     */

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté pour enregistrer un paiement.'
            );
        }

        /*
     * ============================================================
     * COMMANDE ANNULÉE
     * ============================================================
     *
     * Une commande entièrement annulée ne doit plus pouvoir
     * recevoir de nouveau paiement.
     * ============================================================
     */
        if ($commande->getStatutTravaux() === 'annulee') {
            $this->addFlash(
                'error',
                'Cette commande est annulée : aucun paiement ne peut plus y être enregistré.'
            );

            return $this->redirectToRoute('app_commandes_show', [
                'id' => $commande->getId(),
            ]);
        }

        $parametresPaiement = $parametresPaiementRepository->recuperer();


        /*
     * ============================================================
     * TOTAL DE LA COMMANDE
     * ============================================================
     */

        $totalCommande = (int) (
            $commande->getTotalTtc()
            ?? 0
        );


        /*
     * ============================================================
     * TOTAL DÉJÀ PAYÉ
     * ============================================================
     *
     * Seuls les paiements VALIDÉS sont pris en compte.
     * ============================================================
     */

        $totalPaye = 0;

        foreach (
            $commande->getPaiements()
            as $paiementExistant
        ) {
            if (
                !$paiementExistant->estValide()
            ) {
                continue;
            }

            $totalPaye += (int) (
                $paiementExistant->getMontant()
                ?? 0
            );
        }


        /*
     * ============================================================
     * RESTE À PAYER
     * ============================================================
     */

        $resteAPayer = max(
            0,
            $totalCommande
                - $totalPaye
        );


        /*
     * ============================================================
     * COMMANDE DÉJÀ SOLDÉE
     * ============================================================
     */

        if (
            $resteAPayer <= 0
            &&
            $totalCommande > 0
        ) {
            $this->addFlash(
                'warning',
                'Cette commande est déjà entièrement payée.'
            );

            return $this->redirectToRoute(
                'app_commandes_show',
                [
                    'id' => $commande->getId(),
                ]
            );
        }


        /*
     * ============================================================
     * NOUVEAU PAIEMENT
     * ============================================================
     */

        $paiement = new Paiements();

        $paiement->setCommande(
            $commande
        );

        /*
     * Très important :
     *
     * le paiement appartient automatiquement
     * à la session connectée.
     */
        $paiement->setEncaissePar(
            $user
        );

        $paiement->setDate(
            new \DateTimeImmutable()
        );


        /*
     * ============================================================
     * FORMULAIRE
     * ============================================================
     */

        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Utilisateur non authentifié.'
            );
        }

        $form = $this->createForm(
            PaiementsType::class,
            $paiement,
            [
                'est_admin' =>
                $this->isGranted('ROLE_ADMIN'),

                'utilisateur' =>
                $user,
            ]
        );

        $form->handleRequest(
            $request
        );


        /*
     * ============================================================
     * TRAITEMENT
     * ============================================================
     */

        if (
            $form->isSubmitted()
            &&
            $form->isValid()
        ) {
            /*
         * ========================================================
         * MONTANT SAISI
         * ========================================================
         */

            $montant = (int) (
                $paiement->getMontant()
                ?? 0
            );


            /*
         * ========================================================
         * RECALCUL DU TOTAL PAYÉ
         * ========================================================
         *
         * On recalcule juste avant l'enregistrement.
         * ========================================================
         */

            $totalPayeAvant = 0;

            foreach (
                $commande->getPaiements()
                as $paiementExistant
            ) {
                if (
                    !$paiementExistant->estValide()
                ) {
                    continue;
                }

                $totalPayeAvant += (int) (
                    $paiementExistant->getMontant()
                    ?? 0
                );
            }


            $resteAvantPaiement = max(
                0,
                $totalCommande
                    - $totalPayeAvant
            );


            /*
         * ========================================================
         * CONTRÔLE DU MONTANT
         * ========================================================
         */

            if ($montant <= 0) {
                $form
                    ->get('montant')
                    ->addError(
                        new FormError(
                            'Le montant doit être supérieur à zéro.'
                        )
                    );
            } elseif (
                $montant
                >
                $resteAvantPaiement
            ) {
                $form
                    ->get('montant')
                    ->addError(
                        new FormError(
                            sprintf(
                                'Le montant saisi dépasse le reste à payer de %s FCFA.',
                                number_format(
                                    $resteAvantPaiement,
                                    0,
                                    ',',
                                    ' '
                                )
                            )
                        )
                    );
            } elseif (
                $paiement->getMode() === Paiements::MODE_CHEQUE
                && trim((string) $paiement->getReference()) === ''
            ) {
                /*
                 * Le numéro du chèque est saisi dans le champ
                 * "Référence de paiement" (voir le placeholder du
                 * formulaire) : sans lui, l'enregistrement du
                 * paiement échoue silencieusement plus loin. On le
                 * signale ici, comme une erreur de formulaire
                 * normale, pour tous les profils (admin ou agent).
                 */
                $form
                    ->get('reference')
                    ->addError(
                        new FormError(
                            'Le numéro du chèque est obligatoire.'
                        )
                    );
            } else {
                /*
             * ====================================================
             * RELATION COMMANDE <-> PAIEMENT
             * ====================================================
             */

                $commande->addPaiement(
                    $paiement
                );


                /*
             * ====================================================
             * FRAIS MOBILE MONEY (retrait + fonds de soutien)
             * ====================================================
             *
             * Le client paie parfois ces frais en plus du prix de
             * la commande quand il règle par Orange Money ou Wave.
             * On ne les autorise que pour ces deux modes, quoi que
             * le formulaire ait pu soumettre (défense en profondeur
             * : les cases ne sont visibles en JS que pour ces modes,
             * mais le contrôleur ne doit jamais faire confiance
             * uniquement au JS).
             * ====================================================
             */

                $modesMobileMoney = [
                    Paiements::MODE_ORANGE_MONEY,
                    Paiements::MODE_WAVE,
                ];

                if (!in_array($paiement->getMode(), $modesMobileMoney, true)) {
                    $paiement->setFraisRetraitInclus(false);
                    $paiement->setFondsSoutienInclus(false);
                }

                /*
                 * Chèque : le numéro saisi dans "Référence de
                 * paiement" alimente aussi numeroCheque, requis par
                 * l'entité au moment de l'enregistrement.
                 */
                if ($paiement->getMode() === Paiements::MODE_CHEQUE) {
                    $paiement->setNumeroCheque($paiement->getReference());
                }

                $paiement->setMontantFraisRetrait(
                    $paiement->isFraisRetraitInclus()
                        ? $parametresPaiement->calculerFraisRetrait($montant)
                        : 0
                );

                $paiement->setMontantFondsSoutien(
                    $paiement->isFondsSoutienInclus()
                        ? $parametresPaiement->calculerFondsSoutien($montant)
                        : 0
                );


                /*
             * ====================================================
             * VALIDATION DU PAIEMENT
             * ====================================================
             *
             * Validation automatique :
             *
             * - espèces
             * - Orange Money
             * - Wave
             *
             * Validation manuelle :
             *
             * - virement bancaire
             * - chèque
             * - carte bancaire
             * ====================================================
             */

                try {
                    if (
                        !$paiement->necessiteValidationManuelle()
                    ) {
                        $paiement->validerPar(
                            $user
                        );
                    }
                } catch (
                    \InvalidArgumentException
                    | \LogicException
                    | \DomainException
                    | \RuntimeException $exception
                ) {
                    $this->addFlash(
                        'warning',
                        $exception->getMessage()
                    );

                    return $this->redirectToRoute(
                        'app_commandes_paiement',
                        [
                            'id' => $commande->getId(),
                        ]
                    );
                }


                /*
             * ====================================================
             * PAIEMENT VALIDÉ
             * ====================================================
             *
             * Un mouvement de trésorerie est créé uniquement
             * lorsque le paiement devient réellement validé.
             * ====================================================
             */

                if (
                    $paiement->estValide()
                ) {
                    $compte =
                        $paiement->getCompteTresorerie();


                    /*
                 * =================================================
                 * COMPTE DE TRÉSORERIE OBLIGATOIRE
                 * =================================================
                 */

                    if ($compte === null) {
                        $this->addFlash(
                            'warning',
                            'Veuillez sélectionner un compte de trésorerie.'
                        );

                        return $this->redirectToRoute(
                            'app_commandes_paiement',
                            [
                                'id' => $commande->getId(),
                            ]
                        );
                    }


                    /*
                 * =================================================
                 * ANTI-DOUBLON
                 * =================================================
                 *
                 * Un paiement ne doit générer
                 * qu'un seul mouvement de trésorerie.
                 * =================================================
                 */

                    if (
                        $paiement->getMouvementTresorerie()
                        === null
                    ) {
                        $mouvement =
                            new MouvementTresorerie();


                        /*
                     * =============================================
                     * RÉFÉRENCE DU MOUVEMENT
                     * =============================================
                     */

                        $mouvement->setReference(
                            sprintf(
                                'MVT-PAY-%s-%s',
                                (
                                    new \DateTimeImmutable()
                                )->format(
                                    'YmdHis'
                                ),
                                strtoupper(
                                    substr(
                                        bin2hex(
                                            random_bytes(
                                                3
                                            )
                                        ),
                                        0,
                                        6
                                    )
                                )
                            )
                        );


                        /*
                     * =============================================
                     * TYPE
                     * =============================================
                     *
                     * Un paiement client est une entrée d'argent.
                     * =============================================
                     */

                        $mouvement->setType(
                            MouvementTresorerie::TYPE_ENCAISSEMENT
                        );


                        /*
                     * =============================================
                     * CATÉGORIE FINANCIÈRE
                     * =============================================
                     *
                     * TRÈS IMPORTANT :
                     *
                     * Le paiement d'une commande est classé
                     * automatiquement comme VENTE.
                     *
                     * CATEGORIE_VENTE entraîne :
                     *
                     * impactResultat = true
                     *
                     * grâce à MouvementTresorerie::setCategorie().
                     * =============================================
                     */

                        $mouvement->setCategorie(
                            MouvementTresorerie::CATEGORIE_VENTE
                        );


                        /*
                     * =============================================
                     * CONFIDENTIALITÉ
                     * =============================================
                     *
                     * Un encaissement client normal
                     * n'est jamais confidentiel.
                     * =============================================
                     */

                        $mouvement->setConfidentiel(
                            false
                        );


                        /*
                     * =============================================
                     * COMPTE DESTINATION
                     * =============================================
                     *
                     * Exemple :
                     *
                     * - Caisse Vente
                     * - Orange Money
                     * - Wave
                     * =============================================
                     */

                        $mouvement->setCompteDestination(
                            $compte
                        );


                        /*
                     * Encaissement :
                     * aucun compte source.
                     */

                        $mouvement->setCompteSource(
                            null
                        );


                        /*
                     * =============================================
                     * MONTANT
                     * =============================================
                     */

                        $mouvement->setMontant(
                            $montant
                        );


                        /*
                     * =============================================
                     * DEVISE
                     * =============================================
                     */

                        $mouvement->setDevise(
                            'XOF'
                        );


                        /*
                     * =============================================
                     * MODE DE PAIEMENT
                     * =============================================
                     */

                        $mouvement->setModePaiement(
                            $paiement->getMode()
                        );


                        /*
                     * =============================================
                     * RÉFÉRENCE EXTERNE
                     * =============================================
                     *
                     * Exemple :
                     *
                     * référence Orange Money
                     * référence Wave
                     * reçu
                     * transaction
                     * =============================================
                     */

                        $mouvement->setReferenceExterne(
                            $paiement->getReference()
                        );


                        /*
                     * =============================================
                     * LIBELLÉ
                     * =============================================
                     */

                        $mouvement->setLibelle(
                            sprintf(
                                'Paiement commande %s — %s',
                                $commande->getNumero()
                                    ??
                                    '#'
                                    . $commande->getId(),
                                $commande->getClients()?->getNomComplet()
                                    ?? 'Client inconnu'
                            )
                        );


                        /*
                     * =============================================
                     * DESCRIPTION
                     * =============================================
                     */

                        $mouvement->setDescription(
                            sprintf(
                                'Encaissement de %s FCFA pour la commande %s.',
                                number_format(
                                    $montant,
                                    0,
                                    ',',
                                    ' '
                                ),
                                $commande->getNumero()
                                    ??
                                    '#'
                                    . $commande->getId()
                            )
                        );


                        /*
                     * =============================================
                     * AGENT
                     * =============================================
                     *
                     * Utilisateur connecté.
                     *
                     * Cette information permettra notamment :
                     *
                     * - statistiques personnelles ;
                     * - historique des encaissements ;
                     * - audit de caisse.
                     * =============================================
                     */

                        $mouvement->setAgent(
                            $user
                        );


                        /*
                     * =============================================
                     * DATE OPÉRATION
                     * =============================================
                     */

                        $mouvement->setDateOperation(
                            $paiement->getDate()
                                ??
                                new \DateTimeImmutable()
                        );


                        /*
                     * =============================================
                     * STATUT
                     * =============================================
                     */

                        $mouvement->setStatut(
                            MouvementTresorerie::STATUT_VALIDE
                        );


                        /*
                     * =============================================
                     * DATE DE VALIDATION
                     * =============================================
                     */

                        $mouvement->setDateValidation(
                            new \DateTimeImmutable()
                        );


                        /*
                     * =============================================
                     * RELATION
                     * PAIEMENT <-> MOUVEMENT
                     * =============================================
                     */

                        $mouvement->setPaiement(
                            $paiement
                        );

                        $paiement->setMouvementTresorerie(
                            $mouvement
                        );


                        /*
                     * =============================================
                     * MISE À JOUR DU SOLDE DU COMPTE
                     * =============================================
                     *
                     * Le compte reçoit le montant total réellement
                     * encaissé (part commande + frais mobile money
                     * éventuels) : c'est ce qui entre physiquement
                     * dans la caisse Orange Money / Wave, même si
                     * seule la part commande est comptée dans le
                     * solde de la commande elle-même.
                     * =============================================
                     */

                        $ancienSolde = (int) (
                            $compte->getSoldeActuel()
                            ?? 0
                        );


                        $compte->setSoldeActuel(
                            $ancienSolde
                                +
                                $paiement->getMontantTotalEncaisse()
                        );


                        /*
                     * =============================================
                     * PERSISTENCE
                     * =============================================
                     */

                        $entityManager->persist(
                            $mouvement
                        );

                        $entityManager->persist(
                            $compte
                        );


                        /*
                     * =============================================
                     * MOUVEMENT SÉPARÉ POUR LES FRAIS MOBILE MONEY
                     * =============================================
                     *
                     * Classé en "Autre produit" (pas "Vente") pour
                     * ne pas gonfler le chiffre d'affaires réel, tout
                     * en restant compté dans le résultat -- c'est
                     * bien de l'argent réellement encaissé, que la
                     * boutique reversera ensuite en frais de retrait
                     * mobile money.
                     * =============================================
                     */

                        $montantFrais =
                            $paiement->getMontantFraisRetrait()
                            + $paiement->getMontantFondsSoutien();

                        if ($montantFrais > 0) {
                            $mouvementFrais = new MouvementTresorerie();

                            $mouvementFrais->setReference(
                                sprintf(
                                    'MVT-FRAIS-%s-%s',
                                    (new \DateTimeImmutable())->format('YmdHis'),
                                    strtoupper(substr(bin2hex(random_bytes(3)), 0, 6))
                                )
                            );

                            $mouvementFrais->setType(
                                MouvementTresorerie::TYPE_ENCAISSEMENT
                            );

                            $mouvementFrais->setCategorie(
                                MouvementTresorerie::CATEGORIE_AUTRE_PRODUIT
                            );

                            $mouvementFrais->setConfidentiel(false);

                            $mouvementFrais->setCompteDestination($compte);
                            $mouvementFrais->setCompteSource(null);

                            $mouvementFrais->setMontant($montantFrais);
                            $mouvementFrais->setDevise('XOF');
                            $mouvementFrais->setModePaiement($paiement->getMode());
                            $mouvementFrais->setReferenceExterne($paiement->getReference());

                            $mouvementFrais->setLibelle(
                                sprintf(
                                    'Frais mobile money - commande %s — %s',
                                    $commande->getNumero() ?? '#' . $commande->getId(),
                                    $commande->getClients()?->getNomComplet() ?? 'Client inconnu'
                                )
                            );

                            $detailFrais = [];

                            if ($paiement->getMontantFraisRetrait() > 0) {
                                $detailFrais[] = sprintf(
                                    'frais de retrait : %s FCFA',
                                    number_format($paiement->getMontantFraisRetrait(), 0, ',', ' ')
                                );
                            }

                            if ($paiement->getMontantFondsSoutien() > 0) {
                                $detailFrais[] = sprintf(
                                    'fonds de soutien : %s FCFA',
                                    number_format($paiement->getMontantFondsSoutien(), 0, ',', ' ')
                                );
                            }

                            $mouvementFrais->setDescription(
                                sprintf(
                                    'Frais mobile money à la charge du client sur le paiement de %s FCFA '
                                        . 'de la commande %s (%s).',
                                    number_format($montant, 0, ',', ' '),
                                    $commande->getNumero() ?? '#' . $commande->getId(),
                                    implode(', ', $detailFrais)
                                )
                            );

                            $mouvementFrais->setAgent($user);
                            $mouvementFrais->setDateOperation(
                                $paiement->getDate() ?? new \DateTimeImmutable()
                            );
                            $mouvementFrais->setStatut(
                                MouvementTresorerie::STATUT_VALIDE
                            );
                            $mouvementFrais->setDateValidation(
                                new \DateTimeImmutable()
                            );

                            $entityManager->persist($mouvementFrais);
                        }
                    }
                }


                /*
             * ====================================================
             * PERSISTENCE DU PAIEMENT
             * ====================================================
             */

                $entityManager->persist(
                    $paiement
                );


                /*
             * ====================================================
             * RECALCUL DU TOTAL PAYÉ DE LA COMMANDE
             * ====================================================
             */

                $nouveauTotalPaye = 0;

                foreach (
                    $commande->getPaiements()
                    as $paiementCommande
                ) {
                    if (
                        !$paiementCommande->estValide()
                    ) {
                        continue;
                    }

                    $nouveauTotalPaye += (int) (
                        $paiementCommande->getMontant()
                        ?? 0
                    );
                }


                /*
             * ====================================================
             * RESTE À PAYER
             * ====================================================
             */

                $nouveauReste = max(
                    0,
                    $totalCommande
                        -
                        $nouveauTotalPaye
                );


                /*
             * ====================================================
             * COMMANDE
             * ====================================================
             */

                $commande->setTotalPaye(
                    $nouveauTotalPaye
                );

                $commande->setResteAPayer(
                    $nouveauReste
                );


                /*
             * ====================================================
             * STATUT PAIEMENT COMMANDE
             * ====================================================
             */

                if (
                    $nouveauReste <= 0
                    &&
                    $totalCommande > 0
                ) {
                    $commande->setStatutPaiement(
                        Commandes::PAIEMENT_PAYE
                    );
                } elseif (
                    $nouveauTotalPaye > 0
                ) {
                    $commande->setStatutPaiement(
                        Commandes::PAIEMENT_PARTIEL
                    );
                } else {
                    $commande->setStatutPaiement(
                        Commandes::PAIEMENT_IMPAYE
                    );
                }


                /*
             * ====================================================
             * FACTURE OFFICIELLE
             * ====================================================
             */

                $facture =
                    $facturesRepository->findOneBy(
                        [
                            'commande' =>
                            $commande,

                            'comptabilisee' =>
                            true,
                        ]
                    );


                if (
                    $facture !== null
                ) {
                    /*
                 * Synchronise :
                 *
                 * - montant payé ;
                 * - reste à payer ;
                 * - statut paiement.
                 */

                    $facture
                        ->synchroniserPaiementsDepuisCommande();


                    /*
                 * Rattachement du paiement à la facture.
                 */

                    if (
                        $paiement->estValide()
                        &&
                        $paiement->getFacture()
                        === null
                    ) {
                        $paiement->setFacture(
                            $facture
                        );
                    }


                    $entityManager->persist(
                        $facture
                    );
                }


                /*
             * ====================================================
             * COMMANDE
             * ====================================================
             */

                $entityManager->persist(
                    $commande
                );

                if ($paiement->estValide()) {
                    $notificationService->notifierRoles(
                        ['ROLE_ADMIN', 'ROLE_TRESORERIE_VOIR'],
                        sprintf(
                            'Paiement de %s FCFA encaissé sur la commande %s.',
                            number_format($montant, 0, ',', ' '),
                            $commande->getNumero()
                        ),
                        'app_commandes_show',
                        ['id' => $commande->getId()],
                        $user
                    );
                }


                /*
             * ====================================================
             * FLUSH UNIQUE
             * ====================================================
             *
             * Enregistre ensemble :
             *
             * - paiement ;
             * - mouvement trésorerie ;
             * - compte trésorerie ;
             * - commande ;
             * - facture ;
             * - notification.
             * ====================================================
             */

                $entityManager->flush();


                /*
             * ====================================================
             * MESSAGE
             * ====================================================
             */

                if (
                    $paiement->estValide()
                ) {
                    $montantFrais =
                        $paiement->getMontantFraisRetrait()
                        + $paiement->getMontantFondsSoutien();

                    $messageSucces = sprintf(
                        'Paiement de %s FCFA enregistré avec succès. L’encaissement a été intégré à la trésorerie et classé dans les ventes.',
                        number_format(
                            $montant,
                            0,
                            ',',
                            ' '
                        )
                    );

                    if ($montantFrais > 0) {
                        $messageSucces .= sprintf(
                            ' Frais mobile money encaissés en plus : %s FCFA (total remis par le client : %s FCFA).',
                            number_format($montantFrais, 0, ',', ' '),
                            number_format($paiement->getMontantTotalEncaisse(), 0, ',', ' ')
                        );
                    }

                    $this->addFlash(
                        'success',
                        $messageSucces
                    );
                } else {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Paiement de %s FCFA enregistré en attente de validation. Aucun mouvement de trésorerie n’a encore été créé.',
                            number_format(
                                $montant,
                                0,
                                ',',
                                ' '
                            )
                        )
                    );
                }


                /*
             * ====================================================
             * REDIRECTION
             * ====================================================
             */

                return $this->redirectToRoute(
                    'app_commandes_show',
                    [
                        'id' => $commande->getId(),
                    ]
                );
            }
        }


        /*
     * ============================================================
     * AFFICHAGE
     * ============================================================
     */

        return $this->render(
            'commandes/paiement.html.twig',
            [
                'commande' =>
                $commande,

                'form' =>
                $form->createView(),

                'totalCommande' =>
                $totalCommande,

                'totalPaye' =>
                $totalPaye,

                'resteAPayer' =>
                $resteAPayer,

                'parametresPaiement' =>
                $parametresPaiement,
            ]
        );
    }


    /*
     * ================================================================
     * SOLDE CLIENT
     * ================================================================
     *
     * Déduit tout ou partie du solde du client (monnaie non rendue,
     * alimentée manuellement par la caissière) du reste à payer de
     * cette commande. Aucun compte de trésorerie n'est crédité :
     * l'argent est déjà entré en caisse lors d'un paiement antérieur.
     * ================================================================
     */
    #[Route(
        '/{id}/solde-client/utiliser',
        name: 'app_commandes_utiliser_solde_client',
        methods: ['POST']
    )]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function utiliserSoldeClient(
        Commandes $commande,
        Request $request,
        EntityManagerInterface $entityManager,
        FacturesRepository $facturesRepository,
        NotificationService $notificationService
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté pour enregistrer un paiement.'
            );
        }

        if (!$this->isCsrfTokenValid(
            'utiliser_solde_client_' . $commande->getId(),
            $request->request->get('_token')
        )) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_commandes_paiement', [
                'id' => $commande->getId(),
            ]);
        }

        if ($commande->getStatutTravaux() === 'annulee') {
            $this->addFlash(
                'error',
                'Cette commande est annulée : aucun paiement ne peut plus y être enregistré.'
            );

            return $this->redirectToRoute('app_commandes_show', [
                'id' => $commande->getId(),
            ]);
        }

        $client = $commande->getClients();
        $soldeClient = (int) ($client?->getSoldeCredit() ?? 0);

        if ($client === null || $soldeClient <= 0) {
            $this->addFlash(
                'warning',
                'Ce client ne dispose d’aucun solde à utiliser.'
            );

            return $this->redirectToRoute('app_commandes_paiement', [
                'id' => $commande->getId(),
            ]);
        }

        $totalCommande = (int) ($commande->getTotalTtc() ?? 0);

        $totalPaye = 0;

        foreach ($commande->getPaiements() as $paiementExistant) {
            if (!$paiementExistant->estValide()) {
                continue;
            }

            $totalPaye += (int) ($paiementExistant->getMontant() ?? 0);
        }

        $resteAPayer = max(0, $totalCommande - $totalPaye);

        if ($resteAPayer <= 0) {
            $this->addFlash(
                'warning',
                'Cette commande est déjà entièrement payée.'
            );

            return $this->redirectToRoute('app_commandes_show', [
                'id' => $commande->getId(),
            ]);
        }

        $montantAUtiliser = min($soldeClient, $resteAPayer);

        $paiement = new Paiements();
        $paiement->setCommande($commande);
        $paiement->setEncaissePar($user);
        $paiement->setDate(new \DateTimeImmutable());
        $paiement->setMode(Paiements::MODE_SOLDE_CLIENT);
        $paiement->setMontant($montantAUtiliser);
        $paiement->setObservation(
            'Déduit automatiquement du solde du client (monnaie non rendue lors d’un précédent paiement).'
        );
        $paiement->validerPar($user);

        $commande->addPaiement($paiement);

        $client->setSoldeCredit($soldeClient - $montantAUtiliser);

        $nouveauTotalPaye = $totalPaye + $montantAUtiliser;
        $nouveauReste = max(0, $totalCommande - $nouveauTotalPaye);

        $commande->setTotalPaye($nouveauTotalPaye);
        $commande->setResteAPayer($nouveauReste);

        if ($nouveauReste <= 0 && $totalCommande > 0) {
            $commande->setStatutPaiement(Commandes::PAIEMENT_PAYE);
        } elseif ($nouveauTotalPaye > 0) {
            $commande->setStatutPaiement(Commandes::PAIEMENT_PARTIEL);
        } else {
            $commande->setStatutPaiement(Commandes::PAIEMENT_IMPAYE);
        }

        $facture = $facturesRepository->findOneBy([
            'commande' => $commande,
            'comptabilisee' => true,
        ]);

        if ($facture !== null) {
            $facture->synchroniserPaiementsDepuisCommande();

            if ($paiement->getFacture() === null) {
                $paiement->setFacture($facture);
            }

            $entityManager->persist($facture);
        }

        $entityManager->persist($paiement);
        $entityManager->persist($commande);
        $entityManager->persist($client);

        $notificationService->notifierRoles(
            ['ROLE_ADMIN', 'ROLE_TRESORERIE_VOIR'],
            sprintf(
                'Solde client de %s FCFA déduit sur la commande %s.',
                number_format($montantAUtiliser, 0, ',', ' '),
                $commande->getNumero()
            ),
            'app_commandes_show',
            ['id' => $commande->getId()],
            $user
        );

        $entityManager->flush();

        $this->addFlash(
            'success',
            sprintf(
                'Solde client de %s FCFA appliqué au paiement de cette commande.',
                number_format($montantAUtiliser, 0, ',', ' ')
            )
        );

        return $this->redirectToRoute('app_commandes_show', [
            'id' => $commande->getId(),
        ]);
    }


    private function commandeEstValidee(
        Commandes $commande
    ): bool {
        return $commande->isStatut();
    }


    private function preparerCircuitCommande(
        Commandes $commande
    ): void {
        foreach ($commande->getCommandesDetails() as $detail) {

            /*
         * Sécurité.
         */
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            /*
         * La ligne décide elle-même de son premier statut
         * selon son circuit.
         */
            $detail->preparerApresValidationCommande();
            dump([
                'apres' => $detail->getStatutProduction(),
            ]);
        }
    }
    private function commandeACommenceSonCircuit(
        Commandes $commande
    ): bool {
        foreach (
            $commande->getCommandesDetails()
            as $detail
        ) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            $statut =
                $detail->getStatutProduction();

            if (
                in_array(
                    $statut,
                    [
                        CommandesDetails::PRODUCTION_EN_COURS,
                        CommandesDetails::PRODUCTION_TERMINEE,
                        CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                        CommandesDetails::PRODUCTION_EN_LIVRAISON,
                        CommandesDetails::PRODUCTION_LIVREE,
                    ],
                    true
                )
            ) {
                return true;
            }

            if (
                method_exists(
                    $detail,
                    'getQuantiteLivree'
                )
                && (float) $detail->getQuantiteLivree() > 0
            ) {
                return true;
            }

            if (
                method_exists(
                    $detail,
                    'getProductionDebuteLe'
                )
                && $detail->getProductionDebuteLe() !== null
            ) {
                return true;
            }
        }

        return false;
    }

    private function verifierModificationStructurelleAutorisee(
        Commandes $commande
    ): void {
        if (
            !$this->commandeACommenceSonCircuit(
                $commande
            )
        ) {
            return;
        }

        throw new \DomainException(
            'Cette commande a déjà commencé son circuit de production ou de livraison. '
                . 'Les lignes ne peuvent plus être modifiées.'
        );
    }
    private function creerEmpreinteDetails(
        Commandes $commande
    ): string {
        $donnees = [];

        foreach (
            $commande->getCommandesDetails()
            as $detail
        ) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            $finitions = [];

            foreach (
                $detail->getFinitions()
                as $finition
            ) {
                $finitions[] = [
                    'id' =>
                    $finition->getId(),

                    'configuration' =>
                    $finition
                        ->getConfigurationFinition()
                        ?->getId(),

                    'quantite' =>
                    $finition->getQuantite(),

                    'prix' =>
                    $finition->getPrixApplique(),

                    'montant' =>
                    $finition->getMontant(),
                ];
            }

            $donnees[] = [
                'id' =>
                $detail->getId(),

                'produit' =>
                $detail->getProduit()
                    ?->getId(),

                'configuration' =>
                $detail
                    ->getProduitConfiguration()
                    ?->getId(),

                'designation' =>
                $detail->getDesignation(),

                'typeImpression' =>
                $detail->getTypeImpression()
                    ?->getId(),

                'support' =>
                $detail->getSupport()
                    ?->getId(),

                'format' =>
                $detail->getFormat()
                    ?->getId(),

                'largeur' =>
                $detail->getLargeur(),

                'longueur' =>
                $detail->getLongueur(),

                'surface' =>
                $detail->getSurface(),

                'quantite' =>
                $detail->getQuantite(),

                'prixUnitaire' =>
                $detail->getPrixUnitaire(),

                'productionNecessaire' =>
                $detail->isProductionNecessaire(),

                'prePresseNecessaire' =>
                $detail->isPrePresseNecessaire(),

                'finitions' =>
                $finitions,
            ];
        }

        return hash(
            'sha256',
            serialize($donnees)
        );
    }
}
