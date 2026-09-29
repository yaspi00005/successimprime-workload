<?php

namespace App\Controller;

use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Repository\ProduitConfigurationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/commandes/catalogue')]
final class CommandeCatalogueController extends AbstractController
{
    /*
     * ============================================================
     * CONFIGURATIONS AUTOMATIQUES D'UN PRODUIT
     * ============================================================
     */

    #[Route(
        '/produit/{id}',
        name: 'app_commande_catalogue_produit',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function configurationsProduit(
        Produits $produit,
        ProduitConfigurationRepository $repository
    ): JsonResponse {
        if (
            !$produit->isActif()
            || !$produit->isPublie()
        ) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Ce produit n’est pas disponible.',
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $configurations =
            $repository->findBy(
                [
                    'produit' => $produit,
                    'active' => true,
                ],
                [
                    'ordre' => 'ASC',
                    'id' => 'ASC',
                ]
            );

        $resultat = [];

        foreach (
            $configurations
            as $configuration
        ) {
            if (
                !$configuration
                instanceof ProduitConfiguration
            ) {
                continue;
            }

            $resultat[] = [
                'id' =>
                    $configuration->getId(),

                'libelle' =>
                    (string) $configuration,

                /*
                 * Prix PUBLIC.
                 */
                'prixBase' =>
                    $configuration->getPrixBase()
                    ?? 0,

                /*
                 * Taux B2B calculé depuis le Produit.
                 */
                'remiseB2B' =>
                    round(
                        $configuration
                            ->getRemiseB2BProduit(),
                        4
                    ),

                /*
                 * Prix B2B théorique pour affichage.
                 */
                'prixB2BCalcule' =>
                    $configuration
                        ->getPrixB2BCalcule(),

                'modeCalcul' =>
                    $configuration
                        ->getModeCalcul(),

                'modeDimension' =>
                    $configuration
                        ->getModeDimension(),

                'largeur' =>
                    $configuration
                        ->getLargeurDefaut(),

                'longueur' =>
                    $configuration
                        ->getLongueurDefaut(),

                'surface' =>
                    $configuration
                        ->getSurfaceDefaut(),

                'quantiteMinimale' =>
                    $configuration
                        ->getQuantiteMinimale(),

                'quantiteMaximale' =>
                    $configuration
                        ->getQuantiteMaximale(),

                'typeImpression' =>
                    $configuration
                        ->getTypeImpression()
                        ?->getId(),

                'typeImpressionNom' =>
                    $configuration
                        ->getTypeImpression()
                        ?->getNom(),

                'support' =>
                    $configuration
                        ->getSupport()
                        ?->getId(),

                'supportNom' =>
                    $configuration
                        ->getSupport()
                        ?->getNom(),

                'format' =>
                    $configuration
                        ->getFormat()
                        ?->getId(),

                'formatNom' =>
                    $configuration
                        ->getFormat()
                        ?->getNom(),
            ];
        }

        return $this->json([
            'success' => true,

            'produit' => [
                'id' =>
                    $produit->getId(),

                'nom' =>
                    $produit->getNom(),

                'prixBase' =>
                    $produit->getPrixBase()
                    ?? 0,

                'prixB2B' =>
                    $produit->getPrixB2B(),

                'remiseB2B' =>
                    round(
                        $this
                            ->calculerRemiseB2BProduit(
                                $produit
                            ),
                        4
                    ),

                'modeCalcul' =>
                    $produit
                        ->getModeCalcul(),
            ],

            'configurations' =>
                $resultat,
        ]);
    }


    /*
     * ============================================================
     * OPTIONS MANUELLES D'UN PRODUIT
     * ============================================================
     */

