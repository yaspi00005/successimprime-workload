<?php

namespace App\Repository;

use App\Entity\Format;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProduitConfiguration>
 */
class ProduitConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry
    ) {
        parent::__construct(
            $registry,
            ProduitConfiguration::class
        );
    }

    /**
     * Retourne toutes les configurations actives d’un produit.
     *
     * Utilisé notamment pour le mode automatique.
     *
     * @return ProduitConfiguration[]
     */
    public function findActiveByProduit(
        Produits $produit
    ): array {
        return $this->createQueryBuilder('configuration')
            ->addSelect(
                'typeImpression',
                'support',
                'format'
            )
            ->innerJoin(
                'configuration.typeImpression',
                'typeImpression'
            )
            ->innerJoin(
                'configuration.support',
                'support'
            )
            ->innerJoin(
                'configuration.format',
                'format'
            )
            ->andWhere(
                'configuration.produit = :produit'
            )
            ->andWhere(
                'configuration.active = :active'
            )
            ->setParameter('produit', $produit)
            ->setParameter('active', true)
            ->orderBy(
                'configuration.ordre',
                'ASC'
            )
            ->addOrderBy(
                'configuration.id',
                'ASC'
            )
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les types d’impression autorisés
     * pour un produit.
     *
     * @return TypesImpression[]
     */
    public function findTypesImpressionByProduit(
        Produits $produit
    ): array {
        $configurations = $this->findActiveByProduit(
            $produit
        );

        $resultats = [];

        foreach ($configurations as $configuration) {
            $typeImpression =
                $configuration->getTypeImpression();

            if ($typeImpression === null) {
                continue;
            }

            $resultats[$typeImpression->getId()] =
                $typeImpression;
        }

        uasort(
            $resultats,
            static function (
                TypesImpression $premier,
                TypesImpression $second
            ): int {
                return strcasecmp(
                    (string) $premier->getNom(),
                    (string) $second->getNom()
                );
            }
        );

        return array_values($resultats);
    }

    /**
     * Retourne les supports compatibles avec
     * un produit et un type d’impression.
     *
     * @return Supports[]
     */
    public function findSupportsByProduitAndType(
        Produits $produit,
        TypesImpression $typeImpression
    ): array {
        $configurations = $this->createQueryBuilder(
            'configuration'
        )
            ->addSelect('support')
            ->innerJoin(
                'configuration.support',
                'support'
            )
            ->andWhere(
                'configuration.produit = :produit'
            )
            ->andWhere(
                'configuration.typeImpression = :typeImpression'
            )
            ->andWhere(
                'configuration.active = :active'
            )
            ->setParameter('produit', $produit)
            ->setParameter(
                'typeImpression',
                $typeImpression
            )
            ->setParameter('active', true)
            ->orderBy(
                'configuration.ordre',
                'ASC'
            )
            ->getQuery()
            ->getResult();

        $resultats = [];

        foreach ($configurations as $configuration) {
            $support = $configuration->getSupport();

            if ($support === null) {
                continue;
            }

            $resultats[$support->getId()] = $support;
        }

        uasort(
            $resultats,
            static function (
                Supports $premier,
                Supports $second
            ): int {
                return strcasecmp(
                    (string) $premier->getNom(),
                    (string) $second->getNom()
                );
            }
        );

        return array_values($resultats);
    }

    /**
     * Retourne les formats compatibles avec
     * le produit, le type d’impression et le support.
     *
     * @return Formats[]
     */
    public function findFormatsByProduitTypeAndSupport(
        Produits $produit,
        TypesImpression $typeImpression,
        Supports $support
    ): array {
        $configurations = $this->createQueryBuilder(
            'configuration'
        )
            ->addSelect('format')
            ->innerJoin(
                'configuration.format',
                'format'
            )
            ->andWhere(
                'configuration.produit = :produit'
            )
            ->andWhere(
                'configuration.typeImpression = :typeImpression'
            )
            ->andWhere(
                'configuration.support = :support'
            )
            ->andWhere(
                'configuration.active = :active'
            )
            ->setParameter('produit', $produit)
            ->setParameter(
                'typeImpression',
                $typeImpression
            )
            ->setParameter('support', $support)
            ->setParameter('active', true)
            ->orderBy(
                'configuration.ordre',
                'ASC'
            )
            ->getQuery()
            ->getResult();

        $resultats = [];

        foreach ($configurations as $configuration) {
            $format = $configuration->getFormat();

            if ($format === null) {
                continue;
            }

            $resultats[$format->getId()] = $format;
        }

        uasort(
            $resultats,
            static function (
                Format $premier,
                Format $second
            ): int {
                return strcasecmp(
                    (string) $premier->getNom(),
                    (string) $second->getNom()
                );
            }
        );

        return array_values($resultats);
    }

    /**
     * Recherche la configuration exacte choisie
     * indirectement en mode manuel.
     */
    public function findConfigurationExacte(
        Produits $produit,
        TypesImpression $typeImpression,
        Supports $support,
        Format $format
    ): ?ProduitConfiguration {
        return $this->createQueryBuilder(
            'configuration'
        )
            ->addSelect(
                'typeImpression',
                'support',
                'format',
                'configurationFinitions',
                'finition'
            )
            ->innerJoin(
                'configuration.typeImpression',
                'typeImpression'
            )
            ->innerJoin(
                'configuration.support',
                'support'
            )
            ->innerJoin(
                'configuration.format',
                'format'
            )
            ->leftJoin(
                'configuration.configurationFinitions',
                'configurationFinitions',
                'WITH',
                'configurationFinitions.active = :active'
            )
            ->leftJoin(
                'configurationFinitions.finition',
                'finition'
            )
            ->andWhere(
                'configuration.produit = :produit'
            )
            ->andWhere(
                'configuration.typeImpression = :typeImpression'
            )
            ->andWhere(
                'configuration.support = :support'
            )
            ->andWhere(
                'configuration.format = :format'
            )
            ->andWhere(
                'configuration.active = :active'
            )
            ->setParameter('produit', $produit)
            ->setParameter(
                'typeImpression',
                $typeImpression
            )
            ->setParameter('support', $support)
            ->setParameter('format', $format)
            ->setParameter('active', true)
            ->orderBy(
                'configurationFinitions.ordre',
                'ASC'
            )
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Vérifie qu’une configuration automatique
     * est active et appartient réellement au produit.
     */
    public function findActiveConfiguration(
        int $configurationId,
        Produits $produit
    ): ?ProduitConfiguration {
        return $this->createQueryBuilder(
            'configuration'
        )
            ->addSelect(
                'typeImpression',
                'support',
                'format',
                'configurationFinitions',
                'finition'
            )
            ->innerJoin(
                'configuration.typeImpression',
                'typeImpression'
            )
            ->innerJoin(
                'configuration.support',
                'support'
            )
            ->innerJoin(
                'configuration.format',
                'format'
            )
            ->leftJoin(
                'configuration.configurationFinitions',
                'configurationFinitions',
                'WITH',
                'configurationFinitions.active = :active'
            )
            ->leftJoin(
                'configurationFinitions.finition',
                'finition'
            )
            ->andWhere(
                'configuration.id = :configurationId'
            )
            ->andWhere(
                'configuration.produit = :produit'
            )
            ->andWhere(
                'configuration.active = :active'
            )
            ->setParameter(
                'configurationId',
                $configurationId
            )
            ->setParameter('produit', $produit)
            ->setParameter('active', true)
            ->orderBy(
                'configurationFinitions.ordre',
                'ASC'
            )
            ->getQuery()
            ->getOneOrNullResult();
    }
}