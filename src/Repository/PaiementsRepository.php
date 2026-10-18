<?php

namespace App\Repository;

use App\Entity\Clients;
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
     * Avoir du client déjà utilisé en ligne, que l'imprimerie n'a pas encore
     * validé (paiements « solde client » en attente, « Factures et
     * paiements »).
     */
    public function avoirEnValidation(Clients $client): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.montant), 0)')
            ->join('p.commande', 'c')
            ->where('c.clients = :client')->andWhere('p.mode = :mode')->andWhere('p.statut = :attente')
            ->setParameter('client', $client)->setParameter('mode', Paiements::MODE_SOLDE_CLIENT)->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Liste des paiements, du plus récent au plus ancien, avec
     * recherche facultative (nom du client, code client, numéro de
     * commande, référence ou numéro de chèque) et filtres des en-têtes
     * de la liste (FiltresListePaiements : mode, statut, compte,
     * période, montant, tri).
     *
     * Sans recherche ni filtre : les $limite derniers paiements
     * uniquement (l'appelant y passe une petite limite, ex. 100). Avec
     * une recherche ou un filtre, l'appelant y passe une limite plus
     * large pour pouvoir retrouver un paiement plus ancien.
     *
     * @param array{mode?: ?string, statut?: ?string, compte?: ?int, du?: ?\DateTimeImmutable, au?: ?\DateTimeImmutable, min?: ?int, max?: ?int, tri?: string} $filtres
     *
     * @return Paiements[]
     */
    public function rechercher(string $q, int $limite, array $filtres = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.commande', 'c')
            ->addSelect('c')
            ->leftJoin('c.clients', 'cl')
            ->addSelect('cl')
            ->leftJoin('p.compteTresorerie', 'ct')
            ->addSelect('ct')
            ->setMaxResults(max(1, $limite));

        match ($filtres['tri'] ?? 'recent') {
            'ancien' => $qb->orderBy('p.date', 'ASC')->addOrderBy('p.id', 'ASC'),
            'montant_desc' => $qb->orderBy('p.montant', 'DESC')->addOrderBy('p.date', 'DESC'),
            'montant_asc' => $qb->orderBy('p.montant', 'ASC')->addOrderBy('p.date', 'DESC'),
            default => $qb->orderBy('p.date', 'DESC')->addOrderBy('p.id', 'DESC'),
        };

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

        if (null !== ($filtres['mode'] ?? null)) {
            $qb->andWhere('p.mode = :mode')->setParameter('mode', $filtres['mode']);
        }
        if (null !== ($filtres['statut'] ?? null)) {
            $qb->andWhere('p.statut = :statut')->setParameter('statut', $filtres['statut']);
        }
        if (null !== ($filtres['compte'] ?? null)) {
            $qb->andWhere('ct.id = :compte')->setParameter('compte', $filtres['compte']);
        }
        if (null !== ($filtres['du'] ?? null)) {
            $qb->andWhere('p.date >= :du')->setParameter('du', $filtres['du']->setTime(0, 0));
        }
        if (null !== ($filtres['au'] ?? null)) {
            // Jusqu'au soir du jour choisi compris.
            $qb->andWhere('p.date < :au')->setParameter('au', $filtres['au']->setTime(0, 0)->modify('+1 day'));
        }
        if (null !== ($filtres['min'] ?? null)) {
            $qb->andWhere('p.montant >= :min')->setParameter('min', $filtres['min']);
        }
        if (null !== ($filtres['max'] ?? null)) {
            $qb->andWhere('p.montant <= :max')->setParameter('max', $filtres['max']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Comptes de trésorerie qui ont reçu au moins un paiement : les choix
     * du filtre « Compte » de la liste (noms seulement, aucun solde).
     *
     * @return list<array{id: int, nom: string}>
     */
    public function comptesUtilises(): array
    {
        $lignes = $this->createQueryBuilder('p')
            ->select('DISTINCT ct.id AS id, ct.nom AS nom')
            ->join('p.compteTresorerie', 'ct')
            ->orderBy('ct.nom', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $ligne): array => ['id' => (int) $ligne['id'], 'nom' => (string) $ligne['nom']], $lignes);
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
