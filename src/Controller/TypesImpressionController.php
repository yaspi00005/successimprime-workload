<?php

namespace App\Controller;

use App\Entity\TypesImpression;
use App\Repository\FinitionRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/types/impression')]
final class TypesImpressionController extends AbstractController
{

    #[Route(
        '',
        name: 'app_type_impression_index',
        methods: ['GET']
    )]
    public function index(
        TypesImpressionRepository $typesImpressionRepository,
        FinitionRepository $finitionRepository
    ): Response {
        $typesImpressions = $typesImpressionRepository->findBy(
            [],
            [
                'ordre' => 'ASC',
                'nom' => 'ASC',
            ]
        );

        $finitions = $finitionRepository->findBy(
            ['publie' => true],
            [
                'ordre' => 'ASC',
                'nom' => 'ASC',
            ]
        );

        return $this->render('types_impression/index.html.twig', [
            'typesImpressions' => $typesImpressions,
            'finitions' => $finitions,
        ]);
    }

    /*
     * AJOUT
     */
    #[Route(
        '/create/ajax',
        name: 'app_type_impression_create_ajax',
        methods: ['POST']
    )]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        TypesImpressionRepository $repository,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'create_type_impression',
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $actif = (bool) ($data['actif'] ?? false);
        $finitionIds = $this->nettoyerIds($data['finitions'] ?? []);

        if ($nom === '') {
            return $this->json([
                'success' => false,
                'message' => 'Le nom du type d’impression est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($repository->findOneBy(['nom' => $nom])) {
            return $this->json([
                'success' => false,
                'message' => 'Ce type d’impression existe déjà.',
            ], Response::HTTP_CONFLICT);
        }

        $dernier = $repository->findOneBy([], ['ordre' => 'DESC']);
        $prochainOrdre = $dernier
            ? $dernier->getOrdre() + 10
            : 10;
        $description = trim((string) ($data['description'] ?? ''));

        if ($description === '') {
            return $this->json([
                'success' => false,
                'message' => 'La description est obligatoire.',
            ], 400);
        }

        $typeImpression = new TypesImpression();
        $typeImpression->setNom($nom);
        $typeImpression->setDescription(
            $description !== '' ? $description : null
        );
        $typeImpression->setPublie($actif);
        $typeImpression->setOrdre($prochainOrdre);

        foreach ($finitionRepository->findBy(['id' => $finitionIds]) as $finition) {
            $typeImpression->addFinition($finition);
        }

        $entityManager->persist($typeImpression);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le type d’impression a été ajouté avec succès.',
            'typeImpression' => [
                'id' => $typeImpression->getId(),
                'nom' => $typeImpression->getNom(),
                'publie' => $typeImpression->isPublie(),
                'ordre' => $typeImpression->getOrdre(),
            ],
        ], Response::HTTP_CREATED);
    }

    /*
     * CHARGEMENT POUR LE MODAL
     */
    #[Route(
        '/{id}/ajax',
        name: 'app_type_impression_get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function getAjax(
        TypesImpression $typesImpression
    ): JsonResponse {
        return $this->json([
            'success' => true,
            'typeImpression' => [
                'id' => $typesImpression->getId(),
                'nom' => $typesImpression->getNom(),
                'description' => $typesImpression->getDescription(),
                'publie' => $typesImpression->isPublie(),
                'ordre' => $typesImpression->getOrdre(),
                'finitions' => array_map(
                    static fn($finition): ?int => $finition->getId(),
                    $typesImpression->getFinitions()->toArray()
                ),
            ],
        ]);
    }

    /*
     * MODIFICATION
     */
    #[Route(
        '/{id}/update/ajax',
        name: 'app_type_impression_update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function updateAjax(
        TypesImpression $typeImpression,
        Request $request,
        EntityManagerInterface $entityManager,
        TypesImpressionRepository $repository,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $actif = (bool) ($data['actif'] ?? false);
        $finitionIds = $this->nettoyerIds($data['finitions'] ?? []);

        if ($nom === '') {
            return $this->json([
                'success' => false,
                'message' => 'Le nom du type d’impression est obligatoire.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $existant = $repository->findOneBy(['nom' => $nom]);

        if (
            $existant !== null &&
            $existant->getId() !== $typeImpression->getId()
        ) {
            return $this->json([
                'success' => false,
                'message' => 'Un autre type d’impression porte déjà ce nom.',
            ], Response::HTTP_CONFLICT);
        }

        $typeImpression->setNom($nom);
        $typeImpression->setDescription(
            $description !== '' ? $description : null
        );
        $typeImpression->setPublie($actif);

        foreach ($typeImpression->getFinitions()->toArray() as $finition) {
            $typeImpression->removeFinition($finition);
        }

        foreach ($finitionRepository->findBy(['id' => $finitionIds]) as $finition) {
            $typeImpression->addFinition($finition);
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le type d’impression a été modifié avec succès.',
        ]);
    }

    /*
     * PUBLICATION/DÉPUBLICATION
     */
    #[Route(
        '/{id}/toggle-publication',
        name: 'app_type_impression_toggle_publication',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function togglePublication(
        TypesImpression $typeImpression,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (
            !is_array($data) ||
            !$this->isCsrfTokenValid(
                'toggle_type_impression_' . $typeImpression->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nouvelEtat = !$typeImpression->isPublie();

        $typeImpression->setPublie($nouvelEtat);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => $nouvelEtat
                ? 'Le type d’impression a été publié.'
                : 'Le type d’impression a été dépublié.',
            'publie' => $nouvelEtat,
        ]);
    }

    /*
     * SUPPRESSION
     */
    #[Route(
        '/{id}/delete/ajax',
        name: 'app_type_impression_delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    public function deleteAjax(
        TypesImpression $typeImpression,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (
            !is_array($data) ||
            !$this->isCsrfTokenValid(
                'delete_type_impression_' . $typeImpression->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($typeImpression);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le type d’impression a été supprimé.',
        ]);
    }

    /*
     * ACTIONS DE MASSE
     */
    #[Route(
        '/mass-action',
        name: 'app_type_impression_mass_action',
        methods: ['POST']
    )]
    public function massAction(
        Request $request,
        EntityManagerInterface $entityManager,
        TypesImpressionRepository $repository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'success' => false,
                'message' => 'Données JSON invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'type_impression_mass_action',
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $action = $data['action'] ?? '';
        $ids = $this->nettoyerIds($data['ids'] ?? []);

        if (!in_array($action, ['publish', 'unpublish', 'delete'], true)) {
            return $this->json([
                'success' => false,
                'message' => 'Action non autorisée.',
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($ids === []) {
            return $this->json([
                'success' => false,
                'message' => 'Sélectionnez au moins un type d’impression.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $types = $repository->findBy(['id' => $ids]);

        foreach ($types as $typeImpression) {
            match ($action) {
                'publish' => $typeImpression->setPublie(true),
                'unpublish' => $typeImpression->setPublie(false),
                'delete' => $entityManager->remove($typeImpression),
            };
        }

        $entityManager->flush();

        $message = match ($action) {
            'publish' => count($types) . ' type(s) publié(s).',
            'unpublish' => count($types) . ' type(s) dépublié(s).',
            'delete' => count($types) . ' type(s) supprimé(s).',
        };

        return $this->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /*
     * CLASSEMENT
     */
    #[Route(
        '/reorder',
        name: 'app_type_impression_reorder',
        methods: ['POST']
    )]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        TypesImpressionRepository $repository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (
            !is_array($data) ||
            !$this->isCsrfTokenValid(
                'type_impression_reorder',
                $data['_token'] ?? null
            )
        ) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $ids = $this->nettoyerIds($data['ids'] ?? []);

        if ($ids === []) {
            return $this->json([
                'success' => false,
                'message' => 'Aucun type d’impression reçu.',
            ], Response::HTTP_BAD_REQUEST);
        }

        foreach ($ids as $position => $id) {
            $typeImpression = $repository->find($id);

            if ($typeImpression !== null) {
                $typeImpression->setOrdre(($position + 1) * 10);
            }
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le classement a été enregistré.',
        ]);
    }

    private function nettoyerIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(
                static fn(mixed $id): int => (int) $id,
                $ids
            ),
            static fn(int $id): bool => $id > 0
        )));
    }
}
