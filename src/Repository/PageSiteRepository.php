<?php

namespace App\Repository;

use App\Entity\PageSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageSite>
 */
class PageSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageSite::class);
    }

    public function publiee(string $slug): ?PageSite
    {
        return $this->findOneBy(['slug' => $slug, 'publie' => true]);
    }

    /**
     * @return list<PageSite>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'titre' => 'ASC']);
    }
}
