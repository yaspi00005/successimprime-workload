<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Objet\ConfigurationProduit;
use App\ApiResource\Produit;
use App\Entity\Produits;
use App\Service\GrilleTarifaire;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * @implements ProviderInterface<Produit>
 */
final class ProduitProvider extends LectureApi implements ProviderInterface
{
    public function __construct(Pagination $pagination, private readonly EntityManagerInterface $em)
    {
        parent::__construct($pagination);
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        return $this->lire($operation, $uriVariables, $context);
    }

    protected function requete(array $filtres): QueryBuilder
    {
        $requete = $this->em->createQueryBuilder()
            ->select('p', 'cat')->from(Produits::class, 'p')
            ->leftJoin('p.categorieProduit', 'cat')
            ->where('p.actif = true')
            ->orderBy('p.nom', 'ASC')->addOrderBy('p.id', 'ASC');
        if (null !== $q = self::texte($filtres, 'q')) {
            $requete->andWhere('p.nom LIKE :q OR p.code LIKE :q OR cat.nom LIKE :q')->setParameter('q', self::like($q));
        }
        if (self::oui($filtres, 'publie')) {
            $requete->andWhere('p.publie = true');
        }

        return $requete;
    }

    protected function convertir(object $entite): Produit
    {
        \assert($entite instanceof Produits);
        $configurations = [];
        foreach ($entite->getConfigurations() as $c) {
            if (!$c->isActive() || null === $c->getId()) {
                continue;
            }
            $configurations[] = new ConfigurationProduit(
                $c->getId(),
                GrilleTarifaire::libelle($c),
                $c->getModeCalcul(),
                $c->getPrixApplicable(false),
                $c->getPrixApplicable(true),
                max(1, $c->getQuantiteMinimale()),
            );
        }

        return new Produit(
            (int) $entite->getId(),
            (string) $entite->getNom(),
            $entite->getCode(),
            $entite->getCategorieProduit()?->getNom(),
            $entite->isPublie(),
            $configurations,
        );
    }
}
