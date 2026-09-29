<?php

namespace App\Controller;

use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Repository\FinitionRepository;
use App\Repository\FormatRepository;
use App\Repository\ProduitConfigurationRepository;
use App\Repository\ProduitsRepository;
use App\Repository\SupportsRepository;
use App\Repository\TypesImpressionRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Entity\Produits;



#[Route('/produit-configurations')]
#[IsGranted('ROLE_ADMIN')]
final class ProduitConfigurationController extends AbstractController
{
    #[Route('', name: 'app_produit_configuration_index', methods: ['GET'])]
    public function index(
        ProduitConfigurationRepository $configurationRepository,
        ProduitsRepository $produitsRepository,
        TypesImpressionRepository $typeRepository,
        SupportsRepository $supportRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): Response {
        $produits = $produitsRepository->findBy(
            ['publie' => true, 'actif' => true],
            ['ordre' => 'ASC', 'nom' => 'ASC']
        );

        $typesImpressions = $typeRepository->findBy(
            ['publie' => true],
            ['ordre' => 'ASC', 'nom' => 'ASC']
        );

        $supports = $supportRepository->findBy(
            ['publie' => true],
            ['ordre' => 'ASC', 'nom' => 'ASC']
        );

        $formats = $formatRepository->findBy(
            ['publie' => true],
            ['ordre' => 'ASC', 'nom' => 'ASC']
        );

        $finitions = $finitionRepository->findBy(
            ['publie' => true],
            ['ordre' => 'ASC', 'nom' => 'ASC']
        );

        /*
         * Catalogue utilisé par le JavaScript pour les listes dépendantes.
         *
         * Produit sélectionné
         *     -> types d'impression compatibles
         *     -> supports compatibles
         *     -> formats compatibles
         *     -> finitions compatibles
         *
         * Le support sélectionné affine ensuite le type, le format
         * et les finitions.
         */
        $catalogueCompatibilites = [
            'produits' => [],
            'supports' => [],
        ];

        foreach ($produits as $produit) {
            $catalogueCompatibilites['produits'][(string) $produit->getId()] = [
                'types' => $this->extraireIds(
                    $produit->getTypesImpressions()
                ),
                'supports' => $this->extraireIds(
                    $produit->getSupports()
                ),
                'formats' => $this->extraireIds(
                    $produit->getFormats()
                ),
                'finitions' => $this->extraireIds(
                    $produit->getFinitions()
                ),
            ];
        }

        foreach ($supports as $support) {
            $catalogueCompatibilites['supports'][(string) $support->getId()] = [
                'types' => $this->extraireIds(
                    $support->getTypesImpressions()
                ),
                'formats' => $this->extraireIds(
                    $support->getFormats()
                ),
                'finitions' => $this->extraireIds(
                    $support->getFinitions()
                ),
            ];
        }

        return $this->render('produit_configuration/index.html.twig', [
            'configurations' => $configurationRepository->findBy(
                [],
                ['ordre' => 'ASC', 'id' => 'ASC']
            ),
            'produits' => $produits,
            'typesImpressions' => $typesImpressions,
            'supports' => $supports,
            'formats' => $formats,
            'finitions' => $finitions,
            'catalogueCompatibilites' => $catalogueCompatibilites,
        ]);
    }

