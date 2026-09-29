<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Form\ClientsType;
use App\Repository\ClientsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Paiements;
use App\Entity\User;
use App\Entity\JournalActivite;
use App\Repository\CommandesRepository;
use App\Repository\DevisRepository;
use App\Repository\CompteTresorerieRepository;
use App\Entity\MouvementTresorerie;
use App\Service\MouvementTresorerieService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/clients')]
final class ClientsController extends AbstractController
{
    #[Route(
        '',
        name: 'app_clients_index',
        methods: ['GET']
    )]
    public function index(): Response
    {
        $nouveauClient = new Clients();

        $formAjout = $this->createForm(
            ClientsType::class,
            $nouveauClient,
            [
                'action' => $this->generateUrl('app_clients_new'),
                'method' => 'POST',
            ]
        );

        return $this->render('clients/index.html.twig', [
            'formAjout' => $formAjout->createView(),
        ]);
    }

    /*
     * ============================================================
     * SOLDES CLIENTS
     * ============================================================
     *
     * Liste des clients chez qui de l'argent est resté (monnaie non
     * rendue) : répond à "chez qui la monnaie est-elle restée ?".
     * ============================================================
     */
    #[Route(
        '/soldes',
        name: 'app_clients_soldes',
        methods: ['GET']
    )]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function soldes(ClientsRepository $clientsRepository): Response
    {
        $clients = $clientsRepository->trouverAvecSoldeCredit();

        $total = 0;

        foreach ($clients as $client) {
            $total += $client->getSoldeCredit();
        }

        return $this->render('clients/soldes.html.twig', [
            'clients' => $clients,
            'total' => $total,
        ]);
    }

    /*
     * Cette route doit rester avant /{id}.
     */
    #[Route(
        '/actions-en-masse',
        name: 'app_clients_mass_action',
        methods: ['POST']
    )]
    public function massAction(
        Request $request,
        ClientsRepository $clientsRepository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        ) ?? [];

        if (!$this->isCsrfTokenValid(
            'clients_mass_action',
            $data['_token'] ?? ''
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $ids = array_values(array_unique(array_filter(
            array_map(
                'intval',
                is_array($data['ids'] ?? null)
                    ? $data['ids']
                    : []
            )
        )));

        $action = $data['action'] ?? '';

        if ($ids === []) {
            return $this->json([
                'success' => false,
                'message' => 'Sélectionnez au moins un client.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!in_array(
            $action,
            ['bloquer', 'debloquer', 'supprimer'],
            true
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Action non reconnue.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $clients = $clientsRepository->findBy([
            'id' => $ids,
        ]);

        if ($clients === []) {
            return $this->json([
                'success' => false,
                'message' => 'Aucun client correspondant trouvé.',
            ], Response::HTTP_NOT_FOUND);
        }

        foreach ($clients as $client) {
            if ($action === 'bloquer') {
                $client->setStatut(false);
            } elseif ($action === 'debloquer') {
                $client->setStatut(true);
            } elseif ($action === 'supprimer') {
                $entityManager->remove($client);
            }
        }

        $entityManager->flush();

        $messages = [
            'bloquer' =>
            'Les clients sélectionnés ont été bloqués.',
            'debloquer' =>
            'Les clients sélectionnés ont été débloqués.',
            'supprimer' =>
            'Les clients sélectionnés ont été supprimés.',
        ];

        return $this->json([
            'success' => true,
            'message' => $messages[$action],
        ]);
    }

    #[Route(
        '/new',
        name: 'app_clients_new',
        methods: ['POST']
    )]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        ClientsRepository $clientsRepository
    ): JsonResponse {
        $client = new Clients();

        /*
     * ============================================================
     * CODE PROVISOIRE
     * ============================================================
     *
     * La colonne code est obligatoire et unique.
     * L'identifiant n'existe pas encore avant le premier flush.
     */
        $client->setCode(
            'TMP-' . bin2hex(
                random_bytes(12)
            )
        );


        $form = $this->createForm(
            ClientsType::class,
            $client
        );

        $form->handleRequest($request);


        /*
     * ============================================================
     * FORMULAIRE NON SOUMIS
     * ============================================================
     */

        if (!$form->isSubmitted()) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Le formulaire n’a pas été soumis.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
     * ============================================================
     * DROIT B2B
     * ============================================================
     */

        if (
            !$this->isGranted('ROLE_ADMIN')
            &&
            $client->isB2B()
        ) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Vous n’êtes pas autorisé à créer un client B2B.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
     * ============================================================
     * VALIDATION IDENTITÉ
     * ============================================================
     */

        $this->validerIdentiteClient(
            $form,
            $client
        );


        /*
     * ============================================================
     * VÉRIFICATION TÉLÉPHONE AVANT SQL
     * ============================================================
     */

        $telephone =
            trim(
                (string)
                $client->getTelephone()
            );


        if (
            $telephone !== ''
            &&
            $clientsRepository
            ->telephoneExistePourAutreClient(
                $telephone
            )
        ) {
            /*
         * On attache aussi l'erreur directement
         * au champ téléphone.
         */
            if ($form->has('telephone')) {
                $form
                    ->get('telephone')
                    ->addError(
                        new FormError(
                            'Un client avec ce numéro de téléphone existe déjà.'
                        )
                    );
            }

            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Ce numéro de téléphone est déjà utilisé par un autre client.',

                    'errors' => [
                        'telephone' => [
                            'Un client avec ce numéro de téléphone existe déjà.',
                        ],
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        /*
     * ============================================================
     * VALIDATION SYMFONY
     * ============================================================
     */

        if (!$form->isValid()) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Veuillez corriger les champs indiqués.',

                    'errors' =>
                    $this->getFormErrors(
                        $form
                    ),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        /*
     * ============================================================
     * ENREGISTREMENT
     * ============================================================
     */

        try {
            $entityManager->persist(
                $client
            );

            /*
         * Premier flush :
         * génération de l'ID.
         */
            $entityManager->flush();


            /*
         * On transforme :
         *
         * TMP-xxxxxxxx
         *
         * en :
         *
         * CLI-000001
         */
            $client->genererCodeDepuisId();


            /*
         * Deuxième flush :
         * sauvegarde du code définitif.
         */
            $entityManager->flush();
        } catch (
            UniqueConstraintViolationException $e
        ) {
            /*
         * Deuxième sécurité.
         *
         * Même si deux utilisateurs essaient de créer
         * exactement le même téléphone simultanément,
         * la contrainte UNIQUE MySQL reste l'autorité finale.
         */

            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Ce numéro de téléphone est déjà utilisé par un autre client.',

                    'errors' => [
                        'telephone' => [
                            'Un client avec ce numéro de téléphone existe déjà.',
                        ],
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        /*
     * ============================================================
     * SUCCÈS
     * ============================================================
     */

        return $this->json(
            [
                'success' => true,

                'message' => sprintf(
                    'Le client %s a été ajouté avec succès.',
                    $client->getCode()
                ),

                'client' => [
                    'id' =>
                    $client->getId(),

                    'publicId' =>
                    $client
                        ->getPublicId()
                        ->toRfc4122(),

                    'code' =>
                    $client->getCode(),

                    'showUrl' =>
                    $this->generateUrl(
                        'app_clients_show',
                        [
                            'publicId' =>
                            $client
                                ->getPublicId()
                                ->toRfc4122(),
                        ]
                    ),
                ],
            ],
            Response::HTTP_CREATED
        );
    }

    #[Route(
        '/{id}/formulaire-modification',
        name: 'app_clients_edit_form',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function editForm(
        Clients $client
    ): Response {
        $form = $this->createForm(
            ClientsType::class,
            $client,
            [
                'action' => $this->generateUrl(
                    'app_clients_edit',
                    ['id' => $client->getId()]
                ),
                'method' => 'POST',
            ]
        );

        return $this->render(
            'clients/_modal_form.html.twig',
            [
                'form' => $form->createView(),
                'client' => $client,
                'mode' => 'edit',
            ]
        );
    }

    #[Route(
        '/{id}/edit',
        name: 'app_clients_edit',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['POST']
    )]
    public function edit(
        Request $request,
        Clients $client,
        EntityManagerInterface $entityManager,
        ClientsRepository $clientsRepository
    ): JsonResponse {
        $form = $this->createForm(
            ClientsType::class,
            $client
        );

        $form->handleRequest(
            $request
        );


        /*
     * ============================================================
     * FORMULAIRE
     * ============================================================
     */

        if (!$form->isSubmitted()) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Le formulaire n’a pas été soumis.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
     * ============================================================
     * DROIT B2B
     * ============================================================
     */

        if (
            !$this->isGranted('ROLE_ADMIN')
            &&
            $client->isB2B()
        ) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Vous n’êtes pas autorisé à définir un client en B2B.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
     * ============================================================
     * VALIDATION IDENTITÉ
     * ============================================================
     */

        $this->validerIdentiteClient(
            $form,
            $client
        );


        /*
     * ============================================================
     * CONTRÔLE DU TÉLÉPHONE
     * ============================================================
     */

        $telephone =
            trim(
                (string)
                $client->getTelephone()
            );


        if (
            $telephone !== ''
            &&
            $clientsRepository
            ->telephoneExistePourAutreClient(
                $telephone,
                $client->getId()
            )
        ) {
            if ($form->has('telephone')) {
                $form
                    ->get('telephone')
                    ->addError(
                        new FormError(
                            'Un autre client possède déjà ce numéro de téléphone.'
                        )
                    );
            }


            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Ce numéro de téléphone est déjà utilisé par un autre client.',

                    'errors' => [
                        'telephone' => [
                            'Un autre client possède déjà ce numéro de téléphone.',
                        ],
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        /*
     * ============================================================
     * VALIDATION DU FORMULAIRE
     * ============================================================
     */

        if (!$form->isValid()) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Veuillez corriger les champs indiqués.',

                    'errors' =>
                    $this->getFormErrors(
                        $form
                    ),
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        /*
     * ============================================================
     * SAUVEGARDE
     * ============================================================
     */

        try {
            $entityManager->flush();
        } catch (
            UniqueConstraintViolationException $e
        ) {
            return $this->json(
                [
                    'success' => false,

                    'message' =>
                    'Ce numéro de téléphone est déjà utilisé par un autre client.',

                    'errors' => [
                        'telephone' => [
                            'Un autre client possède déjà ce numéro de téléphone.',
                        ],
                    ],
                ],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }


        /*
     * ============================================================
     * SUCCÈS
     * ============================================================
     */

        return $this->json([
            'success' => true,

            'message' => sprintf(
                'Le client %s a été modifié avec succès.',
                $client->getCode()
            ),
        ]);
    }

    #[Route(
        '/{id}/toggle-statut',
        name: 'app_clients_toggle_statut',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function toggleStatut(
        Clients $client,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        ) ?? [];

        if (!$this->isCsrfTokenValid(
            'toggle_client_' . $client->getId(),
            $data['_token'] ?? ''
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $client->setStatut(!$client->isStatut());

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'statut' => $client->isStatut(),
            'message' => $client->isStatut()
                ? 'Le client a été débloqué.'
                : 'Le client a été bloqué.',
        ]);
    }

    #[Route(
        '/{id}/solde/ajouter',
        name: 'app_clients_solde_ajouter',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function ajouterSolde(
        Clients $client,
        Request $request,
        EntityManagerInterface $entityManager,
        CompteTresorerieRepository $compteTresorerieRepository,
        MouvementTresorerieService $mouvementTresorerieService
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException(
                'Vous devez être connecté pour modifier le solde d’un client.'
            );
        }

        if (!$this->isCsrfTokenValid(
            'ajouter_solde_client_' . $client->getId(),
            $request->request->get('_token')
        )) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_clients_show', [
                'publicId' => $client->getPublicId()->toRfc4122(),
            ]);
        }

        $montant = (int) preg_replace(
            '/[^\d]/',
            '',
            (string) $request->request->get('montant')
        );

        if ($montant <= 0) {
            $this->addFlash(
                'warning',
                'Le montant doit être supérieur à zéro.'
            );

            return $this->redirectToRoute('app_clients_show', [
                'publicId' => $client->getPublicId()->toRfc4122(),
            ]);
        }

        /*
         * ============================================================
         * COMPTE DE TRÉSORERIE
         * ============================================================
         *
         * L'argent gardé (monnaie non rendue) est physiquement dans
         * un compte précis (caisse, Orange Money, Wave...) : ce
         * compte doit être crédité, sinon son solde enregistré ne
         * correspondra plus jamais à l'argent réellement présent.
         * ============================================================
         */
        $compteId = (int) $request->request->get('compteTresorerie');

        $compte = $compteId > 0
            ? $compteTresorerieRepository->find($compteId)
            : null;

        if ($compte === null) {
            $this->addFlash(
                'warning',
                'Veuillez sélectionner le compte de trésorerie où se trouve cet argent.'
            );

            return $this->redirectToRoute('app_clients_show', [
                'publicId' => $client->getPublicId()->toRfc4122(),
            ]);
        }

        $comptesAutorises = $compteTresorerieRepository->trouverDisponiblesPour(
            $user,
            $this->isGranted('ROLE_ADMIN')
        );

        if (!in_array($compte, $comptesAutorises, true)) {
            $this->addFlash(
                'error',
                'Vous n’êtes pas autorisé à utiliser ce compte de trésorerie.'
            );

            return $this->redirectToRoute('app_clients_show', [
                'publicId' => $client->getPublicId()->toRfc4122(),
            ]);
        }

        $soldeAvant = $client->getSoldeCredit();

        $client->setSoldeCredit($soldeAvant + $montant);

        $entityManager->persist($client);

        /*
         * ============================================================
         * TRACE
         * ============================================================
         *
         * En plus du mouvement de trésorerie ci-dessous (qui prouve
         * l'entrée d'argent sur le compte), le journal d'activité
         * garde une trace lisible depuis la fiche du client.
         * ============================================================
         */
        $journal = new JournalActivite();
        $journal
            ->setEntite('Clients')
            ->setEntiteId($client->getId())
            ->setAction(JournalActivite::ACTION_MODIFICATION)
            ->setDonneesAvant([
                'soldeCredit' => $soldeAvant,
            ])
            ->setDonneesApres([
                'soldeCredit' => $client->getSoldeCredit(),
                'montantAjoute' => $montant,
                'compteTresorerieId' => $compte->getId(),
            ])
            ->setUtilisateur($user);

        $entityManager->persist($journal);

        /*
         * ============================================================
         * MOUVEMENT DE TRÉSORERIE
         * ============================================================
         *
         * Catégorie "ajustement" (neutre) : cet argent est déjà
         * arrivé avec un paiement antérieur, ce n'est pas une
         * nouvelle vente -- seul le compte doit refléter l'argent
         * physiquement présent, sans gonfler le chiffre d'affaires.
         * ============================================================
         */
        $mouvement = new MouvementTresorerie();
        $mouvement->setType(MouvementTresorerie::TYPE_ENCAISSEMENT);
        $mouvement->setCategorie(MouvementTresorerie::CATEGORIE_AJUSTEMENT);
        $mouvement->setCompteDestination($compte);
        $mouvement->setMontant($montant);
        $mouvement->setModePaiement(Paiements::MODE_ESPECES);
        $mouvement->setLibelle(
            sprintf('Monnaie non rendue — %s', $client->getNomComplet())
        );
        $mouvement->setDescription(
            sprintf(
                'Ajout de %s FCFA au solde du client %s (monnaie non rendue lors d’un paiement).',
                number_format($montant, 0, ',', ' '),
                $client->getNomComplet()
            )
        );
        $mouvement->setAgent($user);

        try {
            $mouvementTresorerieService->enregistrer($mouvement);
        } catch (\InvalidArgumentException | \LogicException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('app_clients_show', [
                'publicId' => $client->getPublicId()->toRfc4122(),
            ]);
        }

        if ($mouvement->isEnAttente()) {
            $this->addFlash(
                'warning',
                sprintf(
                    'Solde du client mis à jour : +%s FCFA. Le compte "%s" étant une banque, le crédit reste en attente de validation.',
                    number_format($montant, 0, ',', ' '),
                    $compte->getNom()
                )
            );
        } else {
            $this->addFlash(
                'success',
                sprintf(
                    'Solde du client mis à jour : +%s FCFA (crédité sur %s).',
                    number_format($montant, 0, ',', ' '),
                    $compte->getNom()
                )
            );
        }

        return $this->redirectToRoute('app_clients_show', [
            'publicId' => $client->getPublicId()->toRfc4122(),
        ]);
    }

    #[Route(
        '/fiche/{publicId}',
        name: 'app_clients_show',
        methods: ['GET']
    )]
    public function show(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        CommandesRepository $commandesRepository,
        DevisRepository $devisRepository,
        CompteTresorerieRepository $compteTresorerieRepository
    ): Response {
        /*
     * Puis tu gardes ici tout le reste
     * de notre méthode show :
     *
     * commandes
     * devis
     * paiements
     * statistiques
     * crédit
     */
        /*
     * ============================================================
     * COMMANDES DU CLIENT
     * ============================================================
     */

        $commandes =
            $commandesRepository->findBy(
                [
                    'clients' => $client,
                    'deleted' => false,
                ],
                [
                    'dateCommande' => 'DESC',
                ]
            );


        /*
     * ============================================================
     * DEVIS DU CLIENT
     * ============================================================
     */

        $devis =
            $devisRepository->findBy(
                [
                    'clients' => $client,
                    'deleted' => false,
                ],
                [
                    'dateDevis' => 'DESC',
                ]
            );


        /*
     * ============================================================
     * STATISTIQUES
     * ============================================================
     */

        $totalCommandes = 0;

        $totalPaye = 0;

        $resteAPayer = 0;

        $nombreCommandes = 0;

        $nombreDevis = count($devis);

        $nombrePaiements = 0;


        /*
     * Tous les paiements du client.
     */
        $paiements = [];


        /*
     * Données détaillées par commande.
     */
        $commandesAvecPaiements = [];


        foreach ($commandes as $commande) {

            /*
         * Une commande entièrement annulée ne doit pas fausser les
         * statistiques du client (même calcul que ClientsController::
         * pdfImpayes(), qui exclut déjà les commandes annulées).
         */
            if ($commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            ++$nombreCommandes;

            $montantCommande =
                (int) $commande->getTotalTtc();


            $totalCommandes +=
                $montantCommande;


            /*
         * ========================================================
         * PAIEMENTS VALIDÉS DE CETTE COMMANDE
         * ========================================================
         *
         * IMPORTANT :
         * on ne compte PAS :
         *
         * - paiement annulé ;
         * - paiement rejeté ;
         * - paiement encore en attente.
         * ========================================================
         */

            $totalPayeCommande = 0;

            $paiementsCommande = [];


            foreach (
                $commande->getPaiements()
                as $paiement
            ) {
                /*
             * On ajoute le paiement dans l'historique,
             * quel que soit son statut.
             */
                $paiements[] =
                    $paiement;

                $paiementsCommande[] =
                    $paiement;


                if (
                    $paiement->getStatut()
                    !==
                    Paiements::STATUT_VALIDE
                ) {
                    continue;
                }


                $montantPaiement =
                    (int) $paiement->getMontant();


                $totalPayeCommande +=
                    $montantPaiement;

                $totalPaye +=
                    $montantPaiement;

                ++$nombrePaiements;
            }


            /*
         * Reste réellement dû sur la commande.
         */
            $resteCommande =
                max(
                    0,
                    $montantCommande
                        -
                        $totalPayeCommande
                );


            $resteAPayer +=
                $resteCommande;


            /*
         * Statut calculé dynamiquement.
         */
            if ($totalPayeCommande <= 0) {
                $statutPaiement =
                    'impayee';
            } elseif (
                $totalPayeCommande
                <
                $montantCommande
            ) {
                $statutPaiement =
                    'partielle';
            } else {
                $statutPaiement =
                    'payee';
            }


            $commandesAvecPaiements[] = [
                'commande' =>
                $commande,

                'totalTtc' =>
                $montantCommande,

                'totalPaye' =>
                $totalPayeCommande,

                'resteAPayer' =>
                $resteCommande,

                'statutPaiement' =>
                $statutPaiement,

                'paiements' =>
                $paiementsCommande,
            ];
        }


        /*
     * ============================================================
     * TRI DES PAIEMENTS
     * ============================================================
     *
     * Plus récent en premier.
     * ============================================================
     */

        usort(
            $paiements,
            static function (
                Paiements $a,
                Paiements $b
            ): int {
                $dateA =
                    $a->getDate()
                    ?->getTimestamp()
                    ?? 0;

                $dateB =
                    $b->getDate()
                    ?->getTimestamp()
                    ?? 0;

                return $dateB <=> $dateA;
            }
        );


        /*
     * ============================================================
     * CRÉDIT CLIENT
     * ============================================================
     */

        $plafondCredit =
            max(
                0,
                (int) $client->getPlafondCredit()
            );


        /*
     * L'encours représente ce que le client
     * doit encore à l'entreprise.
     */
        $encours =
            $resteAPayer;


        /*
     * Crédit encore disponible.
     */
        $creditDisponible =
            max(
                0,
                $plafondCredit
                    -
                    $encours
            );


        /*
     * Montant éventuel de dépassement.
     */
        $depassementCredit =
            max(
                0,
                $encours
                    -
                    $plafondCredit
            );


        /*
     * Pourcentage d'utilisation.
     */
        $pourcentageCredit =
            $plafondCredit > 0
            ? min(
                100,
                (int) round(
                    (
                        $encours
                        /
                        $plafondCredit
                    )
                        * 100
                )
            )
            : 0;


        /*
     * ============================================================
     * DEVIS : STATISTIQUES
     * ============================================================
     */

        $totalDevis = 0;

        foreach ($devis as $unDevis) {
            $totalDevis +=
                (int) $unDevis->getTotalTtc();
        }


        /*
     * ============================================================
     * COMPTES DE TRÉSORERIE (pour "Ajouter au solde")
     * ============================================================
     *
     * L'argent gardé (monnaie non rendue) doit être crédité sur un
     * compte réel : on ne propose que les comptes que l'utilisateur
     * connecté a le droit d'utiliser (même règle que le formulaire
     * de paiement).
     * ============================================================
     */

        $comptesTresorerie = [];

        $utilisateurConnecte = $this->getUser();

        if (
            $utilisateurConnecte instanceof User
            && $this->isGranted('ROLE_PAIEMENT_ENCAISSER')
        ) {
            $comptesTresorerie = $compteTresorerieRepository->trouverDisponiblesPour(
                $utilisateurConnecte,
                $this->isGranted('ROLE_ADMIN')
            );
        }


        /*
     * ============================================================
     * ENVOI AU TWIG
     * ============================================================
     */

        return $this->render(
            'clients/show.html.twig',
            [
                'client' =>
                $client,

                'comptesTresorerie' =>
                $comptesTresorerie,

                'commandes' =>
                $commandesAvecPaiements,

                'devis' =>
                $devis,

                'paiements' =>
                $paiements,

                'statistiques' => [
                    'nombreCommandes' =>
                    $nombreCommandes,

                    'nombreDevis' =>
                    $nombreDevis,

                    'nombrePaiements' =>
                    $nombrePaiements,

                    'totalCommandes' =>
                    $totalCommandes,

                    'totalDevis' =>
                    $totalDevis,

                    'totalPaye' =>
                    $totalPaye,

                    'resteAPayer' =>
                    $resteAPayer,

                    'encours' =>
                    $encours,
                ],

                'credit' => [
                    'plafond' =>
                    $plafondCredit,

                    'encours' =>
                    $encours,

                    'disponible' =>
                    $creditDisponible,

                    'depassement' =>
                    $depassementCredit,

                    'pourcentage' =>
                    $pourcentageCredit,
                ],
            ]
        );
    }

    #[Route(
        '/fiche/{publicId}/impayes.pdf',
        name: 'app_clients_pdf_impayes',
        methods: ['GET']
    )]
    public function pdfImpayes(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        CommandesRepository $commandesRepository
    ): Response {
        $commandes = $commandesRepository->findBy(
            [
                'clients' => $client,
                'deleted' => false,
            ],
            [
                'dateCommande' => 'DESC',
            ]
        );

        /*
         * Même calcul que ClientsController::show() : le
         * statut réel du paiement se calcule à partir des
         * paiements validés, jamais depuis un champ figé
         * (montantApayer/statutPaiement en base ne sont pas
         * fiables, voir CommandesRepository).
         */
        $commandesImpayees = [];
        $totalCommandes = 0;
        $totalPaye = 0;
        $totalReste = 0;

        foreach ($commandes as $commande) {
            if ($commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $montantCommande = (int) $commande->getTotalTtc();
            $totalPayeCommande = 0;

            foreach ($commande->getPaiements() as $paiement) {
                if ($paiement->getStatut() !== Paiements::STATUT_VALIDE) {
                    continue;
                }

                $totalPayeCommande += (int) $paiement->getMontant();
            }

            $resteCommande = max(0, $montantCommande - $totalPayeCommande);

            if ($resteCommande <= 0) {
                continue;
            }

            $commandesImpayees[] = [
                'commande' => $commande,
                'totalTtc' => $montantCommande,
                'totalPaye' => $totalPayeCommande,
                'resteAPayer' => $resteCommande,
            ];

            $totalCommandes += $montantCommande;
            $totalPaye += $totalPayeCommande;
            $totalReste += $resteCommande;
        }

        $projectDir = $this->getParameter('kernel.project_dir');

        $html = $this->renderView(
            'clients/pdf_impayes.html.twig',
            [
                'client' => $client,
                'commandes' => $commandesImpayees,
                'totalCommandes' => $totalCommandes,
                'totalPaye' => $totalPaye,
                'totalReste' => $totalReste,
                'genereLe' => new \DateTimeImmutable(),
                'logo' => $this->imageVersDataUri(
                    $projectDir . '/public/assets/images/brand/logo2.png'
                ),
            ]
        );

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $contenuPdf = $dompdf->output();

        return new Response(
            $contenuPdf,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf(
                    'inline; filename="impayes-%s.pdf"',
                    preg_replace('/[^A-Za-z0-9_-]/', '-', $client->getNomComplet())
                ),
                'Content-Length' => (string) strlen($contenuPdf),
            ]
        );
    }

    /**
     * Export PDF de l'historique complet des commandes du client
     * (payées, partielles et impayées), contrairement à pdfImpayes()
     * qui n'y met que celles avec un reste à payer.
     */
    #[Route(
        '/fiche/{publicId}/commandes.pdf',
        name: 'app_clients_pdf_commandes',
        methods: ['GET']
    )]
    public function pdfToutesCommandes(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        CommandesRepository $commandesRepository
    ): Response {
        $commandes = $commandesRepository->findBy(
            [
                'clients' => $client,
                'deleted' => false,
            ],
            [
                'dateCommande' => 'DESC',
            ]
        );

        $commandesAvecPaiements = [];
        $totalCommandes = 0;
        $totalPaye = 0;
        $totalReste = 0;

        foreach ($commandes as $commande) {
            if ($commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $montantCommande = (int) $commande->getTotalTtc();
            $totalPayeCommande = 0;

            foreach ($commande->getPaiements() as $paiement) {
                if ($paiement->getStatut() !== Paiements::STATUT_VALIDE) {
                    continue;
                }

                $totalPayeCommande += (int) $paiement->getMontant();
            }

            $resteCommande = max(0, $montantCommande - $totalPayeCommande);

            if ($totalPayeCommande <= 0) {
                $statutPaiement = 'impayee';
            } elseif ($totalPayeCommande < $montantCommande) {
                $statutPaiement = 'partielle';
            } else {
                $statutPaiement = 'payee';
            }

            $commandesAvecPaiements[] = [
                'commande' => $commande,
                'totalTtc' => $montantCommande,
                'totalPaye' => $totalPayeCommande,
                'resteAPayer' => $resteCommande,
                'statutPaiement' => $statutPaiement,
            ];

            $totalCommandes += $montantCommande;
            $totalPaye += $totalPayeCommande;
            $totalReste += $resteCommande;
        }

        $projectDir = $this->getParameter('kernel.project_dir');

        $html = $this->renderView(
            'clients/pdf_commandes.html.twig',
            [
                'client' => $client,
                'commandes' => $commandesAvecPaiements,
                'totalCommandes' => $totalCommandes,
                'totalPaye' => $totalPaye,
                'totalReste' => $totalReste,
                'genereLe' => new \DateTimeImmutable(),
                'logo' => $this->imageVersDataUri(
                    $projectDir . '/public/assets/images/brand/logo2.png'
                ),
            ]
        );

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $contenuPdf = $dompdf->output();

        return new Response(
            $contenuPdf,
            Response::HTTP_OK,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => sprintf(
                    'inline; filename="commandes-%s.pdf"',
                    preg_replace('/[^A-Za-z0-9_-]/', '-', $client->getNomComplet())
                ),
                'Content-Length' => (string) strlen($contenuPdf),
            ]
        );
    }

    private function imageVersDataUri(string $chemin): ?string
    {
        if (!is_file($chemin) || !is_readable($chemin)) {
            return null;
        }

        $contenu = file_get_contents($chemin);

        if ($contenu === false) {
            return null;
        }

        $mime = mime_content_type($chemin);

        if (!$mime) {
            $mime = 'image/png';
        }

        return sprintf(
            'data:%s;base64,%s',
            $mime,
            base64_encode($contenu)
        );
    }

    #[Route(
        '/{id}',
        name: 'app_clients_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        Request $request,
        Clients $client,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $client->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $entityManager->remove($client);
            $entityManager->flush();
        }

        return $this->redirectToRoute(
            'app_clients_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    private function validerIdentiteClient(
        FormInterface $form,
        Clients $client
    ): void {
        if (
            $client->isEntreprise()
            && trim((string) $client->getRaisonSociale()) === ''
        ) {
            $form->get('raisonSociale')->addError(
                new FormError(
                    'La raison sociale est obligatoire pour un compte entreprise.'
                )
            );
        }

        /*
         * Le nom/prénom identifient le client particulier,
         * ou la personne responsable pour un compte entreprise :
         * toujours requis, quel que soit le type de compte.
         */
        if (
            trim((string) $client->getNom()) === ''
            && trim((string) $client->getPrenom()) === ''
        ) {
            $form->get('nom')->addError(
                new FormError(
                    'Indiquez au moins le nom ou le prénom du client.'
                )
            );
        }
    }

    private function getFormErrors(
        FormInterface $form
    ): array {
        $errors = [];

        foreach ($form->getErrors(true) as $error) {
            $origine = $error->getOrigin();

            $champ = $origine !== null
                ? $origine->getName()
                : 'formulaire';

            $errors[$champ][] = $error->getMessage();
        }

        return $errors;
    }
    #[Route(
        '/datatable',
        name: 'app_clients_datatable',
        methods: ['GET']
    )]
    public function datatable(
        Request $request,
        ClientsRepository $clientsRepository
    ): JsonResponse {
        $draw = max(
            0,
            $request->query->getInt('draw')
        );

        $start = max(
            0,
            $request->query->getInt('start')
        );

        $length = $request->query->getInt('length', 10);

        if (!in_array($length, [10, 25, 50, 100], true)) {
            $length = 10;
        }

        $searchData = $request->query->all('search');
        $search = trim((string) ($searchData['value'] ?? ''));

        $resultat = $clientsRepository->rechercherPourDataTable(
            $start,
            $length,
            $search
        );

        $data = [];

        foreach ($resultat['clients'] as $client) {
            $nomClient = trim(
                (string) (
                    $client->getRaisonSociale()
                    ?: $client->getPrenom() . ' ' . $client->getNom()
                )
            );

            $data[] = [
                'id' => $client->getId(),
                'code' => $client->getCode(),
                'client' => $nomClient ?: '-',
                'typeClient' => $client->getTypeClient(),
                'telephone' => $client->getTelephone() ?: '-',
                'telephone2' => $client->getTelephone2(),
                'email' => $client->getEmail() ?: '-',
                'ville' => $client->getVille() ?: '-',
                'nif' => $client->getNif() ?: '-',
                'rccm' => $client->getRccm() ?: '-',
                'plafondCredit' => $client->getPlafondCredit(),
                'statut' => $client->isStatut(),
                'createdAt' => $client->getCreatedAt()
                    ? $client->getCreatedAt()->format('d/m/Y H:i')
                    : '-',

                'showUrl' => $this->generateUrl(
                    'app_clients_show',
                    [
                        'publicId' =>
                        $client->getPublicId()
                            ->toRfc4122(),
                    ]
                ),

                'editFormUrl' => $this->generateUrl(
                    'app_clients_edit_form',
                    ['id' => $client->getId()]
                ),

                'toggleUrl' =>
                $this->isGranted('ROLE_ADMIN')
                    ? $this->generateUrl(
                        'app_clients_toggle_statut',
                        [
                            'id' => $client->getId(),
                        ]
                    )
                    : null,

                'toggleToken' =>
                $this->isGranted('ROLE_ADMIN')
                    ? $this->container
                    ->get('security.csrf.token_manager')
                    ->getToken(
                        'toggle_client_' . $client->getId()
                    )
                    ->getValue()
                    : null,
            ];
        }

        return $this->json([
            'draw' => $draw,
            'recordsTotal' => $resultat['total'],
            'recordsFiltered' => $resultat['filtered'],
            'data' => $data,
        ]);
    }
}
