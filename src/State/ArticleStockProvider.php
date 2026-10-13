<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ArticleStock;
use App\Entity\Articles;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<ArticleStock>
 */
final class ArticleStockProvider extends LectureApi implements ProviderInterface
{
    public function __construct(Pagination $pagination, private readonly EntityManagerInterface $em, private readonly Security $security)
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
            ->select('a', 'fa')->from(Articles::class, 'a')
            ->leftJoin('a.famille', 'fa')
            ->where('a.actif = true')
            ->orderBy('a.designation', 'ASC')->addOrderBy('a.id', 'ASC');
        if (null !== $q = self::texte($filtres, 'q')) {
            $requete->andWhere('a.reference LIKE :q OR a.designation LIKE :q')->setParameter('q', self::like($q));
        }
        if (self::oui($filtres, 'alerte')) {
            $requete->andWhere('a.stockMin > 0')->andWhere('a.stock <= a.stockMin');
        }

        return $requete;
    }

    protected function convertir(object $entite): ArticleStock
    {
        \assert($entite instanceof Articles);
        $seuil = $entite->getSeuilAlerte();

        return new ArticleStock(
            (int) $entite->getId(),
            $entite->getReference(),
            $entite->getDesignation(),
            $entite->getFamille()?->getNom(),
            $entite->getUniteCourte(),
            $entite->getStock(),
            $seuil,
            $seuil > 0 && $entite->getStock() <= $seuil,
            $entite->getEmplacement(),
            $this->security->isGranted('ROLE_COUT_MARGE') ? $entite->getCoutUnitaireStock() : null,
        );
    }
}
