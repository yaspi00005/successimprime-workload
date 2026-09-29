<?php

namespace App\Controller;

use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Repository\FinitionRepository;
use App\Repository\FormatRepository;
use App\Repository\SupportsRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/supports')]
#[IsGranted('ROLE_ADMIN')]
final class SupportsController extends AbstractController
{
    #[Route(
        '',
        name: 'app_support_index',
        methods: ['GET']
    )]
    public function index(
        SupportsRepository $supportRepository,
        TypesImpressionRepository $typesImpressionRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): Response {
        return $this->render('supports/index.html.twig', [
            'supports' => $supportRepository->findBy(
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

            'formats' => $formatRepository->findBy(
                ['publie' => true],
                [
                    'ordre' => 'ASC',
                    'nom' => 'ASC',
                ]
            ),

            'finitions' => $finitionRepository->findBy(
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
     * CRÉATION
     * =========================================================
     */

    #[Route(
        '/create/ajax',
        name: 'app_support_create_ajax',
        methods: ['POST']
    )]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        SupportsRepository $supportRepository,
        TypesImpressionRepository $typesImpressionRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'create_support',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $publie = (bool) ($data['publie'] ?? false);

        $typeIds = $this->nettoyerIds(
            $data['typesImpressions'] ?? []
        );

        $formatIds = $this->nettoyerIds(
            $data['formats'] ?? []
        );

        $finitionIds = $this->nettoyerIds(
            $data['finitions'] ?? []
        );

        $erreur = $this->validerSupport(
            $nom,
            $description,
            $typeIds,
            $formatIds
        );

        if ($erreur !== null) {
            return $this->erreurJson(
                $erreur,
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($supportRepository->findOneBy(['nom' => $nom])) {
            return $this->erreurJson(
                'Un support portant ce nom existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        $typesImpressions = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        $formats = $formatRepository->findBy([
            'id' => $formatIds,
        ]);

        $finitions = $finitionRepository->findBy([
            'id' => $finitionIds,
        ]);

        if (count($typesImpressions) !== count($typeIds)) {
            return $this->erreurJson(
                'Un ou plusieurs types d’impression sont invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (count($formats) !== count($formatIds)) {
            return $this->erreurJson(
                'Un ou plusieurs formats sont invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (count($finitions) !== count($finitionIds)) {
            return $this->erreurJson(
                'Une ou plusieurs finitions sont invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $dernierSupport = $supportRepository->findOneBy(
            [],
            ['ordre' => 'DESC']
        );

        $ordre = $dernierSupport !== null
            ? ($dernierSupport->getOrdre() ?? 0) + 10
            : 10;

        $support = new Supports();
        $support->setNom($nom);
        $support->setDescription($description);
        $support->setPublie($publie);
        $support->setOrdre($ordre);

        foreach ($typesImpressions as $typeImpression) {
            $support->addTypeImpression($typeImpression);
        }

        foreach ($formats as $format) {
            $support->addFormat($format);
        }

        foreach ($finitions as $finition) {
            $support->addFinition($finition);
        }

        $entityManager->persist($support);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le support a été ajouté avec succès.',
            'support' => $this->normaliserSupport($support),
        ], Response::HTTP_CREATED);
    }

    /*
     * =========================================================
     * CHARGEMENT POUR MODIFICATION
     * =========================================================
     */

    #[Route(
        '/{id}/ajax',
        name: 'app_support_get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function getAjax(Supports $supports): JsonResponse
    {
        return $this->json([
            'success' => true,
            'support' => $this->normaliserSupport($supports),
        ]);
    }

    /*
     * =========================================================
     * MODIFICATION
     * =========================================================
     */

    #[Route(
        '/{id}/update/ajax',
        name: 'app_support_update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function updateAjax(
        Supports $support,
        Request $request,
        EntityManagerInterface $entityManager,
        SupportsRepository $supportRepository,
        TypesImpressionRepository $typesImpressionRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'update_support_' . $support->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $publie = (bool) ($data['publie'] ?? false);

        $typeIds = $this->nettoyerIds(
            $data['typesImpressions'] ?? []
        );

        $formatIds = $this->nettoyerIds(
            $data['formats'] ?? []
        );

        $finitionIds = $this->nettoyerIds(
            $data['finitions'] ?? []
        );

        $erreur = $this->validerSupport(
            $nom,
            $description,
            $typeIds,
            $formatIds
        );

        if ($erreur !== null) {
            return $this->erreurJson(
                $erreur,
                Response::HTTP_BAD_REQUEST
            );
        }

        $supportExistant = $supportRepository->findOneBy([
            'nom' => $nom,
        ]);

        if (
            $supportExistant !== null
            && $supportExistant->getId() !== $support->getId()
        ) {
            return $this->erreurJson(
                'Un autre support porte déjà ce nom.',
                Response::HTTP_CONFLICT
            );
        }

        $typesImpressions = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        $formats = $formatRepository->findBy([
            'id' => $formatIds,
        ]);

        $finitions = $finitionRepository->findBy([
            'id' => $finitionIds,
        ]);

        if (count($typesImpressions) !== count($typeIds)) {
            return $this->erreurJson(
                'Un ou plusieurs types d’impression sont invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (count($formats) !== count($formatIds)) {
            return $this->erreurJson(
                'Un ou plusieurs formats sont invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (count($finitions) !== count($finitionIds)) {
            return $this->erreurJson(
                'Une ou plusieurs finitions sont invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $support->setNom($nom);
        $support->setDescription($description);
        $support->setPublie($publie);

        $this->viderRelationsSupport($support);

        foreach ($typesImpressions as $typeImpression) {
            $support->addTypeImpression($typeImpression);
        }

        foreach ($formats as $format) {
            $support->addFormat($format);
        }

        foreach ($finitions as $finition) {
            $support->addFinition($finition);
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le support a été modifié avec succès.',
            'support' => $this->normaliserSupport($support),
        ]);
    }

    /*
     * =========================================================
     * PUBLICATION
     * =========================================================
     */

    #[Route(
        '/{id}/toggle-publication',
        name: 'app_support_toggle_publication',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function togglePublication(
        Supports $supports,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (
            !is_array($data)
            || !$this->isCsrfTokenValid(
                'toggle_support_' . $supports->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $nouvelEtat = !$supports->isPublie();

        $supports->setPublie($nouvelEtat);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => $nouvelEtat
                ? 'Le support a été publié.'
                : 'Le support a été dépublié.',
            'publie' => $nouvelEtat,
        ]);
    }

    /*
     * =========================================================
     * SUPPRESSION
     * =========================================================
     */

    #[Route(
        '/{id}/delete/ajax',
        name: 'app_support_delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    public function deleteAjax(
        Supports $supports,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (
            !is_array($data)
            || !$this->isCsrfTokenValid(
                'delete_support_' . $supports->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        if (!$supports->getCommandesDetails()->isEmpty()) {
            return $this->erreurJson(
                'Ce support est utilisé dans une commande. Dépubliez-le au lieu de le supprimer.',
                Response::HTTP_CONFLICT
            );
        }

        $this->viderRelationsSupport($supports);

        $entityManager->remove($supports);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le support a été supprimé.',
        ]);
    }

    /*
     * =========================================================
     * ACTIONS DE MASSE
     * =========================================================
     */

    #[Route(
        '/mass-action',
        name: 'app_support_mass_action',
        methods: ['POST']
    )]
    public function massAction(
        Request $request,
        EntityManagerInterface $entityManager,
        SupportsRepository $supportRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'support_mass_action',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $action = (string) ($data['action'] ?? '');
        $ids = $this->nettoyerIds($data['ids'] ?? []);

        if (!in_array(
            $action,
            ['publish', 'unpublish', 'delete'],
            true
        )) {
            return $this->erreurJson(
                'Action non autorisée.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($ids === []) {
            return $this->erreurJson(
                'Sélectionnez au moins un support.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $supports = $supportRepository->findBy([
            'id' => $ids,
        ]);

        if (count($supports) !== count($ids)) {
            return $this->erreurJson(
                'Un ou plusieurs supports sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        foreach ($supports as $support) {
            if ($action === 'publish') {
                $support->setPublie(true);
            }

            if ($action === 'unpublish') {
                $support->setPublie(false);
            }

            if ($action === 'delete') {
                if (!$support->getCommandesDetails()->isEmpty()) {
                    return $this->erreurJson(
                        sprintf(
                            'Le support « %s » est utilisé dans une commande.',
                            $support->getNom()
                        ),
                        Response::HTTP_CONFLICT
                    );
                }

                $this->viderRelationsSupport($support);
                $entityManager->remove($support);
            }
        }

        $entityManager->flush();

        $message = match ($action) {
            'publish' => count($supports) . ' support(s) publié(s).',
            'unpublish' => count($supports) . ' support(s) dépublié(s).',
            'delete' => count($supports) . ' support(s) supprimé(s).',
        };

        return $this->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /*
     * =========================================================
     * CLASSEMENT
     * =========================================================
     */

    #[Route(
        '/reorder',
        name: 'app_support_reorder',
        methods: ['POST']
    )]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        SupportsRepository $supportRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (
            !is_array($data)
            || !$this->isCsrfTokenValid(
                'support_reorder',
                $data['_token'] ?? null
            )
        ) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $ids = $this->nettoyerIds($data['ids'] ?? []);

        if ($ids === []) {
            return $this->erreurJson(
                'Aucun support reçu.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $supports = $supportRepository->findBy([
            'id' => $ids,
        ]);

        $supportsParId = [];

        foreach ($supports as $support) {
            $supportsParId[$support->getId()] = $support;
        }

        foreach ($ids as $position => $id) {
            if (isset($supportsParId[$id])) {
                $supportsParId[$id]->setOrdre(
                    ($position + 1) * 10
                );
            }
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le classement a été enregistré.',
        ]);
    }

    private function validerSupport(
        string $nom,
        string $description,
        array $typeIds,
        array $formatIds
    ): ?string {
        if ($nom === '') {
            return 'Le nom du support est obligatoire.';
        }

        if ($description === '') {
            return 'La description du support est obligatoire.';
        }

        if ($typeIds === []) {
            return 'Sélectionnez au moins un type d’impression.';
        }

        if ($formatIds === []) {
            return 'Sélectionnez au moins un format.';
        }

        return null;
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

    private function viderRelationsSupport(
        Supports $supports
    ): void {
        foreach (
            $supports->getTypesImpressions()->toArray()
            as $typeImpression
        ) {
            $supports->removeTypeImpression($typeImpression);
        }

        foreach (
            $supports->getFormats()->toArray()
            as $format
        ) {
            $supports->removeFormat($format);
        }

        foreach (
            $supports->getFinitions()->toArray()
            as $finition
        ) {
            $supports->removeFinition($finition);
        }
    }

    private function normaliserSupport(
        Supports $support
    ): array {
        return [
            'id' => $support->getId(),
            'nom' => $support->getNom(),
            'description' => $support->getDescription(),
            'publie' => $support->isPublie(),
            'ordre' => $support->getOrdre(),

            'typesImpressions' => array_map(
                static fn(TypesImpression $type): int =>
                (int) $type->getId(),
                $support->getTypesImpressions()->toArray()
            ),

            'formats' => array_map(
                static fn(Format $format): int =>
                (int) $format->getId(),
                $support->getFormats()->toArray()
            ),

            'finitions' => array_map(
                static fn(Finition $finition): int =>
                (int) $finition->getId(),
                $support->getFinitions()->toArray()
            ),
        ];
    }

    private function erreurJson(
        string $message,
        int $statut
    ): JsonResponse {
        return $this->json([
            'success' => false,
            'message' => $message,
        ], $statut);
    }
}
