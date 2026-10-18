<?php

namespace App\Repository;

use App\Entity\AlerteSysteme;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlerteSysteme>
 */
class AlerteSystemeRepository extends ServiceEntityRepository
{
    public const LIMITE = 200;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlerteSysteme::class);
    }

    /**
     * Liste de l'écran : « en_cours » (ouvertes et prises en charge, les
     * critiques puis les plus récentes), « resolues » ou « toutes ».
     *
     * @return list<AlerteSysteme>
     */
    public function liste(string $vue, ?string $type = null): array
    {
        $qb = $this->createQueryBuilder('a')->leftJoin('a.prisePar', 'p')->addSelect('p')->setMaxResults(self::LIMITE);
        if ('en_cours' === $vue) {
            $qb->andWhere('a.etat != :resolue')->setParameter('resolue', AlerteSysteme::RESOLUE)
                ->addOrderBy('a.etat', 'ASC')->addOrderBy('a.gravite', 'DESC');
        } elseif ('resolues' === $vue) {
            $qb->andWhere('a.etat = :resolue')->setParameter('resolue', AlerteSysteme::RESOLUE);
        }
        if (null !== $type) {
            $qb->andWhere('a.type = :type')->setParameter('type', $type);
        }

        return $qb->addOrderBy('a.derniereLe', 'DESC')->getQuery()->getResult();
    }

    /** @return array<string, int> état => nombre */
    public function compterParEtat(): array
    {
        $compte = array_fill_keys(array_keys(AlerteSysteme::ETATS), 0);
        foreach ($this->createQueryBuilder('a')->select('a.etat, COUNT(a.id) AS n')->groupBy('a.etat')->getQuery()->getArrayResult() as $ligne) {
            $compte[$ligne['etat']] = (int) $ligne['n'];
        }

        return $compte;
    }

    /** Alertes critiques encore ouvertes (pas prises en charge) : pastille du menu. */
    public function compterOuvertes(): int
    {
        return (int) $this->createQueryBuilder('a')->select('COUNT(a.id)')
            ->where('a.etat = :ouverte')->setParameter('ouverte', AlerteSysteme::OUVERTE)
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<AlerteSysteme> celles qui attendent d'être envoyées, les plus anciennes d'abord */
    public function aPrevenir(): array
    {
        return $this->createQueryBuilder('a')->where('a.aPrevenir = true')->orderBy('a.id', 'ASC')->setMaxResults(500)->getQuery()->getResult();
    }

    /** @return list<AlerteSysteme> les alertes non résolues d'une sorte (résolution automatique) */
    public function nonResoluesDuType(string $type): array
    {
        return $this->createQueryBuilder('a')->where('a.type = :type')->andWhere('a.etat != :resolue')
            ->setParameter('type', $type)->setParameter('resolue', AlerteSysteme::RESOLUE)
            ->getQuery()->getResult();
    }

    /** Supprime les alertes résolues depuis plus longtemps que la date donnée. */
    public function purger(\DateTimeImmutable $avant): int
    {
        return (int) $this->createQueryBuilder('a')->delete()->where('a.etat = :resolue')->andWhere('a.resolueLe < :avant')
            ->setParameter('resolue', AlerteSysteme::RESOLUE)->setParameter('avant', $avant)
            ->getQuery()->execute();
    }
}
