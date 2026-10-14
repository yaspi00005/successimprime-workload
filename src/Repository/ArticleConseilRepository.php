<?php

namespace App\Repository;

use App\Entity\ArticleConseil;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArticleConseil>
 */
class ArticleConseilRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArticleConseil::class);
    }

    /**
     * Conseils publiés dont la date de publication est passée, les plus récents d'abord.
     *
     * @return list<ArticleConseil>
     */
    public function visibles(\DateTimeImmutable $maintenant, ?int $max = null, ?ArticleConseil $sauf = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.image', 'i')->addSelect('i')
            ->where('a.publie = true')->andWhere('a.publieLe <= :maintenant')->andWhere("a.slug <> ''")
            ->setParameter('maintenant', $maintenant)
            ->orderBy('a.publieLe', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults($max);
        if (null !== $sauf) {
            $qb->andWhere('a <> :sauf')->setParameter('sauf', $sauf);
        }

        return $qb->getQuery()->getResult();
    }

    public function visible(string $slug, \DateTimeImmutable $maintenant): ?ArticleConseil
    {
        $article = $this->findOneBy(['slug' => $slug]);

        return null !== $article && $article->estVisible($maintenant) ? $article : null;
    }

    /**
     * @return list<ArticleConseil>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['publieLe' => 'DESC', 'id' => 'DESC']);
    }
}
