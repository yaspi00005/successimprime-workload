<?php

namespace App\Controller;

use App\Service\Clients\ListeClients;
use App\Entity\Clients;
use App\Form\ClientsType;
use App\Service\FicheClient360;
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
use App\Repository\CommandesRepository;
use App\Repository\DevisRepository;
use App\Repository\CompteTresorerieRepository;
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
    public function index(Request $request, ListeClients $liste): Response
    {
        $vue = $request->query->get('vue') === 'cartes' || ($request->query->get('vue') === null && $request->cookies->get('si_clients_vue') === 'cartes') ? 'cartes' : 'liste';
        $filtres = [
            'q' => trim((string) $request->query->get('q', '')),
            'type' => (string) $request->query->get('type', ''),
            'statut' => (string) $request->query->get('statut', ''),
            'suivi' => (int) $request->query->get('suivi', 0),
            'tri' => (string) $request->query->get('tri', 'derniere'),
            'page' => (int) $request->query->get('page', 1),
        ];
        $donnees = $liste->donnees($filtres, new \DateTimeImmutable(), $this->isGranted('ROLE_STATS_VENTES'), $this->isGranted('ROLE_PAIEMENT_VOIR'));

        $reponse = $this->render('clients/index.html.twig', $donnees + ['filtres' => $filtres, 'vue' => $vue]);
        if ($request->query->get('vue') !== null) {
            $reponse->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('si_clients_vue', $vue, new \DateTimeImmutable('+1 year')));
        }

        return $reponse;
    }

    /*
     * ============================================================
     * SOLDES CLIENTS
     * ============================================================
     *
     * Clients chez qui de l'argent est resté (monnaie gardée, dépôts :
     * docs/finances.md, « Compte du client ») : répond à « chez qui
     * l'argent est-il resté ? ». Filtres et export pour la caisse.
     * ============================================================
     */
    #[Route(
        '/soldes',
        name: 'app_clients_soldes',
        methods: ['GET']
    )]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function soldes(Request $request, ClientsRepository $clientsRepository, \App\Service\Paiement\SoldeClient $soldeClient, EntityManagerInterface $em): Response
    {
        $filtres = self::filtresSoldes($request);
        $clients = $clientsRepository->trouverAvecSoldeCredit($filtres['q'], $filtres['minimum'], $filtres['type']);

        return $this->render('clients/soldes.html.twig', [
            'clients' => $clients,
            'total' => array_sum(array_map(static fn (Clients $c): int => $c->getSoldeCredit(), $clients)),
            'derniers' => self::derniersMouvements($em, $clients),
            'depots' => $soldeClient->depotsEnAttente(),
            'filtres' => $filtres,
        ]);
    }

    /** Même liste en CSV (tableur), avec les mêmes filtres. */
    #[Route(
        '/soldes/export',
        name: 'app_clients_soldes_export',
        methods: ['GET']
    )]
    #[IsGranted('ROLE_PAIEMENT_ENCAISSER')]
    public function exporterSoldes(Request $request, ClientsRepository $clientsRepository, EntityManagerInterface $em): Response
    {
        // Toute la liste d'un coup : avec le droit « Paiements » seulement.
        $this->denyAccessUnlessGranted('ROLE_PAIEMENT_VOIR');
        $filtres = self::filtresSoldes($request);
        $clients = $clientsRepository->trouverAvecSoldeCredit($filtres['q'], $filtres['minimum'], $filtres['type']);
        $derniers = self::derniersMouvements($em, $clients);

        $reponse = new \Symfony\Component\HttpFoundation\StreamedResponse(static function () use ($clients, $derniers): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Code', 'Client', 'Type de compte', 'Téléphone', 'Solde client (F)', 'Dernier mouvement'], ';', '"', '');
            foreach ($clients as $c) {
                fputcsv($sortie, [
                    $c->getCode(), $c->getNomComplet(), Clients::TYPES_COMPTE_LABELS[$c->getTypeCompte()] ?? $c->getTypeCompte(), $c->getTelephone() ?? '',
                    $c->getSoldeCredit(), isset($derniers[(int) $c->getId()]) ? $derniers[(int) $c->getId()]->format('d/m/Y') : '',
                ], ';', '"', '');
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="soldes-clients-'.(new \DateTimeImmutable())->format('Y-m-d').'.csv"');

        return $reponse;
    }

    /** @return array{q: string, minimum: int, type: string} */
    private static function filtresSoldes(Request $request): array
    {
        $type = $request->query->getString('type');

        return [
            'q' => mb_substr(trim($request->query->getString('q')), 0, 80),
            'minimum' => max(0, (int) preg_replace('/\D/', '', $request->query->getString('minimum'))),
            'type' => \in_array($type, Clients::TYPES_COMPTE, true) ? $type : '',
        ];
    }

    /**
     * Date du dernier mouvement du compte de chaque client.
     *
     * @param list<Clients> $clients
     *
     * @return array<int, \DateTimeImmutable>
     */
    private static function derniersMouvements(EntityManagerInterface $em, array $clients): array
    {
        if ([] === $clients) {
            return [];
        }
        $dates = [];
        foreach ($em->createQuery('SELECT IDENTITY(m.client) AS client, MAX(m.creeLe) AS le FROM '.\App\Entity\MouvementSoldeClient::class.' m WHERE m.client IN (:clients) GROUP BY m.client')
            ->setParameter('clients', $clients)->getArrayResult() as $ligne) {
            $dates[(int) $ligne['client']] = new \DateTimeImmutable((string) $ligne['le']);
        }

        return $dates;
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

        // Supprimer en masse : la Direction seulement (bloquer reste au droit Clients).
        if ($action === 'supprimer' && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json([
                'success' => false,
                'message' => 'Seule la direction peut supprimer des clients.',
            ], Response::HTTP_FORBIDDEN);
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

        $gardes = 0;
        foreach ($clients as $client) {
            if ($action === 'bloquer') {
                $client->setStatut(false);
            } elseif ($action === 'debloquer') {
                $client->setStatut(true);
            } elseif ($action === 'supprimer') {
                // Un client avec des commandes ou des devis reste (on le bloque à la place).
                if (self::empecheSuppression($client, $entityManager) !== null) {
                    ++$gardes;
                    continue;
                }
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

        if ($gardes > 0) {
            $messages['supprimer'] = \sprintf('Clients supprimés (30 jours dans la corbeille), sauf %d qui %s des commandes, des devis ou des produits à son nom : bloquez-les à la place.', $gardes, $gardes > 1 ? 'ont' : 'a');
        }

        return $this->json([
            'success' => true,
            'message' => $messages[$action],
        ]);
    }

    /**
     * Pourquoi ce client ne peut pas être supprimé (ses commandes, devis ou
     * produits à son nom le retiennent), ou null s'il peut l'être.
     */
    public static function empecheSuppression(Clients $client, EntityManagerInterface $em): ?string
    {
        if ($client->getId() === null) {
            return null;
        }
        $db = $em->getConnection();
        $id = $client->getId();
        $parties = array_filter([
            (int) $db->fetchOne('SELECT COUNT(*) FROM commandes WHERE clients_id = ?', [$id]) > 0 ? 'des commandes' : null,
            (int) $db->fetchOne('SELECT COUNT(*) FROM devis WHERE clients_id = ?', [$id]) > 0 ? 'des devis' : null,
            (int) $db->fetchOne('SELECT COUNT(*) FROM produit_client WHERE client_id = ?', [$id]) > 0 ? 'des produits à son nom' : null,
        ]);
        if ($parties === []) {
            return null;
        }
        $liste = \count($parties) > 1 ? implode(', ', \array_slice($parties, 0, -1)).' et '.end($parties) : reset($parties);

        return \sprintf('Ce client a %s (même supprimés) : sa fiche ne peut pas être supprimée. Bloquez-le à la place.', $liste);
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

    /**
     * Position du client (fiche client) : coordonnees GPS et repere.
     * Un formulaire vide efface la position.
     */
    #[Route(
        '/fiche/{publicId}/localisation',
        name: 'app_clients_localiser',
        methods: ['POST']
    )]
    public function localiser(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid('localiser-client-'.$client->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }

        $latitude = self::coordonnee($request->getPayload()->getString('latitude'), 90);
        $longitude = self::coordonnee($request->getPayload()->getString('longitude'), 180);

        $client
            ->localiser($latitude, $longitude)
            ->setRepere($request->getPayload()->getString('repere'));

        $entityManager->flush();

        $this->addFlash('success', $client->estLocalise() ? 'Position du client enregistrée.' : 'Position du client effacée.');

        return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId()]);
    }

    private static function coordonnee(string $valeur, int $limite): ?float
    {
        $valeur = str_replace(',', '.', trim($valeur));

        if (!is_numeric($valeur) || abs((float) $valeur) > $limite) {
            return null;
        }

        return (float) $valeur;
    }

    /**
     * Fiche client 360° (maquette 33).
     */
    #[Route(
        '/fiche/{publicId}',
        name: 'app_clients_show',
        methods: ['GET']
    )]
    public function fiche360(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        FicheClient360 $fiche,
        \App\Service\Clients\EditionClient $edition,
        \App\Service\EspaceClient\AccesEspace $accesEspace,
        Request $request,
        \App\Service\Paiement\SoldeClient $soldeClient,
        CompteTresorerieRepository $compteTresorerieRepository,
        \App\Security\AccesEnLigne $accesEnLigne,
        EntityManagerInterface $entityManager
    ): Response {
        $voirPaiements = $this->isGranted('ROLE_PAIEMENT_VOIR');
        $donnees = $fiche->donnees($client, new \DateTimeImmutable(), $voirPaiements);
        // Onglet « Compte » (monnaie gardée, dépôts, docs/finances.md) : qui voit ou encaisse les paiements.
        $compte = null;
        if ($voirPaiements || $this->isGranted('ROLE_PAIEMENT_ENCAISSER')) {
            $compte = $soldeClient->etat($client);
            $compte['comptes'] = [];
            $utilisateur = $this->getUser();
            if ($utilisateur instanceof User && $this->isGranted('ROLE_PAIEMENT_ENCAISSER')) {
                $compte['comptes'] = array_values(array_filter(
                    $compteTresorerieRepository->trouverDisponiblesPour($utilisateur, $this->isGranted('ROLE_ADMIN')),
                    static fn (\App\Entity\CompteTresorerie $c): bool => !$c->isOrangeMoneyEnLigne() && \in_array($c->getType(), \App\Service\Paiement\SoldeClient::TYPES_COMPTES, true),
                ));
            }
            $compte['en_ligne'] = $accesEnLigne->estEnLigne();
            $recu = $request->query->getInt('recu_solde');
            $compte['recu'] = $recu > 0 ? array_values(array_filter($compte['releve'], static fn ($l): bool => $l->getId() === $recu))[0] ?? null : null;
        }
        $kpi = $donnees['kpi'];
        $statut = match (true) {
            !$client->isStatut() => 'bloque',
            $kpi['echues'] > 0 => 'relance',
            0 === $kpi['commandes'] => 'nouveau',
            null !== $kpi['derniere_jours'] && $kpi['derniere_jours'] <= ListeClients::INACTIF_JOURS => 'actif',
            default => 'inactif',
        };
        $whatsapp = $client->getTelephone() ? UtilisateursController::numeroWhatsApp($client->getTelephone()) : null;
        // Espace client : la boutique envoie elle-même le code d'accès sur WhatsApp (accesEspace()).
        $empechement = $accesEspace->empechement($client);

        return $this->render('clients/fiche_360.html.twig', $donnees + [
            // Bouton « Supprimer » (Direction) : ce qui l'empêche, s'il y a lieu.
            'suppression_impossible' => $this->isGranted('ROLE_ADMIN') ? self::empecheSuppression($client, $entityManager) : null,
            'client' => $client,
            'contacts' => $edition->contacts($client),
            'statut' => $statut,
            'invitation' => null === $empechement && $request->query->getBoolean('inviter'),
            'acces_espace' => [
                'possible' => null === $empechement,
                'empechement' => $empechement,
                'dernier' => $accesEspace->dernier($client),
                'maintenant' => new \DateTimeImmutable(),
            ],
            'voir_paiements' => $voirPaiements,
            'compte' => $compte,
            'voir_ca' => $this->isGranted('ROLE_STATS_VENTES'),
            'whatsapp' => $whatsapp,
        ]);
    }

    /**
     * « Envoyer l'accès à l'espace client » (fiche client) : nouveau code
     * d'accès (l'ancien ne sert plus), puis le message à ouvrir dans
     * WhatsApp. Le code ne s'affiche qu'ici, une fois : la page n'est pas
     * gardée par le navigateur et le code n'est enregistré nulle part.
     * Refusé en « Voir » (LectureSeuleSubscriber), pour une fiche de
     * démonstration et sur le site en ligne.
     */
    #[Route(
        '/fiche/{publicId}/acces-espace',
        name: 'app_clients_acces_espace',
        methods: ['POST']
    )]
    public function accesEspace(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        \App\Service\EspaceClient\AccesEspace $accesEspace,
        Request $request
    ): Response {
        if (!$this->isCsrfTokenValid('acces-espace-'.$client->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $empechement = $accesEspace->empechement($client);
        if (null !== $empechement) {
            $this->addFlash('warning', $empechement);

            return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId()]);
        }
        $moi = $this->getUser();
        $acces = $accesEspace->donner($client, $moi instanceof User ? $moi : null, new \DateTimeImmutable());

        $reponse = $this->render('clients/acces_espace.html.twig', [
            'client' => $client,
            'acces' => $acces['acces'],
            'message' => $acces['message'],
            'lien_whatsapp' => $acces['whatsapp'],
            'site_regle' => $accesEspace->siteRegle(),
        ]);
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    /**
     * Fiche détaillée : toutes les commandes et paiements, solde client.
     */
    #[Route(
        '/fiche/{publicId}/detail',
        name: 'app_clients_detail',
        methods: ['GET']
    )]
    public function show(
        #[MapEntity(mapping: [
            'publicId' => 'publicId',
        ])]
        Clients $client,
        CommandesRepository $commandesRepository,
        DevisRepository $devisRepository
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
     * ENVOI AU TWIG
     * ============================================================
     */

        return $this->render(
            'clients/show.html.twig',
            [
                'client' =>
                $client,

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
    // Relevé de ce que le client a payé et doit encore : avec le droit Paiements.
    #[IsGranted('ROLE_PAIEMENT_VOIR')]
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
    // Relevé de ce que le client a payé et doit encore : avec le droit Paiements.
    #[IsGranted('ROLE_PAIEMENT_VOIR')]
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

    /**
     * Supprimer un client : bouton « Supprimer » confirmé de la fiche
     * (Yaya, 18/10/2026), réservé à la Direction, avec un jeton propre aux
     * clients. La fiche part 30 jours dans la corbeille (docs/corbeille.md).
     */
    #[Route(
        '/{id}',
        name: 'app_clients_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(
        Request $request,
        Clients $client,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'supprimer-client-' . $client->getId(),
            $request->getPayload()->getString('_token')
        )) {
            if (($motif = self::empecheSuppression($client, $entityManager)) !== null) {
                $this->addFlash('warning', $motif);

                return $this->redirectToRoute('app_clients_show', ['publicId' => $client->getPublicId()], Response::HTTP_SEE_OTHER);
            }
            $nom = $client->getNomComplet();
            $entityManager->remove($client);
            $entityManager->flush();
            $this->addFlash('success', \sprintf('Client %s supprimé : il reste 30 jours dans la corbeille.', $nom));
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