    #[Route('/create/ajax', name: 'app_produit_configuration_create_ajax', methods: ['POST'])]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitConfigurationRepository $configurationRepository,
        ProduitsRepository $produitsRepository,
        TypesImpressionRepository $typeRepository,
        SupportsRepository $supportRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreur('Données JSON invalides.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid('create_produit_configuration', $data['_token'] ?? null)) {
            return $this->erreur('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $configuration = new ProduitConfiguration();
        $erreur = $this->hydrater(
            $configuration,
            $data,
            $produitsRepository,
            $typeRepository,
            $supportRepository,
            $formatRepository,
            $finitionRepository
        );

        if ($erreur !== null) {
            return $this->erreur($erreur, Response::HTTP_BAD_REQUEST);
        }

        if ($this->configurationExiste($configurationRepository, $configuration)) {
            return $this->erreur(
                'Cette combinaison produit, impression, support et format existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        $dernier = $configurationRepository->findOneBy([], ['ordre' => 'DESC']);
        if (!isset($data['ordre'])) {
            $configuration->setOrdre($dernier ? $dernier->getOrdre() + 10 : 10);
        }

        try {
            $entityManager->persist($configuration);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->erreur(
                'Cette configuration existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        return $this->json([
            'success' => true,
            'message' => 'La configuration a été ajoutée avec succès.',
            'configuration' => $this->normaliser($configuration),
        ], Response::HTTP_CREATED);
    }

    /*
     * =========================================================
     * ACTIONS DE MASSE
     * =========================================================
     */

    #[Route(
        '/mass-action',
        name: 'app_produit_configuration_mass_action',
        methods: ['POST']
    )]
    public function massAction(
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitConfigurationRepository $configurationRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreur(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'produit_configurations_mass_action',
            $data['_token'] ?? null
        )) {
            return $this->erreur(
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
            return $this->erreur(
                'Action de masse non autorisée.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $ids = $this->nettoyerIds(
            $data['ids'] ?? []
        );

        if ($ids === []) {
            return $this->erreur(
                'Sélectionnez au moins une configuration.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $configurations =
            $configurationRepository->findBy([
                'id' => $ids,
            ]);

        if (count($configurations) !== count($ids)) {
            return $this->erreur(
                'Une ou plusieurs configurations sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        foreach ($configurations as $configuration) {
            if ($action === 'publish') {
                $configuration->setActive(true);
            } elseif ($action === 'unpublish') {
                $configuration->setActive(false);
            } else {
                $entityManager->remove(
                    $configuration
                );
            }
        }

        $entityManager->flush();

        $nombre = count($configurations);

        $message = match ($action) {
            'publish' =>
            $nombre
                . ' configuration(s) publiée(s).',

            'unpublish' =>
            $nombre
                . ' configuration(s) dépubliée(s).',

            'delete' =>
            $nombre
                . ' configuration(s) supprimée(s).',
        };

        return $this->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    /*
     * =========================================================
     * CLASSEMENT PAR GLISSER-DÉPOSER
     * =========================================================
     */

    #[Route(
        '/reorder',
        name: 'app_produit_configuration_reorder',
        methods: ['POST']
    )]
    public function reorder(
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitConfigurationRepository $configurationRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreur(
                'Données JSON invalides.',
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$this->isCsrfTokenValid(
            'produit_configurations_reorder',
            $data['_token'] ?? null
        )) {
            return $this->erreur(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $ids = $this->nettoyerIds($data['ids'] ?? []);

        if ($ids === []) {
            return $this->erreur(
                'Aucune configuration reçue.',
                Response::HTTP_BAD_REQUEST
            );
        }

        $configurations = $configurationRepository->findBy([
            'id' => $ids,
        ]);

        if (count($configurations) !== count($ids)) {
            return $this->erreur(
                'Une ou plusieurs configurations sont introuvables.',
                Response::HTTP_NOT_FOUND
            );
        }

        $configurationsParId = [];

        foreach ($configurations as $configuration) {
            $configurationsParId[$configuration->getId()] = $configuration;
        }

        foreach ($ids as $position => $id) {
            $configurationsParId[$id]->setOrdre(
                ($position + 1) * 10
            );
        }

        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Le classement des configurations a été enregistré.',
        ]);
    }

    #[Route('/{id}/ajax', name: 'app_produit_configuration_get_ajax', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function getAjax(ProduitConfiguration $configuration): JsonResponse
    {
        return $this->json([
            'success' => true,
            'configuration' => $this->normaliser($configuration),
        ]);
    }

    #[Route('/{id}/update/ajax', name: 'app_produit_configuration_update_ajax', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateAjax(
        ProduitConfiguration $configuration,
        Request $request,
        EntityManagerInterface $entityManager,
        ProduitConfigurationRepository $configurationRepository,
        ProduitsRepository $produitsRepository,
        TypesImpressionRepository $typeRepository,
        SupportsRepository $supportRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): JsonResponse {
        $data = $this->lireJson($request);

        if ($data === null) {
            return $this->erreur('Données JSON invalides.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isCsrfTokenValid(
            'update_produit_configuration_' . $configuration->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreur('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $erreur = $this->hydrater(
            $configuration,
            $data,
            $produitsRepository,
            $typeRepository,
            $supportRepository,
            $formatRepository,
            $finitionRepository
        );

        if ($erreur !== null) {
            return $this->erreur($erreur, Response::HTTP_BAD_REQUEST);
        }

        if ($this->configurationExiste($configurationRepository, $configuration, $configuration->getId())) {
            return $this->erreur(
                'Cette combinaison produit, impression, support et format existe déjà.',
                Response::HTTP_CONFLICT
            );
        }

        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return $this->erreur('Cette configuration existe déjà.', Response::HTTP_CONFLICT);
        }

        return $this->json([
            'success' => true,
            'message' => 'La configuration a été modifiée avec succès.',
            'configuration' => $this->normaliser($configuration),
        ]);
    }

    #[Route(
        '/{id}/toggle-status',
        name: 'app_produit_configuration_toggle_status',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function toggleStatus(
        ProduitConfiguration $configuration,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->lireJson($request) ?? [];

        if (!$this->isCsrfTokenValid(
            'toggle_produit_configuration_'
                . $configuration->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreur(
                'Jeton de sécurité invalide.',
                Response::HTTP_FORBIDDEN
            );
        }

        $configuration->setActive(
            !$configuration->isActive()
        );

        $entityManager->flush();

        return $this->json([
            'success' => true,

            'message' =>
            $configuration->isActive()
                ? 'La configuration a été publiée.'
                : 'La configuration a été dépubliée.',

            'active' =>
            $configuration->isActive(),
        ]);
    }

    #[Route('/{id}/delete/ajax', name: 'app_produit_configuration_delete_ajax', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteAjax(
        ProduitConfiguration $configuration,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        $data = $this->lireJson($request) ?? [];

        if (!$this->isCsrfTokenValid(
            'delete_produit_configuration_' . $configuration->getId(),
            $data['_token'] ?? null
        )) {
            return $this->erreur('Jeton de sécurité invalide.', Response::HTTP_FORBIDDEN);
        }

        $entityManager->remove($configuration);
        $entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'La configuration a été supprimée.',
        ]);
    }

    private function hydrater(
        ProduitConfiguration $configuration,
        array $data,
        ProduitsRepository $produitsRepository,
        TypesImpressionRepository $typeRepository,
        SupportsRepository $supportRepository,
        FormatRepository $formatRepository,
        FinitionRepository $finitionRepository
    ): ?string {
        $produit = $produitsRepository->find((int) ($data['produit'] ?? 0));
        $type = $typeRepository->find((int) ($data['typeImpression'] ?? 0));
        $support = $supportRepository->find((int) ($data['support'] ?? 0));
        $format = $formatRepository->find((int) ($data['format'] ?? 0));

        if (!$produit || !$type || !$support || !$format) {
            return 'Le produit, le type d’impression, le support et le format sont obligatoires.';
        }

        if (!$produit->getTypesImpressions()->contains($type)) {
            return 'Ce type d’impression n’est pas compatible avec le produit.';
        }
        if (!$produit->getSupports()->contains($support)) {
            return 'Ce support n’est pas compatible avec le produit.';
        }
        if (!$produit->getFormats()->contains($format)) {
            return 'Ce format n’est pas compatible avec le produit.';
        }
        if (!$support->getTypesImpressions()->contains($type)) {
            return 'Ce support n’est pas compatible avec le type d’impression.';
        }
        if (!$support->getFormats()->contains($format)) {
            return 'Ce format n’est pas compatible avec le support.';
        }

        $quantiteMin = max(1, (int) ($data['quantiteMinimale'] ?? 1));
        $quantiteMax = $this->entierNullable($data['quantiteMaximale'] ?? null);
        if ($quantiteMax !== null && $quantiteMax < $quantiteMin) {
            return 'La quantité maximale doit être supérieure ou égale à la quantité minimale.';
        }

        $configuration
            ->setProduit($produit)
            ->setTypeImpression($type)
            ->setSupport($support)
            ->setFormat($format)
            ->setPrixBase($this->entierNullable($data['prixBase'] ?? null))
            ->setModeCalcul((string) ($data['modeCalcul'] ?? 'forfait'))
            ->setQuantiteMinimale($quantiteMin)
            ->setQuantiteMaximale($quantiteMax)
            ->setDescription($data['description'] ?? null)
            ->setActive($this->booleen($data['active'] ?? true))
            ->setOrdre(max(0, (int) ($data['ordre'] ?? $configuration->getOrdre())));

        $finitionsData = $data['finitions'] ?? [];
        if (!is_array($finitionsData)) {
            return 'La liste des finitions est invalide.';
        }

        $anciennesFinitions = [];
        foreach ($configuration->getConfigurationFinitions() as $ancienneFinition) {
            $ancienneId = $ancienneFinition->getFinition()?->getId();
            if ($ancienneId !== null) {
                $anciennesFinitions[$ancienneId] = $ancienneFinition;
            }
        }

        $ids = [];
        foreach ($finitionsData as $ligne) {
            if (!is_array($ligne)) {
                return 'Une finition contient des données invalides.';
            }

            $finitionId = (int) ($ligne['finition'] ?? 0);
            if ($finitionId <= 0 || in_array($finitionId, $ids, true)) {
                return 'Chaque finition doit être valide et ne peut apparaître qu’une seule fois.';
            }
            $ids[] = $finitionId;

            $finition = $finitionRepository->find($finitionId);
            if (!$finition || !$produit->getFinitions()->contains($finition)) {
                return 'Une finition sélectionnée n’est pas compatible avec le produit.';
            }
            if (!$support->getFinitions()->contains($finition)) {
                return 'Une finition sélectionnée n’est pas compatible avec le support.';
            }

            $minFinition = max(1, (int) ($ligne['quantiteMinimale'] ?? 1));
            $maxFinition = $this->entierNullable($ligne['quantiteMaximale'] ?? null);
            if ($maxFinition !== null && $maxFinition < $minFinition) {
                return sprintf(
                    'Pour « %s », la quantité maximale est inférieure à la quantité minimale.',
                    $finition->getNom()
                );
            }

            $prix = max(0, (int) ($ligne['prix'] ?? 0));
            $obligatoire = $this->booleen($ligne['obligatoire'] ?? false);

            $configurationFinition = $anciennesFinitions[$finitionId]
                ?? new ProduitConfigurationFinition();

            unset($anciennesFinitions[$finitionId]);

            $configurationFinition
                ->setFinition($finition)
                ->setObligatoire($obligatoire)
                ->setSelectionneeParDefaut(
                    $obligatoire || $this->booleen($ligne['selectionneeParDefaut'] ?? false)
                )
                ->setPrix($prix)
                ->setPayante($prix > 0 && $this->booleen($ligne['payante'] ?? true))
                ->setModeCalcul((string) ($ligne['modeCalcul'] ?? 'forfait'))
                ->setQuantiteMinimale($minFinition)
                ->setQuantiteMaximale($maxFinition)
                ->setActive($this->booleen($ligne['active'] ?? true))
                ->setOrdre(max(0, (int) ($ligne['ordre'] ?? 10)))
                ->setDescription($ligne['description'] ?? null);

            $configuration->addConfigurationFinition($configurationFinition);
        }

        foreach ($anciennesFinitions as $ancienneFinition) {
            $configuration->removeConfigurationFinition($ancienneFinition);
        }

        return null;
    }

    private function configurationExiste(
        ProduitConfigurationRepository $repository,
        ProduitConfiguration $configuration,
        ?int $idIgnore = null
    ): bool {
        $existante = $repository->findOneBy([
            'produit' => $configuration->getProduit(),
            'typeImpression' => $configuration->getTypeImpression(),
            'support' => $configuration->getSupport(),
            'format' => $configuration->getFormat(),
        ]);

        return $existante !== null && $existante->getId() !== $idIgnore;
    }

    private function normaliser(ProduitConfiguration $configuration): array
    {
        return [
            'id' => $configuration->getId(),
            'produit' => $configuration->getProduit()?->getId(),
            'typeImpression' => $configuration->getTypeImpression()?->getId(),
            'support' => $configuration->getSupport()?->getId(),
            'format' => $configuration->getFormat()?->getId(),
            'prixBase' => $configuration->getPrixBase(),
            'modeCalcul' => $configuration->getModeCalcul(),
            'prixBase' => $configuration->getPrixBase(),
            'modeCalcul' => $configuration->getModeCalcul(),
            'modeDimension' =>
            $this->determinerModeDimension($configuration),
            'quantiteMinimale' =>
            $configuration->getQuantiteMinimale(),
            'quantiteMaximale' =>
            $configuration->getQuantiteMaximale(),
            'quantiteMinimale' => $configuration->getQuantiteMinimale(),
            'quantiteMaximale' => $configuration->getQuantiteMaximale(),
            'description' => $configuration->getDescription(),
            'active' => $configuration->isActive(),
            'ordre' => $configuration->getOrdre(),
            'finitions' => array_values(array_map(
                static fn(
                    ProduitConfigurationFinition $ligne
                ): array => [
                    'id' => $ligne->getId(),
                    'finition' => $ligne->getFinition()?->getId(),
                    'nom' => $ligne->getFinition()?->getNom() ?? 'Finition',
                    'obligatoire' => $ligne->isObligatoire(),
                    'selectionneeParDefaut' =>
                    $ligne->isSelectionneeParDefaut(),
                    'payante' => $ligne->isPayante(),
                    'prix' => $ligne->getPrix(),
                    'modeCalcul' => $ligne->getModeCalcul(),
                    'quantiteMinimale' =>
                    $ligne->getQuantiteMinimale(),
                    'quantiteMaximale' =>
                    $ligne->getQuantiteMaximale(),
                    'active' => $ligne->isActive(),
                    'ordre' => $ligne->getOrdre(),
                    'description' => $ligne->getDescription(),
                ],
                array_values(array_filter(
                    $configuration
                        ->getConfigurationFinitions()
                        ->toArray(),
                    static fn(
                        ProduitConfigurationFinition $ligne
                    ): bool => $ligne->isActive()
                ))
            )),
        ];
    }

    private function lireJson(Request $request): ?array
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Extrait les identifiants d'une collection Doctrine.
     *
     * @return list<int>
     */
    private function extraireIds(iterable $elements): array
    {
        $ids = [];

        foreach ($elements as $element) {
            $id = $element->getId();

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private function entierNullable(mixed $valeur): ?int
    {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        return max(0, (int) $valeur);
    }

    /**
     * Nettoie une liste d'identifiants reçue depuis le JavaScript.
     *
     * @return list<int>
     */
    private function nettoyerIds(mixed $valeurs): array
    {
        if (!is_array($valeurs)) {
            return [];
        }

        $ids = [];

        foreach ($valeurs as $valeur) {
            $id = filter_var($valeur, FILTER_VALIDATE_INT);

            if ($id !== false && $id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function booleen(mixed $valeur): bool
    {
        return filter_var($valeur, FILTER_VALIDATE_BOOL);
    }

    private function erreur(string $message, int $status): JsonResponse
    {
        return $this->json(['success' => false, 'message' => $message], $status);
    }


    #[Route(
        '/produit/{id}/ajax',
        name: 'app_produit_configuration_by_produit_ajax',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function parProduit(
        Produits $produit,
        ProduitConfigurationRepository $configurationRepository
    ): JsonResponse {
        $configurations = $configurationRepository->findBy(
            [
                'produit' => $produit,
                'active' => true,
            ],
            [
                'ordre' => 'ASC',
                'id' => 'ASC',
            ]
        );

        $resultats = array_map(
            function (
                ProduitConfiguration $configuration
            ): array {
                $typeImpression =
                    $configuration->getTypeImpression();

                $support =
                    $configuration->getSupport();

                $format =
                    $configuration->getFormat();

                return [
                    'id' => $configuration->getId(),

                    'libelle' => (string) $configuration,

                    'typeImpression' => [
                        'id' => $typeImpression?->getId(),
                        'nom' => $typeImpression?->getNom(),
                    ],

                    'support' => [
                        'id' => $support?->getId(),
                        'nom' => $support?->getNom(),
                    ],

                    'format' => [
                        'id' => $format?->getId(),
                        'nom' => $format?->getNom(),
                    ],

                    'prixBase' =>
                    $configuration->getPrixBase(),

                    'modeCalcul' =>
                    $configuration->getModeCalcul(),

                    'modeDimension' =>
                    $this->determinerModeDimension(
                        $configuration
                    ),

                    'quantiteMinimale' =>
                    $configuration->getQuantiteMinimale(),

                    'quantiteMaximale' =>
                    $configuration->getQuantiteMaximale(),
                ];
            },
            $configurations
        );

        return $this->json([
            'success' => true,
            'produit' => [
                'id' => $produit->getId(),
                'nom' => $produit->getNom(),
            ],
            'configurations' => $resultats,
        ]);
    }
    private function determinerModeDimension(
        ProduitConfiguration $configuration
    ): string {
        $modeCalcul = strtolower(
            trim(
                (string) $configuration->getModeCalcul()
            )
        );

        if (
            in_array(
                $modeCalcul,
                [
                    'metre',
                    'metre_carre',
                    'm2',
                    'surface',
                ],
                true
            )
        ) {
            return 'mesure';
        }

        if ($configuration->getFormat() !== null) {
            return 'format';
        }

        return 'aucune';
    }
}