    #[Route(
        '/produit/{id}/options-manuelles',
        name: 'app_commande_catalogue_options_manuelles',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function optionsManuelles(
        Produits $produit
    ): JsonResponse {
        if (
            !$produit->isActif()
            || !$produit->isPublie()
        ) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Ce produit n’est pas disponible.',
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $typesImpression = [];
        $supports = [];
        $formats = [];
        $finitions = [];


        /*
         * ========================================================
         * TYPES IMPRESSION
         * ========================================================
         */

        foreach (
            $produit->getTypesImpressions()
            as $typeImpression
        ) {
            if (
                method_exists(
                    $typeImpression,
                    'isPublie'
                )
                && !$typeImpression->isPublie()
            ) {
                continue;
            }

            $typesImpression[] = [
                'id' =>
                    $typeImpression->getId(),

                'nom' =>
                    $typeImpression->getNom(),
            ];
        }


        /*
         * ========================================================
         * SUPPORTS
         * ========================================================
         */

        foreach (
            $produit->getSupports()
            as $support
        ) {
            if (
                method_exists(
                    $support,
                    'isPublie'
                )
                && !$support->isPublie()
            ) {
                continue;
            }

            $supports[] = [
                'id' =>
                    $support->getId(),

                'nom' =>
                    $support->getNom(),
            ];
        }


        /*
         * ========================================================
         * FORMATS
         * ========================================================
         */

        foreach (
            $produit->getFormats()
            as $format
        ) {
            if (
                method_exists(
                    $format,
                    'isPublie'
                )
                && !$format->isPublie()
            ) {
                continue;
            }

            $formats[] = [
                'id' =>
                    $format->getId(),

                'nom' =>
                    $format->getNom(),

                'largeur' =>
                    method_exists(
                        $format,
                        'getLargeur'
                    )
                        ? $format->getLargeur()
                        : null,

                'hauteur' =>
                    method_exists(
                        $format,
                        'getHauteur'
                    )
                        ? $format->getHauteur()
                        : null,

                'unite' =>
                    method_exists(
                        $format,
                        'getUnite'
                    )
                        ? $format->getUnite()
                        : null,
            ];
        }


        /*
         * ========================================================
         * FINITIONS
         * ========================================================
         */

        foreach (
            $produit->getFinitions()
            as $finition
        ) {
            if (
                method_exists(
                    $finition,
                    'isPublie'
                )
                && !$finition->isPublie()
            ) {
                continue;
            }

            $finitions[] = [
                'id' =>
                    $finition->getId(),

                'nom' =>
                    $finition->getNom(),
            ];
        }


        /*
         * ========================================================
         * TRI
         * ========================================================
         */

        $triParNom =
            static function (
                array $a,
                array $b
            ): int {
                return strcasecmp(
                    (string) (
                        $a['nom']
                        ?? ''
                    ),
                    (string) (
                        $b['nom']
                        ?? ''
                    )
                );
            };

        usort(
            $typesImpression,
            $triParNom
        );

        usort(
            $supports,
            $triParNom
        );

        usort(
            $formats,
            $triParNom
        );

        usort(
            $finitions,
            $triParNom
        );


        return $this->json([
            'success' => true,

            'produit' => [
                'id' =>
                    $produit->getId(),

                'nom' =>
                    $produit->getNom(),

                'prixBase' =>
                    $produit->getPrixBase()
                    ?? 0,

                'prixB2B' =>
                    $produit->getPrixB2B(),

                'remiseB2B' =>
                    round(
                        $this
                            ->calculerRemiseB2BProduit(
                                $produit
                            ),
                        4
                    ),

                'modeCalcul' =>
                    $produit
                        ->getModeCalcul(),

                'utiliseSurface' =>
                    $produit
                        ->utiliseSurface(),

                'utiliseLongueur' =>
                    $produit
                        ->utiliseLongueur(),

                'estForfaitaire' =>
                    $produit
                        ->estForfaitaire(),
            ],

            'typesImpression' =>
                $typesImpression,

            'supports' =>
                $supports,

            'formats' =>
                $formats,

            'finitions' =>
                $finitions,
        ]);
    }


    /*
     * ============================================================
     * CONFIGURATION AUTOMATIQUE COMPLÈTE
     * ============================================================
     */

