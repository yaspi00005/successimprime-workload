<?php

namespace App\Controller;

use App\Entity\Finition;
use App\Entity\TypesImpression;
use App\Form\FinitionType;
use App\Repository\FinitionRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/finition')]
final class FinitionController extends AbstractController
{
    /*
     * =========================================================
     * LISTE
     * =========================================================
     */

    #[Route(
    '',
    name: 'app_finition_index',
    methods: ['GET']
)]
public function index(
    FinitionRepository $finitionRepository,
    TypesImpressionRepository $typesImpressionRepository
): Response {
    return $this->render('finition/index.html.twig', [
        'finitions' => $finitionRepository->findBy(
            [],
            [
                'ordre' => 'ASC',
                'nom' => 'ASC',
            ]
        ),

        'typesImpressions' => $typesImpressionRepository->findBy(
            ['publie' => true],
            [
                'ordre' => 'ASC',
                'nom' => 'ASC',
            ]
        ),
    ]);
}

    /*
     * =========================================================
     * CRÉATION AJAX
     * =========================================================
     */

    #[Route(
        '/create/ajax',
        name: 'app_finition_create_ajax',
        methods: ['POST']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function createFinitionAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        FinitionRepository $finitionRepository,
        TypesImpressionRepository $typesImpressionRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'create_finition',
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nom = trim(
            (string) ($data['nom'] ?? '')
        );

        $description = trim(
            (string) ($data['description'] ?? '')
        );

        $publie = (bool) ($data['publie'] ?? false);

        $typeIds = $this->nettoyerIds(
            $data['typesImpression'] ?? []
        );

        if ($nom === '') {
            return $this->json([
                'success' => false,
                'message' => 'Le nom de la finition est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($description === '') {
            return $this->json([
                'success' => false,
                'message' => 'La description de la finition est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($typeIds === []) {
            return $this->json([
                'success' => false,
                'message' => 'Sélectionnez au moins un type d’impression.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $finitionExistante = $finitionRepository->findOneBy([
            'nom' => $nom,
        ]);

        if ($finitionExistante !== null) {
            return $this->json([
                'success' => false,
                'message' => 'Cette finition existe déjà.',
            ], Response::HTTP_CONFLICT);
        }

        $typesImpression = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        if (count($typesImpression) !== count($typeIds)) {
            return $this->json([
                'success' => false,
                'message' => 'Un ou plusieurs types d’impression sont invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $derniereFinition = $finitionRepository->findOneBy(
            [],
            ['ordre' => 'DESC']
        );

        $prochainOrdre = $derniereFinition !== null
            ? $derniereFinition->getOrdre() + 10
            : 10;

        $finition = new Finition();

        $finition->setNom($nom);
        $finition->setDescription($description);
        $finition->setPublie($publie);
        $finition->setOrdre($prochainOrdre);

        /*
         * TypesImpression est le côté propriétaire
         * de la relation ManyToMany.
         */
        foreach ($typesImpression as $typeImpression) {
            $typeImpression->addFinition($finition);
        }

        $entityManager->persist($finition);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La finition a été ajoutée avec succès.',

            'finition' => [
                'id' => $finition->getId(),
                'nom' => $finition->getNom(),
                'description' => $finition->getDescription(),
                'publie' => $finition->isPublie(),
                'ordre' => $finition->getOrdre(),

                'typesImpression' => $this->extraireIdsTypesImpression(
                    $finition
                ),
            ],
        ], Response::HTTP_CREATED);
    }

    /*
     * =========================================================
     * ACTIONS DE MASSE
     * La route fixe est placée avant les routes /{id}.
     * =========================================================
     */

    #[Route(
        '/mass-action',
        name: 'app_finition_mass_action',
        methods: ['POST']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function massAction(
        Request $request,
        EntityManagerInterface $entityManager,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'finition_mass_action',
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $action = (string) ($data['action'] ?? '');

        $actionsAutorisees = [
            'publish',
            'unpublish',
            'delete',
        ];

        if (!in_array($action, $actionsAutorisees, true)) {
            return $this->json([
                'success' => false,
                'message' => 'Action non autorisée.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $ids = $this->nettoyerIds(
            $data['ids'] ?? []
        );

        if ($ids === []) {
            return $this->json([
                'success' => false,
                'message' => 'Sélectionnez au moins une finition.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $finitions = $finitionRepository->findBy([
            'id' => $ids,
        ]);

        if (count($finitions) !== count($ids)) {
            return $this->json([
                'success' => false,
                'message' => 'Une ou plusieurs finitions sont introuvables.',
            ], Response::HTTP_NOT_FOUND);
        }

        foreach ($finitions as $finition) {
            switch ($action) {
                case 'publish':
                    $finition->setPublie(true);
                    break;

                case 'unpublish':
                    $finition->setPublie(false);
                    break;

                case 'delete':
                    $this->retirerRelationsTypesImpression(
                        $finition
                    );

                    $entityManager->remove($finition);
                    break;
            }
        }

        $entityManager->flush();

        $nombre = count($finitions);

        $message = match ($action) {
            'publish' => sprintf(
                '%d finition(s) publiée(s) avec succès.',
                $nombre
            ),

            'unpublish' => sprintf(
                '%d finition(s) dépubliée(s) avec succès.',
                $nombre
            ),

            'delete' => sprintf(
                '%d finition(s) supprimée(s) avec succès.',
                $nombre
            ),
        };

        return $this->json([
            'success' => true,
            'message' => $message,
            'action' => $action,
            'count' => $nombre,
            'ids' => $ids,
        ]);
    }

    /*
     * =========================================================
     * CLASSEMENT PAR GLISSER-DÉPOSER
     * =========================================================
     */

    #[Route(
        '/reorder',
        name: 'app_finition_reorder',
        methods: ['POST']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'finition_reorder',
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $ids = $this->nettoyerIds(
            $data['ids'] ?? []
        );

        if ($ids === []) {
            return $this->json([
                'success' => false,
                'message' => 'Aucune finition reçue.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $finitions = $finitionRepository->findBy([
            'id' => $ids,
        ]);

        if (count($finitions) !== count($ids)) {
            return $this->json([
                'success' => false,
                'message' => 'Une ou plusieurs finitions sont introuvables.',
            ], Response::HTTP_NOT_FOUND);
        }

        $finitionsParId = [];

        foreach ($finitions as $finition) {
            $finitionsParId[$finition->getId()] = $finition;
        }

        foreach ($ids as $position => $id) {
            $finitionsParId[$id]->setOrdre(
                ($position + 1) * 10
            );
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'L’ordre des finitions a été enregistré.',
        ]);
    }

    /*
     * =========================================================
     * CHARGEMENT AJAX POUR LE MODAL DE MODIFICATION
     * =========================================================
     */

    #[Route(
        '/{id}/ajax',
        name: 'app_finition_get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function getFinitionAjax(
        Finition $finition
    ): JsonResponse {
        return $this->json([
            'success' => true,

            'finition' => [
                'id' => $finition->getId(),
                'nom' => $finition->getNom(),
                'description' => $finition->getDescription(),
                'publie' => $finition->isPublie(),
                'ordre' => $finition->getOrdre(),

                'typesImpression' => $this->extraireIdsTypesImpression(
                    $finition
                ),
            ],
        ]);
    }

    /*
     * =========================================================
     * MODIFICATION AJAX
     * =========================================================
     */

    #[Route(
        '/{id}/update/ajax',
        name: 'app_finition_update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function updateFinitionAjax(
        Finition $finition,
        Request $request,
        EntityManagerInterface $entityManager,
        FinitionRepository $finitionRepository,
        TypesImpressionRepository $typesImpressionRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $nom = trim(
            (string) ($data['nom'] ?? '')
        );

        $description = trim(
            (string) ($data['description'] ?? '')
        );

        $publie = (bool) ($data['publie'] ?? false);

        $typeIds = $this->nettoyerIds(
            $data['typesImpression'] ?? []
        );

        if ($nom === '') {
            return $this->json([
                'success' => false,
                'message' => 'Le nom de la finition est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($description === '') {
            return $this->json([
                'success' => false,
                'message' => 'La description de la finition est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($typeIds === []) {
            return $this->json([
                'success' => false,
                'message' => 'Sélectionnez au moins un type d’impression.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $finitionExistante = $finitionRepository->findOneBy([
            'nom' => $nom,
        ]);

        if (
            $finitionExistante !== null
            && $finitionExistante->getId() !== $finition->getId()
        ) {
            return $this->json([
                'success' => false,
                'message' => 'Une autre finition porte déjà ce nom.',
            ], Response::HTTP_CONFLICT);
        }

        $typesImpression = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        if (count($typesImpression) !== count($typeIds)) {
            return $this->json([
                'success' => false,
                'message' => 'Un ou plusieurs types d’impression sont invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $finition->setNom($nom);
        $finition->setDescription($description);
        $finition->setPublie($publie);

        /*
         * Retirer les anciennes associations.
         */
        $this->retirerRelationsTypesImpression(
            $finition
        );

        /*
         * Ajouter les nouvelles associations.
         */
        foreach ($typesImpression as $typeImpression) {
            $typeImpression->addFinition($finition);
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La finition a été modifiée avec succès.',

            'finition' => [
                'id' => $finition->getId(),
                'nom' => $finition->getNom(),
                'description' => $finition->getDescription(),
                'publie' => $finition->isPublie(),
                'ordre' => $finition->getOrdre(),

                'typesImpression' => $this->extraireIdsTypesImpression(
                    $finition
                ),
            ],
        ]);
    }

    /*
     * =========================================================
     * PUBLICATION / DÉPUBLICATION
     * =========================================================
     */

    #[Route(
        '/{id}/toggle-publication',
        name: 'app_finition_toggle_publication',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function togglePublication(
        Finition $finition,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'toggle_finition_'.$finition->getId(),
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nouvelEtat = !$finition->isPublie();

        $finition->setPublie($nouvelEtat);

        $entityManager->flush();

        return $this->json([
            'success' => true,

            'message' => $nouvelEtat
                ? 'La finition a été publiée avec succès.'
                : 'La finition a été dépubliée avec succès.',

            'publie' => $nouvelEtat,
        ]);
    }

    /*
     * =========================================================
     * SUPPRESSION AJAX
     * =========================================================
     */

    #[Route(
        '/{id}/delete/ajax',
        name: 'app_finition_delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function deleteFinitionAjax(
        Finition $finition,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'delete_finition_'.$finition->getId(),
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $this->retirerRelationsTypesImpression(
            $finition
        );

        $entityManager->remove($finition);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La finition a été supprimée avec succès.',
        ]);
    }

    /*
     * =========================================================
     * ROUTES CLASSIQUES GÉNÉRÉES PAR SYMFONY
     * =========================================================
     */

    #[Route(
        '/new',
        name: 'app_finition_new',
        methods: ['GET', 'POST']
    )]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $finition = new Finition();

        $form = $this->createForm(
            FinitionType::class,
            $finition
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($finition);
            $entityManager->flush();

            return $this->redirectToRoute(
                'app_finition_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('finition/new.html.twig', [
            'finition' => $finition,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_finition_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(
        Finition $finition
    ): Response {
        return $this->render('finition/show.html.twig', [
            'finition' => $finition,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'app_finition_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function edit(
        Request $request,
        Finition $finition,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(
            FinitionType::class,
            $finition
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute(
                'app_finition_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('finition/edit.html.twig', [
            'finition' => $finition,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_finition_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        Request $request,
        Finition $finition,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete'.$finition->getId(),
            $request->getPayload()->getString('_token')
        )) {
            $this->retirerRelationsTypesImpression(
                $finition
            );

            $entityManager->remove($finition);
            $entityManager->flush();
        }

        return $this->redirectToRoute(
            'app_finition_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    /*
     * =========================================================
     * MÉTHODES PRIVÉES
     * =========================================================
     */

    private function nettoyerIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn (mixed $id): int => (int) $id,
                        $ids
                    ),
                    static fn (int $id): bool => $id > 0
                )
            )
        );
    }

    private function extraireIdsTypesImpression(
        Finition $finition
    ): array {
        return array_values(
            array_map(
                static fn (
                    TypesImpression $typeImpression
                ): int => (int) $typeImpression->getId(),

                $finition
                    ->getTypesImpressions()
                    ->toArray()
            )
        );
    }

    private function retirerRelationsTypesImpression(
        Finition $finition
    ): void {
        foreach (
            $finition->getTypesImpressions()->toArray()
            as $typeImpression
        ) {
            $typeImpression->removeFinition(
                $finition
            );
        }
    }
}