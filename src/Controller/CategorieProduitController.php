<?php

namespace App\Controller;

use App\Entity\CategorieProduit;
use App\Repository\CategorieProduitRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/categorie/produit')]
#[IsGranted('ROLE_ADMIN')]
final class CategorieProduitController extends AbstractController
{
    /*
     * =========================================================
     * LISTE
     * =========================================================
     */

    #[Route(
        '',
        name: 'app_categorie_produit_index',
        methods: ['GET']
    )]
    public function index(
        CategorieProduitRepository $categorieProduitRepository
    ): Response {
        return $this->render(
            'categorie_produit/index.html.twig',
            [
                'categoriesProduits' => $categorieProduitRepository->findBy(
                    [],
                    [
                        'ordre' => 'ASC',
                        'nom' => 'ASC',
                    ]
                ),
            ]
        );
    }

    /*
     * =========================================================
     * CRÉATION AJAX
     * =========================================================
     */

    #[Route(
        '/create/ajax',
        name: 'app_categorie_produit_create_ajax',
        methods: ['POST']
    )]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        CategorieProduitRepository $categorieProduitRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'create_categorie_produit',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $nom = trim(
            (string) ($data['nom'] ?? '')
        );

        $description = trim(
            (string) ($data['description'] ?? '')
        );

        $publie = filter_var(
            $data['publie'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $erreur = $this->validerCategorie(
            $nom,
            $description
        );

        if ($erreur !== null) {
            return $this->erreurJson(
                $erreur,
                Response::HTTP_BAD_REQUEST
            );
        }

        $categorieExistante = $categorieProduitRepository->findOneBy([
            'nom' => $nom,
        ]);

        if ($categorieExistante !== null) {
            return $this->erreurJson(
                'Une catégorie portant ce nom existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        $derniereCategorie = $categorieProduitRepository->findOneBy(
            [],
            ['ordre' => 'DESC']
        );

        $ordre = $derniereCategorie !== null
            ? ($derniereCategorie->getOrdre() ?? 0) + 10
            : 10;

        $categorie = new CategorieProduit();

        $categorie->setNom($nom);
        $categorie->setDescription($description);
        $categorie->setPublie($publie);
        $categorie->setOrdre($ordre);

        $entityManager->persist($categorie);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La catégorie a été ajoutée avec succès.',
            'categorie' => $this->normaliserCategorie($categorie),
        ], Response::HTTP_CREATED);
    }

    /*
     * =========================================================
     * ACTIONS DE MASSE
     * Route fixe placée avant les routes avec {id}.
     * =========================================================
     */

    #[Route(
        '/mass-action',
        name: 'app_categorie_produit_mass_action',
        methods: ['POST']
    )]
    public function massAction(
        Request $request,
        EntityManagerInterface $entityManager,
        CategorieProduitRepository $categorieProduitRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'categorie_produit_mass_action',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $action = trim(
            (string) ($data['action'] ?? '')
        );

        $actionsAutorisees = [
            'publish',
            'unpublish',
            'delete',
        ];

        if (!in_array(
            $action,
            $actionsAutorisees,
            true
        )) {
            return $this->erreurJson(
                'Action de masse non autorisée.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $ids = $this->nettoyerIds(
            $data['ids'] ?? []
        );

        if ($ids === []) {
            return $this->erreurJson(
                'Sélectionnez au moins une catégorie.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $categories = $categorieProduitRepository->findBy([
            'id' => $ids,
        ]);

        if (count($categories) !== count($ids)) {
            return $this->erreurJson(
                'Une ou plusieurs catégories sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        if ($action === 'delete') {
            foreach ($categories as $categorie) {
                if (!$categorie->getProduits()->isEmpty()) {
                    return $this->erreurJson(
                        sprintf(
                            'La catégorie « %s » contient des produits. Dépubliez-la au lieu de la supprimer.',
                            $categorie->getNom()
                        ),
                        Response::HTTP_CONFLICT
                    );
                }
            }
        }

        foreach ($categories as $categorie) {
            if ($action === 'publish') {
                $categorie->setPublie(true);
            }

            if ($action === 'unpublish') {
                $categorie->setPublie(false);
            }

            if ($action === 'delete') {
                $entityManager->remove($categorie);
            }
        }

        $entityManager->flush();

        $nombre = count($categories);

        $message = match ($action) {
            'publish' => sprintf(
                '%d catégorie(s) publiée(s) avec succès.',
                $nombre
            ),

            'unpublish' => sprintf(
                '%d catégorie(s) dépubliée(s) avec succès.',
                $nombre
            ),

            'delete' => sprintf(
                '%d catégorie(s) supprimée(s) avec succès.',
                $nombre
            ),
        };

        return $this->json([
            'success' => true,
            'message' => $message,
            'count' => $nombre,
        ]);
    }

    /*
     * =========================================================
     * CLASSEMENT
     * =========================================================
     */

    #[Route(
        '/reorder',
        name: 'app_categorie_produit_reorder',
        methods: ['POST']
    )]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        CategorieProduitRepository $categorieProduitRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'categorie_produit_reorder',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $ids = $this->nettoyerIds(
            $data['ids'] ?? []
        );

        if ($ids === []) {
            return $this->erreurJson(
                'Aucune catégorie reçue.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $categories = $categorieProduitRepository->findBy([
            'id' => $ids,
        ]);

        if (count($categories) !== count($ids)) {
            return $this->erreurJson(
                'Une ou plusieurs catégories sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        $categoriesParId = [];

        foreach ($categories as $categorie) {
            $categoriesParId[$categorie->getId()] = $categorie;
        }

        foreach ($ids as $position => $id) {
            if (!isset($categoriesParId[$id])) {
                continue;
            }

            $categoriesParId[$id]->setOrdre(
                ($position + 1) * 10
            );
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'L’ordre des catégories a été enregistré.',
        ]);
    }

    /*
     * =========================================================
     * CHARGEMENT AJAX
     * =========================================================
     */

    #[Route(
        '/{id}/ajax',
        name: 'app_categorie_produit_get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function getAjax(
        CategorieProduit $categorieProduit
    ): JsonResponse {
        return $this->json([
            'success' => true,
            'categorie' => $this->normaliserCategorie(
                $categorieProduit
            ),
        ]);
    }

    /*
     * =========================================================
     * MODIFICATION AJAX
     * =========================================================
     */

    #[Route(
        '/{id}/update/ajax',
        name: 'app_categorie_produit_update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function updateAjax(
        CategorieProduit $categorieProduit,
        Request $request,
        EntityManagerInterface $entityManager,
        CategorieProduitRepository $categorieProduitRepository
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'update_categorie_produit_'.$categorieProduit->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $nom = trim(
            (string) ($data['nom'] ?? '')
        );

        $description = trim(
            (string) ($data['description'] ?? '')
        );

        $publie = filter_var(
            $data['publie'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $erreur = $this->validerCategorie(
            $nom,
            $description
        );

        if ($erreur !== null) {
            return $this->erreurJson(
                $erreur,
                Response::HTTP_BAD_REQUEST
            );
        }

        $categorieExistante = $categorieProduitRepository->findOneBy([
            'nom' => $nom,
        ]);

        if (
            $categorieExistante !== null
            && $categorieExistante->getId()
                !== $categorieProduit->getId()
        ) {
            return $this->erreurJson(
                'Une autre catégorie porte déjà ce nom.',
                Response::HTTP_CONFLICT
            );
        }

        $categorieProduit->setNom($nom);
        $categorieProduit->setDescription($description);
        $categorieProduit->setPublie($publie);

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La catégorie a été modifiée avec succès.',
            'categorie' => $this->normaliserCategorie(
                $categorieProduit
            ),
        ]);
    }

    /*
     * =========================================================
     * PUBLICATION / DÉPUBLICATION
     * =========================================================
     */

    #[Route(
        '/{id}/toggle-publication',
        name: 'app_categorie_produit_toggle_publication',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function togglePublication(
        CategorieProduit $categorieProduit,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'toggle_categorie_produit_'.$categorieProduit->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $nouvelEtat = !$categorieProduit->isPublie();

        $categorieProduit->setPublie(
            $nouvelEtat
        );

        $entityManager->flush();

        return $this->json([
            'success' => true,

            'message' => $nouvelEtat
                ? 'La catégorie a été publiée.'
                : 'La catégorie a été dépubliée.',

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
        name: 'app_categorie_produit_delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    public function deleteAjax(
        CategorieProduit $categorieProduit,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = json_decode(
            $request->getContent(),
            true
        );

        if (!is_array($data)) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'delete_categorie_produit_'.$categorieProduit->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        if (!$categorieProduit->getProduits()->isEmpty()) {
            return $this->erreurJson(
                'Cette catégorie contient des produits. Dépubliez-la au lieu de la supprimer.',
                Response::HTTP_CONFLICT
            );
        }

        $entityManager->remove(
            $categorieProduit
        );

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La catégorie a été supprimée avec succès.',
        ]);
    }

    /*
     * =========================================================
     * MÉTHODES PRIVÉES
     * =========================================================
     */

    private function validerCategorie(
        string $nom,
        string $description
    ): ?string {
        if ($nom === '') {
            return 'Le nom de la catégorie est obligatoire.';
        }

        if (mb_strlen($nom) < 2) {
            return 'Le nom de la catégorie est trop court.';
        }

        if (mb_strlen($nom) > 255) {
            return 'Le nom de la catégorie est trop long.';
        }

        if ($description === '') {
            return 'La description de la catégorie est obligatoire.';
        }

        if (mb_strlen($description) > 500) {
            return 'La description ne doit pas dépasser 500 caractères.';
        }

        return null;
    }

    private function nettoyerIds(
        mixed $ids
    ): array {
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

    private function normaliserCategorie(
        CategorieProduit $categorieProduit
    ): array {
        return [
            'id' => $categorieProduit->getId(),
            'nom' => $categorieProduit->getNom(),
            'description' => $categorieProduit->getDescription(),
            'publie' => $categorieProduit->isPublie(),
            'ordre' => $categorieProduit->getOrdre(),
            'nombreProduits' => $categorieProduit
                ->getProduits()
                ->count(),
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