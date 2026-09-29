<?php

namespace App\Controller;

use App\Entity\Format;
use App\Entity\TypesImpression;
use App\Repository\FormatRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/format')]
#[IsGranted('ROLE_ADMIN')]
final class FormatController extends AbstractController
{
    #[Route(
        '',
        name: 'app_format_index',
        methods: ['GET']
    )]
    public function index(
        FormatRepository $formatRepository,
        TypesImpressionRepository $typesImpressionRepository
    ): Response {
        return $this->render('format/index.html.twig', [
            'formats' => $formatRepository->findBy(
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
     * CRÉATION
     * =========================================================
     */

    #[Route(
        '/create/ajax',
        name: 'app_format_create_ajax',
        methods: ['POST']
    )]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        FormatRepository $formatRepository,
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
            'create_format',
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $largeur = (int) ($data['largeur'] ?? 0);
        $hauteur = (int) ($data['hauteur'] ?? 0);
        $unite = trim((string) ($data['unite'] ?? 'mm'));
        $description = trim((string) ($data['description'] ?? ''));
        $publie = (bool) ($data['publie'] ?? false);

        $typeIds = $this->nettoyerIds(
            $data['typesImpressions'] ?? []
        );

        $erreur = $this->validerFormat(
            $nom,
            $largeur,
            $hauteur,
            $unite,
            $description,
            $typeIds
        );

        if ($erreur !== null) {
            return $this->json([
                'success' => false,
                'message' => $erreur,
            ], Response::HTTP_BAD_REQUEST);
        }

        $formatExistant = $formatRepository->findOneBy([
            'nom' => $nom,
        ]);

        if ($formatExistant !== null) {
            return $this->json([
                'success' => false,
                'message' => 'Un format portant ce nom existe déjà.',
            ], Response::HTTP_CONFLICT);
        }

        $typesImpressions = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        if (count($typesImpressions) !== count($typeIds)) {
            return $this->json([
                'success' => false,
                'message' => 'Un ou plusieurs types d’impression sont invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $dernierFormat = $formatRepository->findOneBy(
            [],
            ['ordre' => 'DESC']
        );

        $ordre = $dernierFormat !== null
            ? $dernierFormat->getOrdre() + 10
            : 10;

        $format = new Format();

        $format->setNom($nom);
        $format->setLargeur($largeur);
        $format->setHauteur($hauteur);
        $format->setUnite($unite);
        $format->setDescription($description);
        $format->setPublie($publie);
        $format->setOrdre($ordre);

        foreach ($typesImpressions as $typeImpression) {
            $format->addTypeImpression($typeImpression);
        }

        $entityManager->persist($format);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le format a été ajouté avec succès.',

            'format' => $this->normaliserFormat($format),
        ], Response::HTTP_CREATED);
    }

    /*
     * =========================================================
     * CHARGEMENT POUR MODIFICATION
     * =========================================================
     */

    #[Route(
        '/{id}/ajax',
        name: 'app_format_get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function getAjax(
        Format $format
    ): JsonResponse {
        return $this->json([
            'success' => true,
            'format' => $this->normaliserFormat($format),
        ]);
    }

    /*
     * =========================================================
     * MODIFICATION
     * =========================================================
     */

    #[Route(
        '/{id}/update/ajax',
        name: 'app_format_update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function updateAjax(
        Format $format,
        Request $request,
        EntityManagerInterface $entityManager,
        FormatRepository $formatRepository,
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
            'update_format_'.$format->getId(),
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nom = trim((string) ($data['nom'] ?? ''));
        $largeur = (int) ($data['largeur'] ?? 0);
        $hauteur = (int) ($data['hauteur'] ?? 0);
        $unite = trim((string) ($data['unite'] ?? 'mm'));
        $description = trim((string) ($data['description'] ?? ''));
        $publie = (bool) ($data['publie'] ?? false);

        $typeIds = $this->nettoyerIds(
            $data['typesImpressions'] ?? []
        );

        $erreur = $this->validerFormat(
            $nom,
            $largeur,
            $hauteur,
            $unite,
            $description,
            $typeIds
        );

        if ($erreur !== null) {
            return $this->json([
                'success' => false,
                'message' => $erreur,
            ], Response::HTTP_BAD_REQUEST);
        }

        $formatExistant = $formatRepository->findOneBy([
            'nom' => $nom,
        ]);

        if (
            $formatExistant !== null
            && $formatExistant->getId() !== $format->getId()
        ) {
            return $this->json([
                'success' => false,
                'message' => 'Un autre format porte déjà ce nom.',
            ], Response::HTTP_CONFLICT);
        }

        $typesImpressions = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        if (count($typesImpressions) !== count($typeIds)) {
            return $this->json([
                'success' => false,
                'message' => 'Un ou plusieurs types d’impression sont invalides.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $format->setNom($nom);
        $format->setLargeur($largeur);
        $format->setHauteur($hauteur);
        $format->setUnite($unite);
        $format->setDescription($description);
        $format->setPublie($publie);

        foreach (
            $format->getTypesImpressions()->toArray()
            as $ancienTypeImpression
        ) {
            $format->removeTypeImpression(
                $ancienTypeImpression
            );
        }

        foreach ($typesImpressions as $typeImpression) {
            $format->addTypeImpression(
                $typeImpression
            );
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le format a été modifié avec succès.',
            'format' => $this->normaliserFormat($format),
        ]);
    }

    /*
     * =========================================================
     * PUBLICATION / DÉPUBLICATION
     * =========================================================
     */

    #[Route(
        '/{id}/toggle-publication',
        name: 'app_format_toggle_publication',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function togglePublication(
        Format $format,
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
            'toggle_format_'.$format->getId(),
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        $nouvelEtat = !$format->isPublie();

        $format->setPublie($nouvelEtat);

        $entityManager->flush();

        return $this->json([
            'success' => true,

            'message' => $nouvelEtat
                ? 'Le format a été publié.'
                : 'Le format a été dépublié.',

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
        name: 'app_format_delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    public function deleteAjax(
        Format $format,
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
            'delete_format_'.$format->getId(),
            $data['_token'] ?? null
        )) {
            return $this->json([
                'success' => false,
                'message' => 'Jeton de sécurité invalide.',
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$format->getCommandesDetails()->isEmpty()) {
            return $this->json([
                'success' => false,
                'message' => 'Ce format est déjà utilisé dans une commande. Dépubliez-le au lieu de le supprimer.',
            ], Response::HTTP_CONFLICT);
        }

        foreach (
            $format->getTypesImpressions()->toArray()
            as $typeImpression
        ) {
            $format->removeTypeImpression(
                $typeImpression
            );
        }

        $entityManager->remove($format);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le format a été supprimé.',
        ]);
    }

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

    private function validerFormat(
        string $nom,
        int $largeur,
        int $hauteur,
        string $unite,
        string $description,
        array $typeIds
    ): ?string {
        if ($nom === '') {
            return 'Le nom du format est obligatoire.';
        }

        if ($largeur <= 0) {
            return 'La largeur doit être supérieure à zéro.';
        }

        if ($hauteur <= 0) {
            return 'La hauteur doit être supérieure à zéro.';
        }

        if (!in_array($unite, ['mm', 'cm', 'm'], true)) {
            return 'L’unité sélectionnée est invalide.';
        }

        if ($description === '') {
            return 'La description est obligatoire.';
        }

        if ($typeIds === []) {
            return 'Sélectionnez au moins un type d’impression.';
        }

        return null;
    }

    private function normaliserFormat(
        Format $format
    ): array {
        return [
            'id' => $format->getId(),
            'nom' => $format->getNom(),
            'largeur' => $format->getLargeur(),
            'hauteur' => $format->getHauteur(),
            'unite' => $format->getUnite(),
            'description' => $format->getDescription(),
            'publie' => $format->isPublie(),
            'ordre' => $format->getOrdre(),

            'typesImpressions' => array_values(
                array_map(
                    static fn (
                        TypesImpression $typeImpression
                    ): int => (int) $typeImpression->getId(),

                    $format
                        ->getTypesImpressions()
                        ->toArray()
                )
            ),
        ];
    }
}