    #[Route(
        '/configuration/{id}',
        name: 'app_commande_catalogue_configuration',
        requirements: [
            'id' => '\d+',
        ],
        methods: ['GET']
    )]
    public function configuration(
        ProduitConfiguration $configuration
    ): JsonResponse {
        if (!$configuration->isActive()) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Cette configuration est désactivée.',
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        $produit =
            $configuration
                ->getProduit();

        if (
            $produit === null
            || !$produit->isActif()
            || !$produit->isPublie()
        ) {
            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Le produit de cette configuration n’est pas disponible.',
                ],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json([
            'success' => true,

            'configuration' => [
                'id' =>
                    $configuration->getId(),

                'produit' =>
                    $produit->getId(),

                'designation' =>
                    $produit->getNom(),

                'typeImpression' =>
                    $configuration
                        ->getTypeImpression()
                        ?->getId(),

                'typeImpressionNom' =>
                    $configuration
                        ->getTypeImpression()
                        ?->getNom(),

                'support' =>
                    $configuration
                        ->getSupport()
                        ?->getId(),

                'supportNom' =>
                    $configuration
                        ->getSupport()
                        ?->getNom(),

                'format' =>
                    $configuration
                        ->getFormat()
                        ?->getId(),

                'formatNom' =>
                    $configuration
                        ->getFormat()
                        ?->getNom(),

                /*
                 * =================================================
                 * PRIX PUBLIC
                 * =================================================
                 */

                'prixBase' =>
                    $configuration
                        ->getPrixBase()
                    ?? 0,

                /*
                 * =================================================
                 * B2B
                 * =================================================
                 */

                'remiseB2B' =>
                    round(
                        $configuration
                            ->getRemiseB2BProduit(),
                        4
                    ),

                'prixB2BCalcule' =>
                    $configuration
                        ->getPrixB2BCalcule(),

                /*
                 * =================================================
                 * CALCUL / DIMENSIONS
                 * =================================================
                 */

                'modeCalcul' =>
                    $configuration
                        ->getModeCalcul(),

                'modeDimension' =>
                    $configuration
                        ->getModeDimension(),

                'largeur' =>
                    $configuration
                        ->getLargeurDefaut(),

                'longueur' =>
                    $configuration
                        ->getLongueurDefaut(),

                'surface' =>
                    $configuration
                        ->getSurfaceDefaut(),

                'quantiteMinimale' =>
                    $configuration
                        ->getQuantiteMinimale(),

                'quantiteMaximale' =>
                    $configuration
                        ->getQuantiteMaximale(),

                'finitions' =>
                    $this
                        ->normaliserFinitions(
                            $configuration
                        ),
            ],
        ]);
    }


    /*
     * ============================================================
     * FINITIONS AUTOMATIQUES
     * ============================================================
     */

    private function normaliserFinitions(
        ProduitConfiguration $configuration
    ): array {
        $resultat = [];

        foreach (
            $configuration
                ->getConfigurationFinitions()
            as $ligne
        ) {
            if (
                !$ligne
                instanceof ProduitConfigurationFinition
            ) {
                continue;
            }

            if (!$ligne->isActive()) {
                continue;
            }

            $finition =
                $ligne->getFinition();

            if ($finition === null) {
                continue;
            }

            $resultat[] = [
                /*
                 * ID de ProduitConfigurationFinition.
                 */
                'id' =>
                    $ligne->getId(),

                /*
                 * ID de Finition.
                 */
                'finition' =>
                    $finition->getId(),

                'nom' =>
                    $finition->getNom(),

                'prix' =>
                    $ligne->getPrix(),

                'modeCalcul' =>
                    $ligne->getModeCalcul(),

                'obligatoire' =>
                    $ligne->isObligatoire(),

                'selectionneeParDefaut' =>
                    $ligne
                        ->isSelectionneeParDefaut(),

                'payante' =>
                    $ligne->isPayante(),

                'quantiteMinimale' =>
                    $ligne
                        ->getQuantiteMinimale(),

                'quantiteMaximale' =>
                    $ligne
                        ->getQuantiteMaximale(),

                'description' =>
                    $ligne->getDescription(),
            ];
        }

        return $resultat;
    }


    /*
     * ============================================================
     * TAUX B2B PRODUIT
     * ============================================================
     */

    private function calculerRemiseB2BProduit(
        Produits $produit
    ): float {
        $prixBase =
            (float) (
                $produit
                    ->getPrixBase()
                ?? 0
            );

        $prixB2B =
            $produit
                ->getPrixB2B();

        if (
            $prixBase <= 0
            || $prixB2B === null
        ) {
            return 0.0;
        }

        $prixB2B =
            (float) $prixB2B;

        if (
            $prixB2B < 0
            || $prixB2B >= $prixBase
        ) {
            return 0.0;
        }

        return (
            (
                $prixBase
                - $prixB2B
            )
            / $prixBase
        ) * 100;
    }
}