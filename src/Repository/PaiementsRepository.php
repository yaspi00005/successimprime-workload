<?php

namespace App\Repository;

use App\Entity\Paiements;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Paiements>
 */
class PaiementsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Paiements::class);
    }

    /**
     * Liste des paiements, du plus récent au plus ancien, avec
     * recherche facultative (nom du client, code client, numéro de
     * commande, référence ou numéro de chèque).
     *
     * Sans recherche : les $limite derniers paiements uniquement
     * (l'appelant y passe une petite limite, ex. 100). Avec une
     * recherche, l'appelant y passe une limite plus large pour
     * pouvoir retrouver un paiement plus ancien.
     *
     * @return Paiements[]
     */
    public function rechercher(string $q, int $limite): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.commande', 'c')
            ->addSelect('c')
            ->leftJoin('c.clients', 'cl')
            ->addSelect('cl')
            ->leftJoin('p.compteTresorerie', 'ct')
            ->addSelect('ct')
            ->orderBy('p.date', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(max(1, $limite));

        $q = trim($q);

        if ($q !== '') {
            $qb
                ->andWhere(
                    'cl.nom LIKE :q
                    OR cl.prenom LIKE :q
                    OR cl.raisonSociale LIKE :q
                    OR cl.code LIKE :q
                    OR c.numero LIKE :q
                    OR p.reference LIKE :q
                    OR p.numeroCheque LIKE :q'
                )
                ->setParameter('q', '%' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return Paiements[] Returns an array of Paiements objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('p.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Paiements
    //    {
    //        return $this->createQueryBuilder('p')
    //            ->andWhere('p.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
