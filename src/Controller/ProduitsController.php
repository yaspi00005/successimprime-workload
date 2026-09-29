<?php

namespace App\Controller;

use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\ProduitArticleStock;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Repository\CategorieProduitRepository;
use App\Repository\FinitionRepository;
use App\Repository\FormatRepository;
use App\Repository\ProduitsRepository;
use App\Repository\SupportsRepository;
use App\Repository\ArticlesRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/produits')]
#[IsGranted('ROLE_ADMIN')]
final class ProduitsController extends AbstractController
{
    #[Route(
        '',
        name: 'app_produits_index',
        methods: ['GET']
    )]
    public function index(
        ProduitsRepository $produitsRepository,
        CategorieProduitRepository $categorieProduitRepository,
        TypesImpressionRepository $typesImpressionRepository,
        SupportsRepository $supportsRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository,
        ArticlesRepository $articlesRepository
    ): Response {
        $categoriesProduits = $categorieProduitRepository->findBy(
            [],
            [
                'ordre' => 'ASC',
                'nom' => 'ASC',
            ]
        );

        return $this->render('produits/index.html.twig', [
            'articles' => $articlesRepository->findBy(
                [],
                [
                    'designation' => 'ASC'
                ]
            ),
            'produits' => $produitsRepository->findBy(
                [],
                [
                    'ordre' => 'ASC',
                    'nom' => 'ASC',
                ]
            ),

            'categoriesProduits' => $categoriesProduits,

            'typesImpressions' => $typesImpressionRepository->findBy(
                ['publie' => true],
                [
                    'ordre' => 'ASC',
                    'nom' => 'ASC',
                ]
            ),

            'supports' => $supportsRepository->findBy(
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
        name: 'app_produits_create_ajax',
        methods: ['POST']
    )]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitsRepository $produitsRepository,
        CategorieProduitRepository $categorieProduitRepository,
        TypesImpressionRepository $typesImpressionRepository,
        SupportsRepository $supportsRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository,
        ArticlesRepository $articlesRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'create_produit',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $nom = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $prixBase = max(0, (int) ($data['prixBase'] ?? 0));

        $publie = filter_var(
            $data['publie'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $actif = filter_var(
            $data['actif'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $personnalisable = filter_var(
            $data['personnalisable'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $gestionStock = filter_var(
            $data['gestionStock'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $articlesStockData = is_array($data['articlesStock'] ?? null)
            ? $data['articlesStock']
            : [];

        $categorieId = (int) ($data['categorieProduit'] ?? 0);

        $typeIds = $this->nettoyerIds(
            $data['typesImpressions'] ?? []
        );

        $supportIds = $this->nettoyerIds(
            $data['supports'] ?? []
        );

        $formatIds = $this->nettoyerIds(
            $data['formats'] ?? []
        );

        $finitionIds = $this->nettoyerIds(
            $data['finitions'] ?? []
        );

        $erreur = $this->validerProduit(
            $code,
            $nom,
            $description,
            $categorieId,
            $typeIds,
            $supportIds,
            $formatIds
        );

        if ($erreur !== null) {
            return $this->erreurJson(
                $erreur,
                Response::HTTP_BAD_REQUEST
            );
        }

        if ($produitsRepository->findOneBy(['code' => $code])) {
            return $this->erreurJson(
                'Un produit portant ce code existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        if ($produitsRepository->findOneBy(['nom' => $nom])) {
            return $this->erreurJson(
                'Un produit portant ce nom existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        $categorie = $categorieProduitRepository->find($categorieId);

        if ($categorie === null) {
            return $this->erreurJson(
                'La catégorie sélectionnée est invalide.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $typesImpressions = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        $supports = $supportsRepository->findBy([
            'id' => $supportIds,
        ]);

        $formats = $formatRepository->findBy([
            'id' => $formatIds,
        ]);

        $finitions = $finitionRepository->findBy([
            'id' => $finitionIds,
        ]);

        $erreurRelations = $this->verifierRelations(
            $typeIds,
            $typesImpressions,
            $supportIds,
            $supports,
            $formatIds,
            $formats,
            $finitionIds,
            $finitions
        );

        if ($erreurRelations !== null) {
            return $this->erreurJson(
                $erreurRelations,
                Response::HTTP_BAD_REQUEST
            );
        }

        $dernierProduit = $produitsRepository->findOneBy(
            [],
            ['ordre' => 'DESC']
        );

        $ordre = $dernierProduit !== null
            ? ($dernierProduit->getOrdre() ?? 0) + 10
            : 10;

        $produit = new Produits();

        $produit->setCode($code);
        $produit->setNom($nom);
        $produit->setDescription($description);
        $produit->setPrixBase($prixBase);
        $produit->setPersonnalisable($personnalisable);
        $produit->setPublie($publie);
        $produit->setActif($actif);
        $produit->setOrdre($ordre);
        $produit->setCategorieProduit($categorie);
        $produit->setGestionStock($gestionStock);

        foreach ($typesImpressions as $typeImpression) {
            $produit->addTypeImpression($typeImpression);
        }

        foreach ($supports as $support) {
            $produit->addSupport($support);
        }

        foreach ($formats as $format) {
            $produit->addFormat($format);
        }

        foreach ($finitions as $finition) {
            $produit->addFinition($finition);
        }

        $this->appliquerArticlesStock(
            $produit,
            $articlesStockData,
            $articlesRepository
        );

        $entityManager->persist($produit);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le produit a été ajouté avec succès.',
            'produit' => $this->normaliserProduit($produit),
        ], Response::HTTP_CREATED);
    }

    /*
     * =========================================================
     * ACTIONS DE MASSE
     * =========================================================
     */

    #[Route(
        '/mass-action',
        name: 'app_produits_mass_action',
        methods: ['POST']
    )]
    public function massAction(
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitsRepository $produitsRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'produits_mass_action',
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $action = trim((string) ($data['action'] ?? ''));

        $actionsAutorisees = [
            'publish',
            'unpublish',
            'activate',
            'deactivate',
            'delete',
        ];

        if (!in_array($action, $actionsAutorisees, true)) {
            return $this->erreurJson(
                'Action de masse non autorisée.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $ids = $this->nettoyerIds($data['ids'] ?? []);

        if ($ids === []) {
            return $this->erreurJson(
                'Sélectionnez au moins un produit.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $produits = $produitsRepository->findBy([
            'id' => $ids,
        ]);

        if (count($produits) !== count($ids)) {
            return $this->erreurJson(
                'Un ou plusieurs produits sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        if ($action === 'delete') {
            foreach ($produits as $produit) {
                if (!$produit->getCommandesDetails()->isEmpty()) {
                    return $this->erreurJson(
                        sprintf(
                            'Le produit « %s » est déjà utilisé dans une commande.',
                            $produit->getNom()
                        ),
                        Response::HTTP_CONFLICT
                    );
                }
            }
        }

        foreach ($produits as $produit) {
            match ($action) {
                'publish' => $produit->setPublie(true),
                'unpublish' => $produit->setPublie(false),
                'activate' => $produit->setActif(true),
                'deactivate' => $produit->setActif(false),
                'delete' => $entityManager->remove($produit),
            };
        }

        $entityManager->flush();

        $nombre = count($produits);

        $message = match ($action) {
            'publish' => $nombre . ' produit(s) publié(s).',
            'unpublish' => $nombre . ' produit(s) dépublié(s).',
            'activate' => $nombre . ' produit(s) activé(s).',
            'deactivate' => $nombre . ' produit(s) désactivé(s).',
            'delete' => $nombre . ' produit(s) supprimé(s).',
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
        name: 'app_produits_reorder',
        methods: ['POST']
    )]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitsRepository $produitsRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if (
            $data === null
            || !$this->isCsrfTokenValid(
                'produits_reorder',
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
                'Aucun produit reçu.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $produits = $produitsRepository->findBy([
            'id' => $ids,
        ]);

        if (count($produits) !== count($ids)) {
            return $this->erreurJson(
                'Un ou plusieurs produits sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        $produitsParId = [];

        foreach ($produits as $produit) {
            $produitsParId[$produit->getId()] = $produit;
        }

        foreach ($ids as $position => $id) {
            $produitsParId[$id]->setOrdre(
                ($position + 1) * 10
            );
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le classement des produits a été enregistré.',
        ]);
    }

    /*
     * =========================================================
     * CHARGEMENT
     * =========================================================
     */

    #[Route(
        '/{id}/ajax',
        name: 'app_produits_get_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function getAjax(Produits $produit): JsonResponse
    {
        return $this->json([
            'success' => true,
            'produit' => $this->normaliserProduit($produit),
        ]);
    }

    /*
     * =========================================================
     * MODIFICATION
     * =========================================================
     */

    #[Route(
        '/{id}/update/ajax',
        name: 'app_produits_update_ajax',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function updateAjax(
        Produits $produit,
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitsRepository $produitsRepository,
        CategorieProduitRepository $categorieProduitRepository,
        TypesImpressionRepository $typesImpressionRepository,
        SupportsRepository $supportsRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository,
        ArticlesRepository $articlesRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreurJson(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'update_produit_' . $produit->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $nom = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $prixBase = max(0, (int) ($data['prixBase'] ?? 0));

        $publie = filter_var(
            $data['publie'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $actif = filter_var(
            $data['actif'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $personnalisable = filter_var(
            $data['personnalisable'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $gestionStock = filter_var(
            $data['gestionStock'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        $articlesStockData = is_array($data['articlesStock'] ?? null)
            ? $data['articlesStock']
            : [];

        $categorieId = (int) ($data['categorieProduit'] ?? 0);

        $typeIds = $this->nettoyerIds(
            $data['typesImpressions'] ?? []
        );

        $supportIds = $this->nettoyerIds(
            $data['supports'] ?? []
        );

        $formatIds = $this->nettoyerIds(
            $data['formats'] ?? []
        );

        $finitionIds = $this->nettoyerIds(
            $data['finitions'] ?? []
        );

        $erreur = $this->validerProduit(
            $code,
            $nom,
            $description,
            $categorieId,
            $typeIds,
            $supportIds,
            $formatIds
        );

        if ($erreur !== null) {
            return $this->erreurJson(
                $erreur,
                Response::HTTP_BAD_REQUEST
            );
        }

        $produitCodeExistant = $produitsRepository->findOneBy([
            'code' => $code,
        ]);

        if (
            $produitCodeExistant !== null
            && $produitCodeExistant->getId() !== $produit->getId()
        ) {
            return $this->erreurJson(
                'Un autre produit porte déjà ce code.',
                Response::HTTP_CONFLICT
            );
        }

        $produitNomExistant = $produitsRepository->findOneBy([
            'nom' => $nom,
        ]);

        if (
            $produitNomExistant !== null
            && $produitNomExistant->getId() !== $produit->getId()
        ) {
            return $this->erreurJson(
                'Un autre produit porte déjà ce nom.',
                Response::HTTP_CONFLICT
            );
        }

        $categorie = $categorieProduitRepository->find($categorieId);

        if ($categorie === null) {
            return $this->erreurJson(
                'La catégorie sélectionnée est invalide.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $typesImpressions = $typesImpressionRepository->findBy([
            'id' => $typeIds,
        ]);

        $supports = $supportsRepository->findBy([
            'id' => $supportIds,
        ]);

        $formats = $formatRepository->findBy([
            'id' => $formatIds,
        ]);

        $finitions = $finitionRepository->findBy([
            'id' => $finitionIds,
        ]);

        $erreurRelations = $this->verifierRelations(
            $typeIds,
            $typesImpressions,
            $supportIds,
            $supports,
            $formatIds,
            $formats,
            $finitionIds,
            $finitions
        );

        if ($erreurRelations !== null) {
            return $this->erreurJson(
                $erreurRelations,
                Response::HTTP_BAD_REQUEST
            );
        }

        $produit->setCode($code);
        $produit->setNom($nom);
        $produit->setDescription($description);
        $produit->setPrixBase($prixBase);
        $produit->setPersonnalisable($personnalisable);
        $produit->setPublie($publie);
        $produit->setActif($actif);
        $produit->setCategorieProduit($categorie);
        $produit->setGestionStock($gestionStock);

        $this->viderRelationsProduit($produit);

        foreach ($typesImpressions as $typeImpression) {
            $produit->addTypeImpression($typeImpression);
        }

        foreach ($supports as $support) {
            $produit->addSupport($support);
        }

        foreach ($formats as $format) {
            $produit->addFormat($format);
        }

        foreach ($finitions as $finition) {
            $produit->addFinition($finition);
        }

        $this->appliquerArticlesStock(
            $produit,
            $articlesStockData,
            $articlesRepository
        );

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le produit a été modifié avec succès.',
            'produit' => $this->normaliserProduit($produit),
        ]);
    }

    /*
     * =========================================================
     * PUBLICATION
     * =========================================================
     */

    #[Route(
        '/{id}/toggle-publication',
        name: 'app_produits_toggle_publication',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function togglePublication(
        Produits $produit,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->lireJson($request);

        if (
            $data === null
            || !$this->isCsrfTokenValid(
                'toggle_produit_' . $produit->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $produit->setPublie(!$produit->isPublie());

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => $produit->isPublie()
                ? 'Le produit a été publié.'
                : 'Le produit a été dépublié.',
        ]);
    }

    /*
     * =========================================================
     * ACTIVATION
     * =========================================================
     */

    #[Route(
        '/{id}/toggle-status',
        name: 'app_produits_toggle_status',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function toggleStatus(
        Produits $produit,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->lireJson($request);

        if (
            $data === null
            || !$this->isCsrfTokenValid(
                'toggle_status_produit_' . $produit->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $produit->setActif(!$produit->isActif());

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => $produit->isActif()
                ? 'Le produit a été activé.'
                : 'Le produit a été désactivé.',
        ]);
    }

    /*
     * =========================================================
     * SUPPRESSION
     * =========================================================
     */

    #[Route(
        '/{id}/delete/ajax',
        name: 'app_produits_delete_ajax',
        requirements: ['id' => '\d+'],
        methods: ['DELETE']
    )]
    public function deleteAjax(
        Produits $produit,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->lireJson($request);

        if (
            $data === null
            || !$this->isCsrfTokenValid(
                'delete_produit_' . $produit->getId(),
                $data['_token'] ?? null
            )
        ) {
            return $this->erreurJson(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        if (!$produit->getCommandesDetails()->isEmpty()) {
            return $this->erreurJson(
                'Ce produit est utilisé dans une commande. Désactivez-le au lieu de le supprimer.',
                Response::HTTP_CONFLICT
            );
        }

        $this->viderRelationsProduit($produit);

        $entityManager->remove($produit);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le produit a été supprimé.',
        ]);
    }

    private function lireJson(Request $request): ?array
    {
        $data = json_decode(
            $request->getContent(),
            true
        );

        return is_array($data) ? $data : null;
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
                        static fn(mixed $id): int => (int) $id,
                        $ids
                    ),
                    static fn(int $id): bool => $id > 0
                )
            )
        );
    }

    private function validerProduit(
        string $code,
        string $nom,
        string $description,
        int $categorieId,
        array $typeIds,
        array $supportIds,
        array $formatIds
    ): ?string {
        if ($code === '') {
            return 'Le code du produit est obligatoire.';
        }

        if ($nom === '') {
            return 'Le nom du produit est obligatoire.';
        }

        if ($description === '') {
            return 'La description du produit est obligatoire.';
        }

        if ($categorieId <= 0) {
            return 'Sélectionnez une catégorie.';
        }

        if ($typeIds === []) {
            return 'Sélectionnez au moins un type d’impression.';
        }

        if ($supportIds === []) {
            return 'Sélectionnez au moins un support.';
        }

        if ($formatIds === []) {
            return 'Sélectionnez au moins un format.';
        }

        return null;
    }

    private function verifierRelations(
        array $typeIds,
        array $typesImpressions,
        array $supportIds,
        array $supports,
        array $formatIds,
        array $formats,
        array $finitionIds,
        array $finitions
    ): ?string {
        if (count($typeIds) !== count($typesImpressions)) {
            return 'Un ou plusieurs types d’impression sont invalides.';
        }

        if (count($supportIds) !== count($supports)) {
            return 'Un ou plusieurs supports sont invalides.';
        }

        if (count($formatIds) !== count($formats)) {
            return 'Un ou plusieurs formats sont invalides.';
        }

        if (count($finitionIds) !== count($finitions)) {
            return 'Une ou plusieurs finitions sont invalides.';
        }

        return null;
    }

    private function viderRelationsProduit(Produits $produit): void
    {
        foreach (
            $produit->getTypesImpressions()->toArray()
            as $typeImpression
        ) {
            $produit->removeTypeImpression($typeImpression);
        }

        foreach ($produit->getSupports()->toArray() as $support) {
            $produit->removeSupport($support);
        }

        foreach ($produit->getFormats()->toArray() as $format) {
            $produit->removeFormat($format);
        }

        foreach ($produit->getFinitions()->toArray() as $finition) {
            $produit->removeFinition($finition);
        }

        foreach ($produit->getArticlesStock()->toArray() as $articleStock) {
            $produit->removeArticlesStock($articleStock);
        }
    }

    /*
     * Construit les liaisons ProduitArticleStock (nomenclature de
     * consommation) a partir des lignes envoyees par le JS
     * (initialiserGestionStock() / recupererDonnees() dans
     * traitement_produit.js) et les rattache au produit.
     *
     * Jusqu'ici, ni createAjax() ni updateAjax() ne lisaient
     * "gestionStock" ni "articlesStock" dans les donnees recues :
     * le formulaire de nomenclature (fonctionnel cote JS) n'avait
     * donc aucun effet en base -- gestion_stock restait a 0 et
     * aucune ligne n'etait jamais enregistree, meme apres l'avoir
     * renseignee et "enregistree" avec succes (reponse 200).
     */
    private function appliquerArticlesStock(
        Produits $produit,
        array $donnees,
        ArticlesRepository $articlesRepository
    ): void {
        foreach ($donnees as $ligne) {
            if (!is_array($ligne)) {
                continue;
            }

            $articleId = (int) ($ligne['articleId'] ?? 0);

            if ($articleId <= 0) {
                continue;
            }

            $article = $articlesRepository->find($articleId);

            if ($article === null) {
                continue;
            }

            $liaison = new ProduitArticleStock();
            $liaison->setArticle($article);

            try {
                $liaison->setCoefficient($ligne['coefficient'] ?? '1.000');
                $liaison->setModeCalcul(
                    (string) ($ligne['modeCalcul'] ?? ProduitArticleStock::MODE_QUANTITE)
                );
            } catch (\InvalidArgumentException) {
                continue;
            }

            $liaison->setObligatoire(
                filter_var($ligne['obligatoire'] ?? true, FILTER_VALIDATE_BOOL)
            );

            $liaison->setActif(
                filter_var($ligne['actif'] ?? true, FILTER_VALIDATE_BOOL)
            );

            $produit->addArticlesStock($liaison);
        }
    }

    private function normaliserProduit(Produits $produit): array
    {
        return [
            'id' => $produit->getId(),
            'code' => $produit->getCode(),
            'nom' => $produit->getNom(),
            'description' => $produit->getDescription(),
            'prixBase' => $produit->getPrixBase(),
            'personnalisable' => $produit->isPersonnalisable(),
            'publie' => $produit->isPublie(),
            'actif' => $produit->isActif(),
            'ordre' => $produit->getOrdre(),

            'categorieProduit' => $produit
                ->getCategorieProduit()
                ?->getId(),

            'typesImpressions' => array_map(
                static fn(TypesImpression $type): int =>
                (int) $type->getId(),
                $produit->getTypesImpressions()->toArray()
            ),

            'supports' => array_map(
                static fn(Supports $support): int =>
                (int) $support->getId(),
                $produit->getSupports()->toArray()
            ),

            'formats' => array_map(
                static fn(Format $format): int =>
                (int) $format->getId(),
                $produit->getFormats()->toArray()
            ),

            'finitions' => array_map(
                static fn(Finition $finition): int =>
                (int) $finition->getId(),
                $produit->getFinitions()->toArray()
            ),

            'gestionStock' => $produit->isGestionStock(),

            'articlesStock' => array_map(
                static fn(ProduitArticleStock $liaison): array => [
                    'articleId' => $liaison->getArticle()?->getId(),
                    'coefficient' => $liaison->getCoefficient(),
                    'modeCalcul' => $liaison->getModeCalcul(),
                    'obligatoire' => $liaison->isObligatoire(),
                    'actif' => $liaison->isActif(),
                ],
                $produit->getArticlesStock()->toArray()
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
