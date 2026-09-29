<?php

namespace App\Repository;

use App\Entity\Clients;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Clients>
 */
class ClientsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Clients::class);
    }

    /**
     * Clients ayant un solde créditeur (monnaie non rendue laissée
     * chez nous), du plus important au plus faible -- pour savoir
     * chez qui l'argent est resté.
     *
     * @return Clients[]
     */
    public function trouverAvecSoldeCredit(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.soldeCredit > 0')
            ->orderBy('c.soldeCredit', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Clients actifs, pour la sélection des destinataires d'une
     * campagne. Pas de pagination volontairement : la liste est
     * affichée en une seule fois avec une recherche cote navigateur,
     * pour que "Tout cocher" selectionne reellement tout le monde
     * sans etre limite a une page.
     *
     * @return array<int, Clients>
     */
    public function findActifsPourCampagne(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.statut = :statut')
            ->setParameter('statut', true)
            ->orderBy('c.nom', 'ASC')
            ->addOrderBy('c.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

/**
 * Recherche paginée pour DataTables.
 *
 * @return array{
 *     clients: array,
 *     total: int,
 *     filtered: int
 * }
 */
public function rechercherPourDataTable(
    int $start,
    int $length,
    string $search = ''
): array {
    // Nombre total de clients
    $total = (int) $this->createQueryBuilder('c')
        ->select('COUNT(c.id)')
        ->getQuery()
        ->getSingleScalarResult();

    // Requête avec recherche
    $qb = $this->createQueryBuilder('c');

    if ($search !== '') {
        $qb
            ->andWhere(
                $qb->expr()->orX(
                    'LOWER(c.code) LIKE LOWER(:search)',
                    'LOWER(c.nom) LIKE LOWER(:search)',
                    'LOWER(c.prenom) LIKE LOWER(:search)',
                    'LOWER(c.raisonSociale) LIKE LOWER(:search)',
                    'LOWER(c.telephone) LIKE LOWER(:search)',
                    'LOWER(c.telephone2) LIKE LOWER(:search)',
                    'LOWER(c.email) LIKE LOWER(:search)',
                    'LOWER(c.ville) LIKE LOWER(:search)',
                    'LOWER(c.nif) LIKE LOWER(:search)',
                    'LOWER(c.rccm) LIKE LOWER(:search)'
                )
            )
            ->setParameter('search', '%' . $search . '%');
    }

    // Nombre après filtrage
    $countQb = clone $qb;

    $filtered = (int) $countQb
        ->select('COUNT(c.id)')
        ->resetDQLPart('orderBy')
        ->getQuery()
        ->getSingleScalarResult();

    // Résultats de la page
    $clients = $qb
        ->orderBy('c.id', 'DESC')
        ->setFirstResult(max(0, $start))
        ->setMaxResults(max(1, $length))
        ->getQuery()
        ->getResult();

    return [
        'clients' => $clients,
        'total' => $total,
        'filtered' => $filtered,
    ];
}
public function telephoneExistePourAutreClient(
    string $telephone,
    ?int $clientId = null
): bool {
    $telephone = trim($telephone);

    if ($telephone === '') {
        return false;
    }

    $qb = $this
        ->createQueryBuilder('c')
        ->select('COUNT(c.id)')
        ->andWhere('c.telephone = :telephone')
        ->setParameter(
            'telephone',
            $telephone
        );

    /*
     * En modification :
     * on ignore le client actuellement modifié.
     */
    if ($clientId !== null) {
        $qb
            ->andWhere('c.id != :clientId')
            ->setParameter(
                'clientId',
                $clientId
            );
    }

    return (int) $qb
        ->getQuery()
        ->getSingleScalarResult() > 0;
}
    /**
     * Nombre total de clients enregistrés.
     */
    public function compterClients(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    //    /**
    //     * @return Clients[] Returns an array of Clients objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Clients
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
